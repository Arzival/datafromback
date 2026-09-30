<?php

declare(strict_types=1);

namespace App\Services\Vault;

/**
 * Arma el prompt de clasificación. Inyecta el vocabulario controlado para que
 * el modelo elija de una lista cerrada en vez de inventar sinónimos: es más
 * barato corregirlo aquí que normalizarlo después.
 */
class PromptBuilder
{
    public function forImage(?string $absolutePath = null): string
    {
        $vocabulary = config('vault.vocabulary');
        $limits = config('vault.limits');

        $lines = [
            'Analiza la captura de interfaz adjunta.',
            '',
            'Devuelve ÚNICAMENTE un objeto JSON válido. Sin markdown, sin ```json,',
            'sin explicaciones, sin texto antes ni después. Esta forma exacta:',
            '',
            '{',
            '  "title": "título corto en español, máximo 70 caracteres",',
            '  "summary": "2 o 3 frases describiendo la pantalla y su jerarquía visual",',
            '  "notes": "notas accionables para reproducir el diseño: medidas, radios, espaciados, comportamiento",',
            '  "tags": [',
            '    {"category": "screen_type", "value": "dashboard", "confidence": 0.95}',
            '  ]',
            '}',
            '',
            'Los valores de cada categoría SOLO pueden salir de estas listas.',
            'Si ninguno encaja, omite la categoría en lugar de inventar un valor.',
            '',
        ];

        foreach ($vocabulary as $category => $values) {
            $limit = $limits[$category];
            $range = $limit['min'] === $limit['max']
                ? "exactamente {$limit['min']}"
                : "de {$limit['min']} a {$limit['max']}";

            $lines[] = "{$category} ({$range}): ".implode(', ', $values);
            $lines[] = '';
        }

        $palette = $limits['palette'];
        $lines[] = "palette (de {$palette['min']} a {$palette['max']}): no tiene lista. Cada valor es un color";
        $lines[] = 'hex en formato #rrggbb tomado directamente de la imagen.';
        $lines[] = '';
        $lines[] = 'confidence va entre 0 y 1, y debe reflejar lo seguro que estés de verdad:';
        $lines[] = 'usa valores bajos para lo que deduces y altos solo para lo que ves claro.';
        $lines[] = 'No etiquetes nada que no aparezca en la imagen.';

        if ($absolutePath !== null) {
            $lines[] = '';
            $lines[] = "La imagen está en: {$absolutePath}";
            $lines[] = 'Ábrela con la herramienta Read antes de responder.';
        }

        return implode("\n", $lines);
    }
}
