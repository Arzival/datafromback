<?php

declare(strict_types=1);

namespace App\Services\Vault;

use Illuminate\Support\Str;

/**
 * Traduce lo que devuelve la IA al vocabulario controlado.
 *
 * Existe porque distintos motores etiquetan la misma pantalla con sinónimos
 * ("oscuro" vs "modo oscuro", "salud y fitness" vs "salud y bienestar"), y el
 * catálogo filtra por valor exacto. Sin esto los filtros se fragmentan.
 */
class TaxonomyNormalizer
{
    /**
     * @param  array<int, array{category?:mixed, value?:mixed, confidence?:mixed}>  $rawTags
     * @return array{accepted: array<int, array{category:string, value:string, confidence:float|null}>, rejected: array<int, array{category:string, value:string, reason:string}>}
     */
    public function normalize(array $rawTags): array
    {
        $vocabulary = config('vault.vocabulary');
        $limits = config('vault.limits');

        $accepted = [];
        $rejected = [];
        $seen = [];
        $counts = [];

        foreach ($rawTags as $tag) {
            $category = is_string($tag['category'] ?? null) ? trim($tag['category']) : '';
            $value = is_string($tag['value'] ?? null) ? trim($tag['value']) : '';

            if ($category === '' || $value === '') {
                continue;
            }

            if (! array_key_exists($category, $limits)) {
                $rejected[] = ['category' => $category, 'value' => $value, 'reason' => 'categoría desconocida'];

                continue;
            }

            $resolved = $category === 'palette'
                ? $this->normalizeColor($value)
                : $this->resolveValue($category, $value, $vocabulary);

            if ($resolved === null) {
                $rejected[] = [
                    'category' => $category,
                    'value' => $value,
                    'reason' => $category === 'palette' ? 'no es un color hex' : 'fuera del vocabulario',
                ];

                continue;
            }

            // Sin repetidos dentro de la misma referencia.
            $key = "{$category}:{$resolved}";
            if (isset($seen[$key])) {
                continue;
            }

            // Un alias puede hacer que se supere el máximo de la categoría.
            $counts[$category] = ($counts[$category] ?? 0) + 1;
            if ($counts[$category] > $limits[$category]['max']) {
                $rejected[] = [
                    'category' => $category,
                    'value' => $value,
                    'reason' => "supera el máximo de {$limits[$category]['max']}",
                ];

                continue;
            }

            $seen[$key] = true;
            $accepted[] = [
                'category' => $category,
                'value' => $resolved,
                'confidence' => $this->normalizeConfidence($tag['confidence'] ?? null),
            ];
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * @param  array<string, array<int, string>>  $vocabulary
     */
    private function resolveValue(string $category, string $value, array $vocabulary): ?string
    {
        $allowed = $vocabulary[$category] ?? [];
        $needle = $this->fold($value);

        // Coincidencia exacta, ignorando mayúsculas y acentos.
        foreach ($allowed as $candidate) {
            if ($this->fold($candidate) === $needle) {
                return $candidate;
            }
        }

        // Sinónimo conocido, siempre que apunte a un valor de esta categoría.
        foreach (config('vault.aliases') as $alias => $target) {
            if ($this->fold($alias) !== $needle) {
                continue;
            }

            foreach ($allowed as $candidate) {
                if ($this->fold($candidate) === $this->fold($target)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function normalizeColor(string $value): ?string
    {
        $value = strtolower(trim($value));

        // #abc → #aabbcc
        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m) === 1) {
            [$r, $g, $b] = str_split($m[1]);

            return "#{$r}{$r}{$g}{$g}{$b}{$b}";
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    private function normalizeConfidence(mixed $raw): ?float
    {
        if (! is_numeric($raw)) {
            return null;
        }

        return round(max(0.0, min(1.0, (float) $raw)), 3);
    }

    /**
     * Minúsculas sin acentos, para comparar "EDUCACION" con "educación".
     *
     * Usa Str::ascii y no iconv: en macOS `ASCII//TRANSLIT` no transcribe los
     * acentos (devuelve "educaci?n") y la comparación fallaba en silencio.
     */
    private function fold(string $value): string
    {
        return Str::ascii(mb_strtolower(trim($value)));
    }
}
