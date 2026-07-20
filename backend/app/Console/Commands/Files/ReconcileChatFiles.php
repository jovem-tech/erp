<?php

namespace App\Console\Commands\Files;

use App\Services\Files\ChatFileReconciliationService;
use Illuminate\Console\Command;

class ReconcileChatFiles extends Command
{
    protected $signature = 'file-manager:reconcile-chat
        {--apply : Tenta reparar vinculos; sem esta flag e dry-run}
        {--limit=500 : Limite do lote}';

    protected $description = 'Reconcilia de forma idempotente anexos do banco chat com o catalogo central.';

    public function handle(ChatFileReconciliationService $reconciliation): int
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
