<?php

namespace App\Console\Commands\Backups;

use App\Services\Backups\BackupDiscoveryService;
use Illuminate\Console\Command;

class ScanBackups extends Command
{
    protected $signature = 'backup:varrer';

    protected $description = 'Cataloga no painel todos os backups presentes no disco, inclusive os gerados fora do sistema.';

    public function handle(BackupDiscoveryService $discovery): int
    {
        $result = $discovery->scan();

        $this->info(sprintf(
            '%d catalogado(s), %d atualizado(s), %d ausente(s), %d ignorado(s).',
            $result['catalogados'],
            $result['atualizados'],
            $result['ausentes'],
            $result['ignorados'],
        ));

        return self::SUCCESS;
    }
}
