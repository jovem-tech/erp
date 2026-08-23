<?php

namespace App\Console\Commands\Backups;

use App\Services\Backups\BackupRetentionPolicy;
use Illuminate\Console\Command;

class PruneBackups extends Command
{
    protected $signature = 'backup:expurgar {--simulacao : Mostra o que seria removido sem apagar nada}';

    protected $description = 'Aplica a política de retenção (diários, semanais e mensais) aos backups gerenciados.';

    public function handle(BackupRetentionPolicy $policy): int
    {
        $dryRun = (bool) $this->option('simulacao');
        $result = $policy->apply($dryRun);

        $this->info(sprintf(
            '%s%d removido(s), %d mantido(s), %s liberados.',
            $dryRun ? '[simulação] ' : '',
            $result['removidos'],
            $result['mantidos'],
            $this->formatSize($result['bytes_liberados']),
        ));

        return self::SUCCESS;
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return sprintf('%.1f %s', $value, $units[$index]);
    }
}
