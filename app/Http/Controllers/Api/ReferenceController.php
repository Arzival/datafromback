<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReferenceRequest;
use App\Http\Resources\ReferenceResource;
use App\Jobs\ClassifyReference;
use App\Models\Reference;
use App\Services\Vault\GustoExporter;
use App\Services\Vault\ImageStore;
use App\Services\Vault\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReferenceController extends Controller
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly GustoExporter $gusto,
    ) {}

    public function index(): JsonResponse
    {
        $references = Reference::with('tags')->latest()->get();

        return response()->json(ReferenceResource::collection($references));
    }

    public function show(Reference $reference): JsonResponse
    {
        return response()->json(new ReferenceResource($reference->load('tags')));
    }

    public function store(StoreReferenceRequest $request): JsonResponse
    {
        $this->images->assertWritable();

        $reference = $this->images->store(
            $request->file('image'),
            $request->input('source_url'),
        );

        $model = $request->input('model');

        $reference->update([
            'status' => Reference::STATUS_PENDING,
            'provider_id' => $request->input('provider_id'),
            'model' => $model !== '' ? $model : null,
            'error' => null,
        ]);

        ClassifyReference::dispatch(
            $reference->id,
            $request->string('provider_id')->toString(),
            $model !== '' ? $model : null,
        );
        $this->gusto->refresh();

        return response()->json(
            new ReferenceResource($reference->load('tags')),
            Response::HTTP_CREATED,
        );
    }

    public function reclassify(Request $request, Reference $reference, ProviderRegistry $registry): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'string', 'in:'.implode(',', array_keys(config('vault.providers')))],
            'model' => ['nullable', 'string', 'max:64'],
        ]);

        if (! $registry->isAvailable($validated['provider_id'])) {
            return response()->json([
                'message' => $registry->make($validated['provider_id'])->unavailableReason(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $model = $validated['model'] ?? null;

        // Se limpia la clasificación anterior para que la UI muestre el
        // reprocesado en curso en lugar de datos viejos.
        $reference->update([
            'status' => Reference::STATUS_PENDING,
            'provider_id' => $validated['provider_id'],
            'model' => $model !== '' ? $model : null,
            'title' => null,
            'summary' => null,
            'notes' => null,
            'rejected_tags' => null,
            'error' => null,
        ]);
        $reference->tags()->detach();

        ClassifyReference::dispatch(
            $reference->id,
            $validated['provider_id'],
            $model !== '' ? $model : null,
        );

        return response()->json(new ReferenceResource($reference->load('tags')));
    }

    public function destroy(Reference $reference): Response
    {
        $this->images->forget($reference);
        $reference->delete();
        $this->gusto->refresh();

        return response()->noContent();
    }

    public function image(Reference $reference): BinaryFileResponse
    {
        return $this->serve($reference->absolutePath());
    }

    public function thumb(Reference $reference): BinaryFileResponse
    {
        return $this->serve($reference->absoluteThumbPath() ?? $reference->absolutePath());
    }

    private function serve(string $path): BinaryFileResponse
    {
        if (! is_file($path)) {
            throw new NotFoundHttpException('El archivo ya no está en la bóveda.');
        }

        return response()->file($path, ['Cache-Control' => 'public, max-age=604800']);
    }
}
