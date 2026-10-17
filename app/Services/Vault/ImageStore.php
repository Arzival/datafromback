<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Models\Reference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Guarda el original en el disco `vault`, genera la miniatura y deja la
 * referencia creada en estado `pending`.
 */
class ImageStore
{
    public function __construct(
        private readonly PerceptualHash $hashes,
        private readonly ImageResizer $resizer,
    ) {}

    public function store(UploadedFile $file, ?string $sourceUrl = null): Reference
    {
        $disk = Storage::disk(config('vault.disk'));
        $hash = hash_file('sha256', $file->getRealPath());

        // Misma imagen subida dos veces: se devuelve la que ya existe en
        // lugar de duplicar el archivo y la fila.
        $existing = Reference::withTrashed()->where('hash', $hash)->first();
        if ($existing !== null) {
            // Volver a subir algo que está en la papelera lo restaura.
            if ($existing->trashed()) {
                $existing->restore();
            }

            // Si esta vez sí viene fuente y la referencia no tenía, se rellena.
            // Nunca se pisa una que ya estuviera puesta.
            if ($sourceUrl !== null && $existing->source_url === null) {
                $existing->update(['source_url' => $sourceUrl]);
            }

            if ($existing->dhash === null) {
                $existing->update(['dhash' => $this->hashes->one($existing->absolutePath())]);
            }

            return $existing;
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = "images/{$hash}.{$extension}";

        $disk->put($path, file_get_contents($file->getRealPath()));

        [$width, $height] = $this->dimensions($disk->path($path));
        $thumbPath = $this->makeThumbnail($disk->path($path), $hash);

        return Reference::create([
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'thumb_path' => $thumbPath,
            'hash' => $hash,
            'dhash' => $this->hashes->one($disk->path($path)),
            'mime' => $file->getMimeType() ?? 'image/png',
            'bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'status' => Reference::STATUS_PENDING,
            'source_url' => $sourceUrl,
        ]);
    }

    /** @return array{0:int,1:int} */
    private function dimensions(string $absolutePath): array
    {
        $info = @getimagesize($absolutePath);

        return $info === false ? [0, 0] : [(int) $info[0], (int) $info[1]];
    }

    /**
     * Miniatura en WebP. Devuelve null si no se pudo: el catálogo sigue
     * funcionando sirviendo el original.
     */
    private function makeThumbnail(string $absolutePath, string $hash): ?string
    {
        $disk = Storage::disk(config('vault.disk'));
        $relative = "thumbs/{$hash}.webp";
        $disk->makeDirectory('thumbs');

        $ok = $this->resizer->toWidth(
            $absolutePath,
            $disk->path($relative),
            (int) config('vault.thumbnail.width'),
            (int) config('vault.thumbnail.quality'),
        );

        return $ok ? $relative : null;
    }

    /**
     * Ruta de la imagen que se le pasa a la IA: el original si cabe en los
     * límites de visión y, si no, una copia reducida que se genera una vez y
     * se reutiliza en las reclasificaciones.
     */
    public function visionPath(Reference $reference): string
    {
        $original = $reference->absolutePath();
        $limits = config('vault.vision');

        $fits = max((int) $reference->width, (int) $reference->height) <= $limits['max_side']
            && (int) $reference->bytes <= $limits['max_bytes'];

        if ($fits || ! is_file($original)) {
            return $original;
        }

        $disk = Storage::disk(config('vault.disk'));
        $relative = $this->visionRelative($reference);

        if (! $disk->exists($relative)) {
            $disk->makeDirectory('vision');
            $made = $this->resizer->toLongSide(
                $original,
                $disk->path($relative),
                (int) $limits['max_side'],
                (int) $limits['quality'],
            );

            if (! $made) {
                return $original;
            }
        }

        return $disk->path($relative);
    }

    private function visionRelative(Reference $reference): string
    {
        return "vision/{$reference->hash}.jpg";
    }

    /** Borra los archivos de una referencia. */
    public function forget(Reference $reference): void
    {
        $disk = Storage::disk(config('vault.disk'));
        $disk->delete(array_filter([$reference->path, $reference->thumb_path, $this->visionRelative($reference)]));
    }

    public function assertWritable(): void
    {
        $disk = Storage::disk(config('vault.disk'));
        $disk->makeDirectory('images');
        $disk->makeDirectory('thumbs');

        if (! is_writable($disk->path('images'))) {
            throw new RuntimeException('El directorio de la bóveda no es escribible.');
        }
    }
}
