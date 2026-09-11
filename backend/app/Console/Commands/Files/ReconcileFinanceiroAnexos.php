<?php

namespace App\Console\Commands\Files;

use App\Services\Files\FinanceiroAnexoReconciliationService;
use Illuminate\Console\Command;

class ReconcileFinanceiroAnexos extends Command
{
    protected $signature = 'file-manager:reconcile-financeiro-anexos
        {--apply : Aplica correções; sem esta opção a execução é somente leitura}
        {--limit=1000 : Máximo de anexos e aliases processados}';

    protected $description = 'Reconcilia anexos financeiros com catálogo, vínculos, aliases e lixeira do Gerenciador';

    public function handle(FinanceiroAnexoReconciliationService $reconciler): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 10_000) {
            $this->error('O limite deve ser um inteiro entre 1 e 10000.');

            return self::INVALID;
        }

        $result = $reconciler->reconcile((bool) $this->option('apply'), $limit);
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ((int) $result['failed']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
