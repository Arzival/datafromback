<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reference;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Vuelca el catálogo a disco para que un agente (Claude Code, Codex) pueda
 * leer la bóveda sin pasar por la API ni por la UI.
 */
class VaultExport extends Command
{
    protected $signature = 'vault:export';

    protected $description = 'Genera CATALOG.md y catalog.json con todas las referencias clasificadas';

    public function handle(): int
    {
        $disk = Storage::disk(config('vault.disk'));
        $references = Reference::with('tags')->latest()->get();

        $json = $references->map(fn (Reference $reference) => [
            'id' => $reference->id,
            'title' => $reference->title,
            'filename' => $reference->filename,
            'path' => $reference->absolutePath(),
            'status' => $reference->status,
            'provider' => $reference->provider_id,
            'model' => $reference->model,
            'summary' => $reference->summary,
            'notes' => $reference->notes,
            'sourceUrl' => $reference->source_url,
            'tags' => $reference->tags->map(fn ($tag) => [
                'category' => $tag->category,
                'value' => $tag->value,
                'confidence' => $tag->pivot->confidence !== null ? (float) $tag->pivot->confidence : null,
            ])->values(),
        ])->values();

        $disk->put('catalog.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $disk->put('CATALOG.md', $this->markdown($references));

        $this->info("Exportadas {$references->count()} referencias a ".$disk->path(''));
        $this->line('  '.$disk->path('CATALOG.md'));
        $this->line('  '.$disk->path('catalog.json'));

        return self::SUCCESS;
    }

    private function markdown(Collection $references): string
    {
        $ready = $references->where('status', Reference::STATUS_READY);

        $lines = [
            '# Catálogo de referencias de UI',
            '',
            'Generado por `php artisan vault:export` el '.now()->toDateTimeString().'.',
            "Referencias clasificadas: {$ready->count()} de {$references->count()}.",
            '',
            'Cada entrada incluye la ruta absoluta de la captura: ábrela antes de',
            'usarla como referencia, la descripción es un apoyo y no un sustituto.',
            '',
        ];

        // Índice por categoría, para poder buscar sin leer el archivo entero.
        $byTag = [];
        foreach ($ready as $reference) {
            foreach ($reference->tags as $tag) {
                $byTag[$tag->category][$tag->value][] = $reference->id;
            }
        }

        if ($byTag !== []) {
            $lines[] = '## Índice por etiqueta';
            $lines[] = '';
            foreach ($byTag as $category => $values) {
                ksort($values);
                $parts = [];
                foreach ($values as $value => $ids) {
                    $parts[] = $value.' ('.implode(', ', array_map(fn ($id) => "#{$id}", $ids)).')';
                }
                $lines[] = "- **{$category}:** ".implode(' · ', $parts);
            }
            $lines[] = '';
        }

        $lines[] = '## Referencias';

        foreach ($ready as $reference) {
            $lines[] = '';
            $lines[] = "### #{$reference->id} — ".($reference->title ?? $reference->filename);
            $lines[] = '';
            $lines[] = "- **Imagen:** `{$reference->absolutePath()}`";
            $lines[] = "- **Clasificada por:** {$reference->provider_id}".($reference->model ? " ({$reference->model})" : '');

            if ($reference->source_url) {
                $lines[] = "- **Fuente:** {$reference->source_url}";
            }

            foreach ($reference->tags->groupBy('category') as $category => $tags) {
                $lines[] = "- **{$category}:** ".$tags->pluck('value')->implode(', ');
            }

            if ($reference->summary) {
                $lines[] = '';
                $lines[] = $reference->summary;
            }

            if ($reference->notes) {
                $lines[] = '';
                $lines[] = "**Notas de implementación:** {$reference->notes}";
            }
        }

        return implode("\n", $lines)."\n";
    }
}
