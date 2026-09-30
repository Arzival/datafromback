<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reference;
use Illuminate\Console\Command;

/**
 * Lista los valores que la IA propuso y el vocabulario controlado rechazó.
 *
 * Es el bucle de mejora: si un valor aparece muchas veces, probablemente
 * merece entrar en `config/vault.php` como valor válido o como alias.
 */
class VaultVocabGaps extends Command
{
    protected $signature = 'vault:vocab-gaps {--min=1 : Mínimo de apariciones para listarlo}';

    protected $description = 'Muestra los valores rechazados por el vocabulario controlado';

    public function handle(): int
    {
        $min = (int) $this->option('min');
        $counts = [];

        foreach (Reference::whereNotNull('rejected_tags')->get() as $reference) {
            foreach ($reference->rejected_tags ?? [] as $rejected) {
                $key = ($rejected['category'] ?? '?').'|'.($rejected['value'] ?? '?').'|'.($rejected['reason'] ?? '?');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            $this->info('No hay valores rechazados: el vocabulario cubre todo lo que ha llegado.');

            return self::SUCCESS;
        }

        arsort($counts);

        $rows = [];
        foreach ($counts as $key => $count) {
            if ($count < $min) {
                continue;
            }
            [$category, $value, $reason] = explode('|', $key);
            $rows[] = [$count, $category, $value, $reason];
        }

        if ($rows === []) {
            $this->info("Ningún valor alcanza las {$min} apariciones.");

            return self::SUCCESS;
        }

        $this->table(['Veces', 'Categoría', 'Valor propuesto', 'Motivo'], $rows);
        $this->newLine();
        $this->line('Para aceptar uno, añádelo a config/vault.php:');
        $this->line('  · como valor nuevo en `vocabulary`, o');
        $this->line('  · como sinónimo en `aliases` si ya existe un equivalente.');

        return self::SUCCESS;
    }
}
