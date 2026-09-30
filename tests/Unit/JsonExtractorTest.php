<?php

declare(strict_types=1);

use App\Services\Vault\Classifier\ClassifierException;
use App\Services\Vault\Classifier\JsonExtractor;

it('lee un JSON limpio', function () {
    expect(JsonExtractor::extract('{"title":"hola"}'))->toBe(['title' => 'hola']);
});

it('quita la valla de markdown', function () {
    $raw = "```json\n{\"title\":\"hola\"}\n```";

    expect(JsonExtractor::extract($raw))->toBe(['title' => 'hola']);
});

it('rescata el JSON incrustado en prosa', function () {
    $raw = "Claro, aquí tienes el análisis:\n{\"title\":\"hola\"}\nEspero que te sirva.";

    expect(JsonExtractor::extract($raw))->toBe(['title' => 'hola']);
});

it('falla con un mensaje útil si no hay JSON', function () {
    expect(fn () => JsonExtractor::extract('No puedo ayudarte con eso.'))
        ->toThrow(ClassifierException::class);
});

it('falla si el motor no devolvió nada', function () {
    expect(fn () => JsonExtractor::extract('   '))->toThrow(ClassifierException::class);
});
