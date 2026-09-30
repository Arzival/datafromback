<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Services\Vault\Classifier\Classifier;
use App\Services\Vault\Classifier\ClassifierException;
use App\Services\Vault\Classifier\ClaudeCliClassifier;
use App\Services\Vault\Classifier\CodexCliClassifier;

/**
 * Sabe qué motores existen y si se pueden usar ahora mismo. La disponibilidad
 * se comprueba en caliente (el binario puede desaparecer entre peticiones), no
 * se cachea.
 */
class ProviderRegistry
{
    public function __construct(private readonly PromptBuilder $prompts) {}

    public function make(string $providerId): Classifier
    {
        $config = config("vault.providers.{$providerId}");

        if ($config === null) {
            throw new ClassifierException("El proveedor «{$providerId}» no existe.");
        }

        $timeout = (int) config('vault.classifier_timeout');

        return match ($providerId) {
            'claude-cli' => new ClaudeCliClassifier($this->prompts, $config['bin'], $timeout),
            'codex-cli' => new CodexCliClassifier($this->prompts, $config['bin'], $timeout),
            default => throw new ClassifierException("El proveedor «{$providerId}» no tiene implementación."),
        };
    }

    /** Forma que consume el frontend (src/types/vault.ts → Provider). */
    public function toArray(): array
    {
        $providers = [];

        foreach (config('vault.providers') as $id => $config) {
            $reason = $this->make($id)->unavailableReason();

            $providers[] = [
                'id' => $id,
                'label' => $config['label'],
                'kind' => $config['kind'],
                'description' => $config['description'],
                'available' => $reason === null,
                'unavailableReason' => $reason,
                'models' => $config['models'],
                'defaultModel' => $config['default_model'],
                'allowCustomModel' => $config['allow_custom_model'],
            ];
        }

        return $providers;
    }

    public function isAvailable(string $providerId): bool
    {
        if (config("vault.providers.{$providerId}") === null) {
            return false;
        }

        return $this->make($providerId)->unavailableReason() === null;
    }
}
