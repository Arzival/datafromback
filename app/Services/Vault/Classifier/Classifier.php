<?php

declare(strict_types=1);

namespace App\Services\Vault\Classifier;

interface Classifier
{
    /** @throws ClassifierException */
    public function classify(string $absoluteImagePath, ?string $model): ClassificationResult;

    /** null si el motor está listo; si no, el motivo para mostrar en la UI. */
    public function unavailableReason(): ?string;
}
