<?php

declare(strict_types=1);

use App\Services\Vault\TaxonomyNormalizer;

beforeEach(function () {
    $this->normalizer = new TaxonomyNormalizer;
});

it('acepta valores que ya están en el vocabulario', function () {
    $result = $this->normalizer->normalize([
        ['category' => 'screen_type', 'value' => 'dashboard', 'confidence' => 0.9],
    ]);

    expect($result['accepted'])->toHaveCount(1)
        ->and($result['accepted'][0]['value'])->toBe('dashboard')
        ->and($result['rejected'])->toBeEmpty();
});

it('traduce los sinónimos que devuelven los distintos motores', function () {
    // El problema real medido: claude dice "oscuro", codex "modo oscuro".
    $result = $this->normalizer->normalize([
        ['category' => 'style', 'value' => 'modo oscuro', 'confidence' => 0.99],
        ['category' => 'industry', 'value' => 'salud y bienestar', 'confidence' => 0.9],
        ['category' => 'components', 'value' => 'botón cta', 'confidence' => 0.8],
    ]);

    expect(collect($result['accepted'])->pluck('value')->all())
        ->toBe(['oscuro', 'salud', 'botón principal']);
});

it('ignora mayúsculas y acentos al comparar', function () {
    $result = $this->normalizer->normalize([
        ['category' => 'industry', 'value' => 'EDUCACION', 'confidence' => 0.7],
    ]);

    expect($result['accepted'][0]['value'])->toBe('educación');
});

it('rechaza lo que no está en el vocabulario y explica por qué', function () {
    $result = $this->normalizer->normalize([
        ['category' => 'style', 'value' => 'vaporwave cyberpunk', 'confidence' => 0.6],
    ]);

    expect($result['accepted'])->toBeEmpty()
        ->and($result['rejected'][0]['reason'])->toBe('fuera del vocabulario');
});

it('rechaza una categoría inventada', function () {
    $result = $this->normalizer->normalize([
        ['category' => 'vibra', 'value' => 'chill', 'confidence' => 1],
    ]);

    expect($result['rejected'][0]['reason'])->toBe('categoría desconocida');
});

it('normaliza colores hex y descarta lo que no lo es', function () {
    $result = $this->normalizer->normalize([
        ['category' => 'palette', 'value' => '#ABC', 'confidence' => 0.9],
        ['category' => 'palette', 'value' => '#0b1120', 'confidence' => 0.9],
        ['category' => 'palette', 'value' => 'azul oscuro', 'confidence' => 0.9],
    ]);

    expect(collect($result['accepted'])->pluck('value')->all())
        ->toBe(['#aabbcc', '#0b1120'])
        ->and($result['rejected'][0]['reason'])->toBe('no es un color hex');
});

it('respeta el máximo de valores por categoría', function () {
    $tags = [];
    for ($i = 0; $i < 5; $i++) {
        $tags[] = ['category' => 'style', 'value' => ['oscuro', 'claro', 'retro', 'lujo', '3d'][$i], 'confidence' => 0.8];
    }

    $result = $this->normalizer->normalize($tags);

    // style permite 3 como máximo.
    expect($result['accepted'])->toHaveCount(3)
        ->and($result['rejected'])->toHaveCount(2)
        ->and($result['rejected'][0]['reason'])->toContain('supera el máximo');
});

it('no duplica un valor al que llegan dos sinónimos', function () {
    $result = $this->normalizer->normalize([
        ['category' => 'style', 'value' => 'oscuro', 'confidence' => 0.9],
        ['category' => 'style', 'value' => 'modo oscuro', 'confidence' => 0.8],
    ]);

    expect($result['accepted'])->toHaveCount(1);
});

it('acota la confianza al rango 0-1', function () {
    $result = $this->normalizer->normalize([
        ['category' => 'density', 'value' => 'alta', 'confidence' => 4.2],
    ]);

    expect($result['accepted'][0]['confidence'])->toBe(1.0);
});
