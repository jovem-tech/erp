<?php

namespace App\Console\Commands\Files;

use App\Models\Files\FileScanRun;
use Illuminate\Console\Command;

/**
 * Retencao do historico de varreduras do gerenciador de arquivos.
 *
 * `file-manager:purge-trash` limpa a lixeira de ARQUIVOS; nada limpava
 * `file_scan_runs`, que crescia 11.232 linhas por dia (uma a cada 7,7 segundos)
 * desde 2026-07-19 e ja somava 382.781 linhas / 373,6 MB — a maior tabela do
 * banco, ocupando 35% do innodb_buffer_pool_size de 1 GB e expulsando do cache
 * as paginas de `os` e `clientes` que a operacao usa o dia inteiro.
 *
 * Execucoes com achado ainda em aberto sao preservadas seja qual for a idade:
 * apagar o run levaria os achados junto (FK com cascadeOnDelete) e a auditoria
 * perderia justamente o que interessa.
 */
class PurgeScanRuns extends Command
{
    protected $signature = 'file-manager:purge-scan-runs
        {--days= : Idade minima em dias (padrao: config file-manager.retention.scan_run_days)}
        {--limit= : Teto de linhas nesta execucao}
        {--dry-run : Apenas informa quantas linhas seriam apagadas}';

    protected $description = 'Aplica retencao ao historico de varreduras (file_scan_runs), preservando execucoes com achados em aberto.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('file-manager.retention.scan_run_days', 14));
        $days = max(1, $days);

        $limit = (int) ($this->option('limit') ?: config('file-manager.retention.scan_run_batch_size', 5000));
        $limit = max(100, min(20_000, $limit));

        $cutoff = now()->subDays($days);

        $query = FileScanRun::query()
            ->where('created_at', '<', $cutoff)
            ->whereIn('status', ['completed', 'completed_with_errors', 'failed', 'interrupted'])
            // Preserva o que ainda tem achado aberto — e' o unico conteudo desta
            // tabela que alguem consulta depois.
            ->whereDoesntHave('findings', static function ($findings): void {
                $findings->where('resolution_status', 'open');
            });

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '%d execucoes anteriores a %s seriam apagadas.',
                (clone $query)->count(),
                $cutoff->format('d/m/Y H:i')
            ));

            return self::SUCCESS;
        }

        $deleted = 0;

        // Em lotes: um DELETE unico de centenas de milhares de linhas seguraria
        // a tabela e o undo log por tempo demais num banco que esta em uso.
        while ($deleted < $limit) {
            $ids = (clone $query)
                ->orderBy('id')
                ->limit(min(1000, $limit - $deleted))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += FileScanRun::query()->whereIn('id', $ids)->delete();
        }

        $this->info(sprintf('%d execucoes de varredura apagadas (retencao: %d dias).', $deleted, $days));

        return self::SUCCESS;
    }
}
