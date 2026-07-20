<?php

namespace App\Console\Commands\Files;

use App\Services\Files\FileScanService;
use Illuminate\Console\Command;

class ScanManagedFiles extends Command
{
    protected $signature = 'file-manager:scan
        {--root=managed : Alias de root configurada}
        {--limit= : Limite maximo de arquivos}
        {--max-depth= : Profundidade maxima}';

    protected $description = 'Executa inventario dry-run em uma root allowlisted, sem mover ou excluir arquivos.';

    public function handle(FileScanService $scanner): int
    {
        try {
            $run = $scanner->scan(
                (string) $this->option('root'),
                $this->option('limit') !== null ? (int) $this->option('limit') : null,
                $this->option('max-depth') !== null ? (int) $this->option('max-depth') : null
            );
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['run', 'status', 'processados', 'ignorados', 'findings', 'falhas'], [[
            $run->uuid,
            $run->status,
            $run->processed_count,
            $run->skipped_count,
            $run->finding_count,
            $run->failed_count,
        ]]);

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
