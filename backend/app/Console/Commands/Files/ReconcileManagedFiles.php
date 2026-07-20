<?php

namespace App\Console\Commands\Files;

use App\Services\Files\FileReconciliationService;
use Illuminate\Console\Command;

class ReconcileManagedFiles extends Command
{
    protected $signature = 'file-manager:reconcile
        {--apply : Aplica marcacao de integridade; sem esta flag e dry-run}
        {--limit=500 : Limite do lote}';

    protected $description = 'Reconcilia registros sem blob de forma idempotente; dry-run por padrao.';

    public function handle(FileReconciliationService $reconciliation): int
    {
        try {
            $result = $reconciliation->reconcile(
                (bool) $this->option('apply'),
                (int) $this->option('limit')
            );
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
