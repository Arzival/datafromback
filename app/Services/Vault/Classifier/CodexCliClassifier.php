<?php

declare(strict_types=1);

namespace App\Services\Vault\Classifier;

use App\Services\Vault\PromptBuilder;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Clasifica invocando `codex exec`.
 *
 * A diferencia de claude, la imagen se adjunta con -i (no la lee del disco) y
 * el mensaje final se escribe en el archivo de -o: la salida estándar es el
 * log del agente y no sirve para parsear.
 */
class CodexCliClassifier implements Classifier
{
    public function __construct(
        private readonly PromptBuilder $prompts,
        private readonly string $bin,
        private readonly int $timeout,
    ) {}

    public function unavailableReason(): ?string
    {
        if (! is_file($this->bin)) {
            return "No se encontró el binario `codex` en {$this->bin}.";
        }

        if (! is_executable($this->bin)) {
            return "El binario `codex` en {$this->bin} no es ejecutable.";
        }

        return null;
    }

    public function classify(string $absoluteImagePath, ?string $model): ClassificationResult
    {
        if (($reason = $this->unavailableReason()) !== null) {
            throw new ClassifierException($reason);
        }

        $outputFile = sys_get_temp_dir().'/codex-vault-'.Str::random(12).'.json';

        $arguments = [
            $this->bin, 'exec',
            '-i', $absoluteImagePath,
            '--skip-git-repo-check',
            '--sandbox', 'read-only',
            '-o', $outputFile,
        ];

        if ($model !== null && $model !== '') {
            $arguments[] = '-m';
            $arguments[] = $model;
        }

        // El prompt va al final, como argumento posicional.
        $arguments[] = $this->prompts->forImage();

        $startedAt = microtime(true);

        $process = new Process(
            $arguments,
            cwd: dirname($absoluteImagePath),
            timeout: $this->timeout,
        );
        $process->setInput('');
        $process->run();

        try {
            if (! $process->isSuccessful()) {
                throw new ClassifierException(
                    'codex terminó con código '.$process->getExitCode().': '
                    .mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()), 0, 300),
                );
            }

            if (! is_file($outputFile)) {
                throw new ClassifierException('codex no escribió el archivo de salida.');
            }

            $payload = JsonExtractor::extract((string) file_get_contents($outputFile));

            return new ClassificationResult(
                title: $this->string($payload['title'] ?? null),
                summary: $this->string($payload['summary'] ?? null),
                notes: $this->string($payload['notes'] ?? null),
                tags: is_array($payload['tags'] ?? null) ? $payload['tags'] : [],
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                // Codex va contra la suscripción: no reporta coste por llamada.
                costUsd: null,
            );
        } finally {
            @unlink($outputFile);
        }
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
