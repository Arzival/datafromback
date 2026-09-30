<?php

declare(strict_types=1);

namespace App\Services\Vault\Classifier;

use App\Services\Vault\PromptBuilder;
use Symfony\Component\Process\Process;

/**
 * Clasifica invocando el binario `claude` en modo headless.
 *
 * Detalles medidos en el smoke test del 2026-09-29 (ver
 * datafront/.rules/implementation/clasificacion-ia.md):
 *  - hay que pasarle stdin vacío o espera 3 s y avisa por stderr
 *  - la respuesta útil está en el campo `result` del envoltorio JSON
 *  - necesita la herramienta Read para abrir la imagen del disco
 */
class ClaudeCliClassifier implements Classifier
{
    public function __construct(
        private readonly PromptBuilder $prompts,
        private readonly string $bin,
        private readonly int $timeout,
    ) {}

    public function unavailableReason(): ?string
    {
        if (! is_file($this->bin)) {
            return "No se encontró el binario `claude` en {$this->bin}.";
        }

        if (! is_executable($this->bin)) {
            return "El binario `claude` en {$this->bin} no es ejecutable.";
        }

        return null;
    }

    public function classify(string $absoluteImagePath, ?string $model): ClassificationResult
    {
        if (($reason = $this->unavailableReason()) !== null) {
            throw new ClassifierException($reason);
        }

        $arguments = [
            $this->bin,
            '-p', $this->prompts->forImage($absoluteImagePath),
            '--output-format', 'json',
            // Solo lectura: no queremos que el clasificador toque nada.
            '--allowedTools', 'Read',
        ];

        if ($model !== null && $model !== '') {
            $arguments[] = '--model';
            $arguments[] = $model;
        }

        $process = new Process(
            $arguments,
            // Se ejecuta dentro del directorio de la imagen para que Read la
            // alcance sin necesidad de --add-dir.
            cwd: dirname($absoluteImagePath),
            timeout: $this->timeout,
        );
        // stdin explícitamente vacío: sin esto espera datos por tubería.
        $process->setInput('');
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ClassifierException(
                'claude terminó con código '.$process->getExitCode().': '
                .mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()), 0, 300),
            );
        }

        $envelope = JsonExtractor::extract($process->getOutput());

        if (($envelope['is_error'] ?? false) === true) {
            throw new ClassifierException(
                'claude devolvió un error: '.mb_substr((string) ($envelope['result'] ?? ''), 0, 300),
            );
        }

        $payload = JsonExtractor::extract((string) ($envelope['result'] ?? ''));

        return new ClassificationResult(
            title: $this->string($payload['title'] ?? null),
            summary: $this->string($payload['summary'] ?? null),
            notes: $this->string($payload['notes'] ?? null),
            tags: is_array($payload['tags'] ?? null) ? $payload['tags'] : [],
            durationMs: isset($envelope['duration_ms']) ? (int) $envelope['duration_ms'] : null,
            costUsd: isset($envelope['total_cost_usd']) ? (float) $envelope['total_cost_usd'] : null,
        );
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
