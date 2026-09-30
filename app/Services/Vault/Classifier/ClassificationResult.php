<?php

declare(strict_types=1);

namespace App\Services\Vault\Classifier;

class ClassificationResult
{
    /**
     * @param  array<int, array{category?:mixed, value?:mixed, confidence?:mixed}>  $tags
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $summary,
        public readonly ?string $notes,
        public readonly array $tags,
        public readonly ?int $durationMs = null,
        public readonly ?float $costUsd = null,
    ) {}
}
