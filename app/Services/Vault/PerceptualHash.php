<?php

declare(strict_types=1);

namespace App\Services\Vault;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * dHash de las imágenes, calculado con Pillow (bin/dhash.py) para que coincida
 * bit a bit con el que calcula el homelab.
 */
class PerceptualHash
{
    /**
     * @param  array<int, string>  $paths
     * @return array<string, ?string> ruta => hash (null si no se pudo)
     */
    public function many(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $process = new Process(
            [(string) config('vault.python_bin'), base_path('bin/dhash.py'), ...$paths],
            timeout: 120,
            // Pillow está en el site-packages del usuario, que depende de HOME.
            env: ['HOME' => (string) (getenv('HOME') ?: '/Users/'.get_current_user())],
        );
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('No se pudo calcular el dHash', ['error' => $process->getErrorOutput()]);

            return array_fill_keys($paths, null);
        }

        $result = array_fill_keys($paths, null);

        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            [$path, $hash] = array_pad(explode("\t", $line, 2), 2, '');
            if (array_key_exists($path, $result) && preg_match('/^[0-9a-f]{16}$/', $hash) === 1) {
                $result[$path] = $hash;
            }
        }

        return $result;
    }

    public function one(string $path): ?string
    {
        return $this->many([$path])[$path] ?? null;
    }
}
