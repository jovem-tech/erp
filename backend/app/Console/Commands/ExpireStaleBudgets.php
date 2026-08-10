<?php

namespace App\Console\Commands;

use App\Services\Budgets\BudgetApprovalService;
use Illuminate\Console\Command;

/**
 * Fecha as propostas que passaram do prazo e continuam esperando resposta do
 * cliente: status vai para "vencido" (Budget::STATUS_EXPIRED), com registro no
 * historico do orcamento, evento na OS vinculada e aviso no sino.
 *
 * Sem isso o painel mostra "Aguardando resposta" indefinidamente enquanto o link
 * publico ja devolve 410 para o cliente. Para reabrir, a equipe envia uma nova
 * proposta — o envio renova a validade e o link (BudgetApprovalService).
 */
class ExpireStaleBudgets extends Command
{
    protected $signature = 'app:expire-budgets {--limit=200 : Máximo de orçamentos processados por execução}';

    protected $description = 'Marca como vencidos os orçamentos cujo prazo terminou sem resposta do cliente.';

    public function handle(BudgetApprovalService $budgetApprovalService): int
    {
        $expired = $budgetApprovalService->expireStaleBudgets((int) $this->option('limit'));

        $this->info(sprintf('Orçamentos marcados como vencidos: %d', $expired));

        return self::SUCCESS;
    }
}
