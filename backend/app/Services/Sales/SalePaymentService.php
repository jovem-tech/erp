<?php

namespace App\Services\Sales;

use App\Models\Financeiro;
use App\Models\FinanceiroFormaPagamento;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Financeiro\FinanceiroCartaoService;
use App\Services\Financeiro\FinanceiroService;
use RuntimeException;

/**
 * Título a receber e baixas de uma venda de balcão.
 *
 * Reaproveita integralmente o motor financeiro: a venda não conhece regra de
 * DRE, conta financeira, taxa de cartão nem status de título — só descreve o
 * que foi recebido. Coreografia copiada de OrderClosureService.
 *
 * Ver specs/027-vendas-balcao-pdv/spec.md.
 */
class SalePaymentService
{
    public function __construct(
        private readonly FinanceiroService $financeiroService,
        private readonly FinanceiroCartaoService $financeiroCartaoService
    ) {}

    /**
     * Simula as taxas de cartão ANTES de abrir a transação.
     *
     * Falha rápido e sem efeito colateral quando a combinação
     * operadora/bandeira/parcelas não tem taxa ativa: num PDV é melhor recusar
     * antes de baixar estoque do que estornar depois.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<int, array<string, mixed>|null>  indexado igual a $payments
     */
    public function simulateCards(array $payments): array
    {
        $simulations = [];

        foreach ($payments as $index => $payment) {
            $forma = trim((string) ($payment['forma_pagamento'] ?? ''));
            $operadoraId = (int) ($payment['operadora_id'] ?? 0);

            if (! FinanceiroFormaPagamento::isCardCode($forma) || $operadoraId <= 0) {
                $simulations[$index] = null;

                continue;
            }

            $simulations[$index] = $this->financeiroCartaoService->simulate([
                'valor_bruto' => round((float) ($payment['valor'] ?? 0), 2),
                'operadora_id' => $operadoraId,
                'bandeira_id' => ! empty($payment['bandeira_id']) ? (int) $payment['bandeira_id'] : null,
                'modalidade' => (string) ($payment['modalidade'] ?? ''),
                'forma_pagamento' => $forma,
                'parcelas' => max(1, (int) ($payment['parcelas'] ?? 1)),
            ]);
        }

        return $simulations;
    }

    /**
     * Cria o título e registra as baixas. Deve rodar dentro da transação da venda.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<int, array<string, mixed>|null>  $simulations
     * @return array<string, mixed>
     */
    public function process(
        Sale $sale,
        array $payments,
        array $simulations,
        ?int $actorId,
        ?int $cashAccountId = null
    ): array {
        $total = round((float) $sale->total, 2);

        if ($total <= 0) {
            return $this->emptySummary();
        }

        $paid = round(array_sum(array_map(
            static fn (array $payment): float => round((float) ($payment['valor'] ?? 0), 2),
            $payments
        )), 2);

        if ($paid > $total + 0.001) {
            throw new RuntimeException('A soma dos pagamentos não pode ser maior que o total da venda.');
        }

        $title = $this->createReceivable($sale);

        $sale->forceFill(['financeiro_id' => (int) $title->id])->save();

        foreach ($payments as $index => $payment) {
            $value = round((float) ($payment['valor'] ?? 0), 2);

            if ($value <= 0) {
                continue;
            }

            $forma = trim((string) ($payment['forma_pagamento'] ?? ''));
            $simulation = $simulations[$index] ?? null;

            // Dinheiro entra na gaveta do turno aberto. Sem isso o operador
            // teria de escolher a conta em toda venda em espécie
            // (specs/028-caixa-sessoes).
            $accountId = ! empty($payment['conta_financeira_id'])
                ? (int) $payment['conta_financeira_id']
                : ($forma === 'dinheiro' ? $cashAccountId : null);

            // O operadora_id é repassado de propósito: assim o próprio
            // FinanceiroService grava financeiro_movimentos_cartao e cria a
            // despesa da taxa. Gravar a meta aqui TAMBÉM duplicaria a despesa
            // no DRE — é o caminho oposto ao de OrderClosureService, que não
            // repassa e por isso grava tudo por conta própria.
            $summary = $this->financeiroService->registerMovement($title, [
                'valor_movimento' => $value,
                'forma_pagamento' => $forma,
                'conta_financeira_id' => $accountId,
                'data_movimento' => $payment['data_pagamento'] ?? $sale->data_venda?->toDateString(),
                'documento_ref' => 'Venda '.$sale->numero,
                'observacoes' => trim((string) ($payment['observacoes'] ?? '')) ?: null,
                'operadora_id' => ! empty($payment['operadora_id']) ? (int) $payment['operadora_id'] : null,
                'bandeira_id' => ! empty($payment['bandeira_id']) ? (int) $payment['bandeira_id'] : null,
                'modalidade' => $payment['modalidade'] ?? null,
                'parcelas' => max(1, (int) ($payment['parcelas'] ?? 1)),
            ]);

            $received = isset($payment['valor_recebido']) && $payment['valor_recebido'] !== null
                ? round((float) $payment['valor_recebido'], 2)
                : null;

            SalePayment::query()->create([
                'venda_id' => (int) $sale->id,
                'forma_pagamento' => $forma,
                'conta_financeira_id' => $accountId,
                'valor' => $value,
                'valor_recebido' => $received,
                'troco' => $received !== null ? max(0, round($received - $value, 2)) : 0,
                'parcelas' => $simulation['parcelas'] ?? max(1, (int) ($payment['parcelas'] ?? 1)),
                'operadora_id' => ! empty($payment['operadora_id']) ? (int) $payment['operadora_id'] : null,
                'bandeira_id' => ! empty($payment['bandeira_id']) ? (int) $payment['bandeira_id'] : null,
                'modalidade' => $simulation['modalidade'] ?? ($payment['modalidade'] ?? null),
                'valor_taxa' => round((float) ($simulation['valor_taxa'] ?? 0), 2),
                'valor_liquido' => isset($simulation['valor_liquido'])
                    ? round((float) $simulation['valor_liquido'], 2)
                    : $value,
                'movimento_id' => ! empty($summary['movement_id']) ? (int) $summary['movement_id'] : null,
                'data_pagamento' => $payment['data_pagamento'] ?? $sale->data_venda?->toDateString(),
                'observacoes' => trim((string) ($payment['observacoes'] ?? '')) ?: null,
                'ordem' => $index,
            ]);
        }

        return $this->summarize($sale, $title);
    }

    /**
     * Cancela o título da venda, estornando movimentos e taxas de cartão.
     *
     * FinanceiroService::cancel() já apaga os movimentos (o saldo da conta volta
     * ao estado anterior, que é o correto quando o dinheiro sai da gaveta) e
     * cancela em cascata as despesas de taxa derivadas.
     */
    public function cancelForSale(Sale $sale): void
    {
        $titleId = (int) ($sale->financeiro_id ?? 0);

        if ($titleId <= 0) {
            return;
        }

        $title = Financeiro::query()->find($titleId);

        if (! $title instanceof Financeiro || $title->status === Financeiro::STATUS_CANCELADO) {
            return;
        }

        $this->financeiroService->cancel($title);
    }

    private function createReceivable(Sale $sale): Financeiro
    {
        $clientId = (int) ($sale->cliente_id ?? 0);
        $orderId = (int) ($sale->os_id ?? 0);

        return $this->financeiroService->create([
            'venda_id' => (int) $sale->id,
            'cliente_id' => $clientId > 0 ? $clientId : null,
            'os_id' => $orderId > 0 ? $orderId : null,
            // Sem isto, resolveClassification() recusa o título de consumidor
            // final com "Selecione o cliente desta cobrança ou vincule uma OS".
            // E `avulso` não pode conviver com os_id, daí a dupla condição.
            'avulso' => $clientId <= 0 && $orderId <= 0,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => Sale::FINANCE_CATEGORY,
            'descricao' => 'Venda '.$sale->numero,
            'valor' => round((float) $sale->total, 2),
            'data_vencimento' => $sale->data_venda?->toDateString() ?? now()->toDateString(),
            'data_competencia' => $sale->data_venda?->toDateString() ?? now()->toDateString(),
            // Rastro textual: `origem_id` fica nulo de propósito, porque apesar
            // do nome ele é um belongsTo(FinanceiroMovimento) e receberia um id
            // alheio. O vínculo real é a coluna `financeiro.venda_id`.
            'origem_tipo' => 'venda',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Sale $sale, Financeiro $title): array
    {
        $summary = $this->financeiroService->movementSummary($title->refresh());

        $paid = round((float) ($summary['valor_movimentado'] ?? 0), 2);
        $total = round((float) $sale->total, 2);

        return [
            'financeiro_id' => (int) $title->id,
            'valor_pago' => $paid,
            'valor_aberto' => round((float) ($summary['valor_aberto'] ?? 0), 2),
            'status_pagamento' => $this->resolvePaymentStatus($paid, $total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'financeiro_id' => null,
            'valor_pago' => 0.0,
            'valor_aberto' => 0.0,
            'status_pagamento' => Sale::PAYMENT_STATUS_PAID,
        ];
    }

    private function resolvePaymentStatus(float $paid, float $total): string
    {
        if ($total <= 0 || $paid + 0.001 >= $total) {
            return Sale::PAYMENT_STATUS_PAID;
        }

        return $paid > 0 ? Sale::PAYMENT_STATUS_PARTIAL : Sale::PAYMENT_STATUS_PENDING;
    }
}
