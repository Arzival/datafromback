<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vault\GustoExporter;
use Illuminate\Console\Command;

/**
 * Regenera gusto/boveda.json a mano. Normalmente no hace falta: se actualiza
 * solo al importar, clasificar y borrar.
 */
class VaultExportGusto extends Command
{
    protected $signature = 'vault:export-gusto {--backfill : Calcula antes el dHash que falte}';

    protected $description = 'Regenera la exportación de la bóveda para el filtro de gusto del homelab';

    public function handle(GustoExporter $exporter): int
    {
        if ($this->option('backfill')) {
            $this->info('dHash calculados: '.$exporter->backfillHashes());
        }

        $exporter->refresh();

        if (! is_file($exporter->path())) {
            $this->error('No se generó. ¿Existe la carpeta de la bandeja? '.$exporter->path());

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($exporter->path()), true);
        $this->info(count($data['items'] ?? []).' referencias exportadas a '.$exporter->path());

        return self::SUCCESS;
    }
}
