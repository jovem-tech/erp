<?php

namespace App\Console\Commands;

use App\Services\Orders\OrderClosureService;
use Illuminate\Console\Command;

class ProcessPendingOsCollections extends Command
{
    protected $signature = 'app:process-pending-os-collections';

    protected $description = 'Envia por WhatsApp as cobrancas agendadas (D+1/D+3/D+5) de OS com saldo pendente.';

    public function handle(OrderClosureService $orderClosureService): int
    {
        $summary = $orderClosureService->processPendingChargeNotifications();

        $this->info(sprintf(
            'Agendamentos lidos: %d | enviados: %d | cancelados: %d | com erro: %d',
            $summary['agendamentos_lidos'],
            $summary['agendamentos_enviados'],
            $summary['agendamentos_cancelados'],
            $summary['agendamentos_com_erro']
        ));

        return self::SUCCESS;
    }
}
