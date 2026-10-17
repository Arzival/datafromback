<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenceResource;
use App\Jobs\ClassifyReference;
use App\Models\Reference;
use App\Services\Vault\GustoExporter;
use App\Services\Vault\Inbox;
use App\Services\Vault\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InboxController extends Controller
{
    public function __construct(private readonly Inbox $inbox) {}

    /** Estado de la carpeta y capturas pendientes de decisión. */
    public function index(): JsonResponse
    {
        return response()->json([
            'path' => $this->inbox->path(),
            'exists' => $this->inbox->exists(),
            'rejectReasons' => collect(config('vault.inbox.reject_reasons'))
                ->map(fn (string $label, string $id) => ['id' => $id, 'label' => $label])
                ->values(),
            'items' => $this->inbox->pending(),
        ]);
    }

    /** Vista previa sin importar: se sirve el archivo tal cual está. */
    public function preview(string $filename): BinaryFileResponse
    {
        $path = $this->inbox->path().'/'.basename($filename);

        if (! $this->inbox->exists() || ! is_file($path)) {
            throw new NotFoundHttpException('Esa captura ya no está en la bandeja.');
        }

        return response()->file($path, ['Cache-Control' => 'private, max-age=60']);
    }

    public function import(Request $request, ProviderRegistry $registry): JsonResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'provider_id' => ['required', 'string', 'in:'.implode(',', array_keys(config('vault.providers')))],
            'model' => ['nullable', 'string', 'max:64'],
            'source_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if (! $registry->isAvailable($validated['provider_id'])) {
            return response()->json([
                'message' => $registry->make($validated['provider_id'])->unavailableReason(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $reference = $this->inbox->import(
                $validated['filename'],
                $validated['source_url'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $model = ($validated['model'] ?? '') !== '' ? $validated['model'] : null;

        $reference->update([
            'status' => Reference::STATUS_PENDING,
            'provider_id' => $validated['provider_id'],
            'model' => $model,
            'error' => null,
        ]);

        ClassifyReference::dispatch($reference->id, $validated['provider_id'], $model);
        app(GustoExporter::class)->refresh();

        return response()->json(
            new ReferenceResource($reference->load('tags')),
            Response::HTTP_CREATED,
        );
    }

    public function reject(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'in:'.implode(',', array_keys(config('vault.inbox.reject_reasons')))],
        ]);

        try {
            $renamed = $this->inbox->reject($validated['filename'], $validated['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['filename' => $renamed]);
    }
}
