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
    public function __construct(private readonly PerceptualHash $hashes) {}

    public function store(UploadedFile $file, ?string $sourceUrl = null): Reference
    {
        $disk = Storage::disk(config('vault.disk'));
        $hash = hash_file('sha256', $file->getRealPath());

        // Misma imagen subida dos veces: se devuelve la que ya existe en
        // lugar de duplicar el archivo y la fila.
        $existing = Reference::where('hash', $hash)->first();
        if ($existing !== null) {
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
     * Miniatura en WebP. Devuelve null si GD no puede con el formato: el
     * catálogo sigue funcionando sirviendo el original.
     */
    private function makeThumbnail(string $absolutePath, string $hash): ?string
    {
        $contents = @file_get_contents($absolutePath);
        if ($contents === false) {
            return null;
        }

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return null;
        }

        try {
            $targetWidth = (int) config('vault.thumbnail.width');
            $width = imagesx($source);

            $resized = $width > $targetWidth
                ? imagescale($source, $targetWidth)
                : $source;

            if ($resized === false) {
                return null;
            }

            $disk = Storage::disk(config('vault.disk'));
            $relative = "thumbs/{$hash}.webp";
            $disk->makeDirectory('thumbs');

            $ok = imagewebp(
                $resized,
                $disk->path($relative),
                (int) config('vault.thumbnail.quality'),
            );

            if ($resized !== $source) {
                imagedestroy($resized);
            }

            return $ok ? $relative : null;
        } finally {
            imagedestroy($source);
        }
    }

    /** Borra los archivos de una referencia. */
    public function forget(Reference $reference): void
    {
        $disk = Storage::disk(config('vault.disk'));
        $disk->delete(array_filter([$reference->path, $reference->thumb_path]));
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
