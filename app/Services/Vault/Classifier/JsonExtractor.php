<?php

declare(strict_types=1);

namespace App\Services\Vault\Classifier;

/**
 * Saca el objeto JSON de la respuesta de un CLI.
 *
 * En las pruebas ni `claude` ni `codex` envolvieron el JSON en prosa, pero son
 * agentes conversacionales: basta un cambio de modelo para que añadan una
 * frase de cortesía o una valla de markdown. Esto lo absorbe.
 */
class JsonExtractor
{
    /** @return array<string, mixed> */
    public static function extract(string $raw): array
    {
        $text = trim($raw);

        if ($text === '') {
            throw new ClassifierException('El motor no devolvió nada.');
        }

        // Valla de markdown: ```json … ```
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text);
            $text = trim($text);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Último recurso: el objeto más externo dentro de la prosa.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new ClassifierException(
            'La respuesta no contenía JSON válido: '.mb_substr($text, 0, 180),
        );
    }
}
