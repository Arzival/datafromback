<?php

declare(strict_types=1);

namespace App\Services\Vault;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Redimensiona con Pillow (bin/resize.py). GD decodifica la imagen entera en
 * la memoria de PHP: una captura de 7416×5571 son ~165 MB y tumba la petición.
 */
class ImageResizer
{
    /** Limita el ancho. */
    public function toWidth(string $source, string $target, int $width, int $quality): bool
    {
        return $this->run($source, $target, 'ancho', $width, $quality);
    }

    /** Limita el lado largo. */
    public function toLongSide(string $source, string $target, int $side, int $quality): bool
    {
        return $this->run($source, $target, 'lado', $side, $quality);
    }

    private function run(string $source, string $target, string $mode, int $limit, int $quality): bool
    {
        $process = new Process(
            [(string) config('vault.python_bin'), base_path('bin/resize.py'), $source, $target, $mode, (string) $limit, (string) $quality],
            timeout: 120,
            // Pillow está en el site-packages del usuario, que depende de HOME.
            env: ['HOME' => (string) (getenv('HOME') ?: '/Users/'.get_current_user())],
        );
        $process->run();

        if (! $process->isSuccessful() || ! is_file($target)) {
            Log::warning('No se pudo redimensionar la imagen', [
                'source' => $source,
                'error' => $process->getErrorOutput(),
            ]);

            return false;
        }

        return true;
    }
}
