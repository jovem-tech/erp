<?php

namespace App\Console\Commands;

use App\Models\Financeiro;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\Financeiro\FinanceiroService;
use App\Services\Orders\OrderClosureService;
use App\Services\Orders\OrderEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Cancela cobrancas abertas de OS encerradas SEM cobranca.
 *
 * Ate a correcao em OrderClosureService::settleNonBilledClosure(), a baixa de
 * uma OS descartada / devolvida sem reparo / entregue sem custo criava mesmo
 * assim o titulo a receber no valor final da OS. O resultado eram cobrancas
 * abertas contra clientes que nao deviam nada - inflando contas a receber,
 * fluxo de caixa, DRE e a agenda.
 *
 * Este comando limpa o que ficou para tras. Roda em simulacao por padrao: mexer
 * em titulo financeiro sem o operador ver antes o que sera tocado nao e
 * aceitavel.
 */
class CancelNonBilledOrderReceivables extends Command
{
    protected $signature = 'os:cancelar-cobrancas-sem-cobranca
        {--aplicar : Executa de fato; sem esta flag apenas simula}';

    protected $description = 'Cancela titulos a receber em aberto de OS encerradas sem cobranca.';

    public function handle(FinanceiroService $financeiroService, OrderEventService $orderEventService): int
    {
        if (! Schema::hasTable('financeiro') || ! Schema::hasTable('os')) {
            $this->warn('Tabelas financeiras ou de OS ausentes — nada a fazer.');

            return self::SUCCESS;
        }

        $aplicar = (bool) $this->option('aplicar');

        $osIds = Order::query()
            ->whereIn('status', OrderClosureService::nonBilledClosureStatuses())
            ->pluck('id');

        $titulos = Financeiro::query()
            ->whereIn('os_id', $osIds)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
            ->with('order')
            ->get();

        $cancelaveis = [];
        $comMovimento = [];

        foreach ($titulos as $titulo) {
            // Titulo com movimento significa dinheiro que ja entrou. Cancelar
            // apagaria uma entrada real do caixa - decisao humana, no Financeiro.
            if ($titulo->movimentos()->exists()) {
                $comMovimento[] = $titulo;

                continue;
            }

            $cancelaveis[] = $titulo;
        }

        $this->renderTable($cancelaveis, 'Títulos a cancelar');

        if ($comMovimento !== []) {
            $this->newLine();
            $this->warn('Estes têm valor já recebido e NÃO serão tocados — avalie devolução ou retenção no Financeiro:');
            $this->renderTable($comMovimento, 'Títulos com movimento');
        }

        if ($cancelaveis === []) {
            $this->info('Nada a cancelar.');

            return self::SUCCESS;
        }

        $total = array_sum(array_map(static fn (Financeiro $t): float => (float) $t->valor, $cancelaveis));

        if (! $aplicar) {
            $this->newLine();
            $this->info(sprintf(
                'SIMULAÇÃO — %d título(s), R$ %s. Rode com --aplicar para cancelar.',
                count($cancelaveis),
                number_format($total, 2, ',', '.')
            ));

            return self::SUCCESS;
        }

        $cancelados = 0;

        foreach ($cancelaveis as $titulo) {
            try {
                $financeiroService->cancel($titulo);
                $cancelados++;

                $orderEventService->record(
                    (int) $titulo->os_id,
                    OrderEvent::CATEGORIA_FINANCEIRO,
                    OrderEvent::TIPO_FECHAMENTO_CONCLUIDO,
                    'Cobrança indevida cancelada',
                    sprintf(
                        'Título nº %d (R$ %s) cancelado: a OS foi encerrada sem cobrança.',
                        (int) $titulo->id,
                        number_format((float) $titulo->valor, 2, ',', '.')
                    ),
                    ['financeiro_id' => (int) $titulo->id],
                    null,
                    OrderEvent::ORIGEM_SISTEMA
                );
            } catch (Throwable $exception) {
                // Um título problemático não pode interromper a limpeza dos demais.
                report($exception);
                $this->error(sprintf('Título %d: %s', (int) $titulo->id, $exception->getMessage()));
            }
        }

        $this->info(sprintf(
            '%d título(s) cancelado(s), R$ %s retirados de contas a receber.',
            $cancelados,
            number_format($total, 2, ',', '.')
        ));

        $this->line('Rode `php artisan agenda:sincronizar-origens` para refletir na agenda.');

        return self::SUCCESS;
    }

    /** @param array<int, Financeiro> $titulos */
    private function renderTable(array $titulos, string $titulo): void
    {
        if ($titulos === []) {
            return;
        }

        $this->line($titulo.':');
        $this->table(
            ['Título', 'OS', 'Status da OS', 'Vencimento', 'Valor'],
            array_map(static fn (Financeiro $t): array => [
                $t->id,
                $t->order?->numero_os ?? $t->os_id,
                $t->order?->status ?? '-',
                $t->data_vencimento?->format('d/m/Y') ?? '-',
                'R$ '.number_format((float) $t->valor, 2, ',', '.'),
            ], $titulos)
        );
    }
}
