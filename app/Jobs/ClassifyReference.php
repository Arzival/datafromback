<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Reference;
use App\Models\Tag;
use App\Services\Vault\Classifier\ClassifierException;
use App\Services\Vault\GustoExporter;
use App\Services\Vault\ProviderRegistry;
use App\Services\Vault\TaxonomyNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassifyReference implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Margen sobre el timeout del CLI para que el job no muera antes. */
    public int $timeout = 240;

    public function __construct(
        public readonly int $referenceId,
        public readonly string $providerId,
        public readonly ?string $model,
    ) {}

    public function handle(ProviderRegistry $registry, TaxonomyNormalizer $normalizer): void
    {
        $reference = Reference::find($this->referenceId);

        if ($reference === null) {
            return; // Se borró mientras esperaba en la cola.
        }

        $reference->update([
            'status' => Reference::STATUS_PROCESSING,
            'provider_id' => $this->providerId,
            'model' => $this->model,
            'error' => null,
        ]);

        try {
            $result = $registry->make($this->providerId)
                ->classify($reference->absolutePath(), $this->model);

            $normalized = $normalizer->normalize($result->tags);

            $reference->update([
                'status' => Reference::STATUS_READY,
                'title' => $result->title,
                'summary' => $result->summary,
                'notes' => $result->notes,
                'rejected_tags' => $normalized['rejected'] ?: null,
                'duration_ms' => $result->durationMs,
                'cost_usd' => $result->costUsd,
                'error' => null,
            ]);

            $this->syncTags($reference, $normalized['accepted']);
            app(GustoExporter::class)->refresh();
        } catch (ClassifierException $e) {
            $this->markFailed($reference, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Fallo inesperado clasificando la referencia', [
                'reference' => $reference->id,
                'provider' => $this->providerId,
                'exception' => $e,
            ]);

            $this->markFailed($reference, 'Error inesperado: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int, array{category:string, value:string, confidence:float|null}>  $tags
     */
    private function syncTags(Reference $reference, array $tags): void
    {
        $attach = [];

        foreach ($tags as $tag) {
            $model = Tag::firstOrCreate(
                ['category' => $tag['category'], 'value' => $tag['value']],
            );

            $attach[$model->id] = ['confidence' => $tag['confidence']];
        }

        $reference->tags()->sync($attach);
    }

    private function markFailed(Reference $reference, string $message): void
    {
        $reference->update([
            'status' => Reference::STATUS_FAILED,
            'error' => mb_substr($message, 0, 500),
        ]);
        app(GustoExporter::class)->refresh();
    }

    /** Si se agotan los reintentos, la referencia no se queda en «procesando». */
    public function failed(?Throwable $exception): void
    {
        $reference = Reference::find($this->referenceId);

        $reference?->update([
            'status' => Reference::STATUS_FAILED,
            'error' => mb_substr(
                $exception?->getMessage() ?? 'El trabajo de clasificación falló.',
                0,
                500,
            ),
        ]);
    }
}
