<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Models\Reference;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mantiene `gusto/boveda.json` en la carpeta sincronizada con el homelab.
 *
 * El homelab lo lee para su filtro de gusto: no mandar estilos que Armando ya
 * rechazó sin haber aceptado ninguno parecido, ni casi copias de lo que ya
 * está en la bóveda. Solo viajan etiquetas y el dHash, nunca imágenes.
 *
 * «Aceptado» = está en la bóveda. Lo que se borra desaparece de la lista.
 */
class GustoExporter
{
    /** Claves del JSON => categoría de la taxonomía. */
    private const CATEGORIES = [
        'tipo' => 'screen_type',
        'estilo' => 'style',
        'layout' => 'layout',
        'sector' => 'industry',
        'densidad' => 'density',
        'componentes' => 'components',
    ];

    /** Las que tienen un único valor por referencia van como texto, no lista. */
    private const SINGLE = ['tipo', 'sector', 'densidad'];

    public function __construct(
        private readonly Inbox $inbox,
        private readonly PerceptualHash $hashes,
    ) {}

    /**
     * Regenera el archivo. Nunca lanza: un fallo aquí no puede tumbar una
     * subida ni una clasificación.
     */
    public function refresh(): void
    {
        try {
            $this->write();
        } catch (Throwable $e) {
            Log::warning('No se pudo exportar boveda.json', ['error' => $e->getMessage()]);
        }
    }

    public function path(): string
    {
        return $this->inbox->path().'/'.config('vault.gusto_export');
    }

    /** Calcula el dHash de las referencias que aún no lo tienen. */
    public function backfillHashes(): int
    {
        $missing = Reference::whereNull('dhash')->get()
            ->filter(fn (Reference $reference) => is_file($reference->absolutePath()));

        $hashes = $this->hashes->many($missing->map->absolutePath()->values()->all());

        $filled = 0;
        foreach ($missing as $reference) {
            $hash = $hashes[$reference->absolutePath()] ?? null;
            if ($hash !== null) {
                $reference->update(['dhash' => $hash]);
                $filled++;
            }
        }

        return $filled;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $vocabulary = config('vault.vocabulary');

        $items = Reference::with('tags')->orderBy('id')->get()->map(function (Reference $reference) {
            $item = [
                'id' => (string) $reference->id,
                'hash' => $this->inboxHash($reference->filename),
            ];

            foreach (self::CATEGORIES as $key => $category) {
                $values = $reference->tags->where('category', $category)->pluck('value')->values()->all();
                $item[$key] = in_array($key, self::SINGLE, true) ? ($values[0] ?? null) : $values;
            }

            $item['dhash'] = $reference->dhash;

            return $item;
        })->values()->all();

        $vocabularyOut = [];
        foreach (self::CATEGORIES as $key => $category) {
            $vocabularyOut[$key] = array_values($vocabulary[$category] ?? []);
        }

        return [
            'actualizado' => now()->toIso8601String(),
            'vocabulario' => $vocabularyOut,
            // Sinónimos que la Mac traduce antes de guardar, por si el homelab
            // quiere normalizar sus propias etiquetas igual.
            'alias' => config('vault.aliases'),
            'items' => $items,
        ];
    }

    private function write(): void
    {
        if (! $this->inbox->exists()) {
            return;
        }

        $target = $this->path();
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("No se pudo crear {$directory}");
        }

        $json = json_encode(
            $this->build(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        // Escritura atómica: el homelab nunca lee un JSON a medias. El temporal
        // usa el patrón .syncthing.*.tmp, que Syncthing nunca sincroniza.
        $temporary = $directory.'/.syncthing.boveda.json.tmp';
        file_put_contents($temporary, $json."\n");
        rename($temporary, $target);
    }

    /** Los 12 hex del nombre si la referencia vino de la bandeja. */
    private function inboxHash(string $filename): ?string
    {
        return preg_match('/^\d{8}-\d{6}__([a-z0-9]{4,64})__/i', $filename, $m) === 1 ? $m[1] : null;
    }
}
