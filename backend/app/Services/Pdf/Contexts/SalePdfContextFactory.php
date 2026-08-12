<?php

namespace App\Services\Pdf\Contexts;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\FinanceiroFormaPagamento;

/**
 * Contexto do comprovante de venda de balcão — specs/027-vendas-balcao-pdv.
 *
 * Diferente de BudgetPdfContextFactory, NÃO estende OrderPdfContextFactory:
 * uma venda de balcão não tem OS nem equipamento, e herdar aquele contexto só
 * traria seções vazias. `empresa.*` e `documento.*` são injetados pelo próprio
 * PdfGenerationService.
 */
class SalePdfContextFactory implements PdfContextFactoryInterface
{
    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $subject, array $options = []): array
    {
        $sale = $this->resolveSale($subject);

        if (! $sale instanceof Sale) {
            return [];
        }

        $sale->loadMissing(['items', 'payments', 'client', 'seller']);

        $paid = round((float) $sale->valor_pago, 2);
        $total = round((float) $sale->total, 2);

        return [
            'venda' => [
                'numero' => (string) $sale->numero,
                'status' => Sale::statusLabel($sale->status),
                'data' => $sale->data_venda,
                'vendedor' => (string) ($sale->seller?->nome ?? ''),
                'subtotal' => round((float) $sale->subtotal, 2),
                'desconto' => round((float) $sale->desconto, 2),
                'acrescimo' => round((float) $sale->acrescimo, 2),
                'total' => $total,
                'valor_pago' => $paid,
                'valor_aberto' => max(0, round($total - $paid, 2)),
                'troco' => round((float) $sale->payments->sum('troco'), 2),
                'status_pagamento' => Sale::paymentStatusLabel($sale->status_pagamento),
                'observacoes' => (string) ($sale->observacoes ?? ''),
            ],
            // Fallback de consumidor final, mesma ideia do orçamento avulso.
            'cliente' => [
                'nome' => $sale->customerName(),
                'telefone' => (string) ($sale->client?->telefone1 ?? $sale->telefone_contato ?? ''),
                'email' => (string) ($sale->client?->email ?? $sale->email_contato ?? ''),
                'documento' => (string) ($sale->client?->cpf_cnpj ?? $sale->cliente_documento_avulso ?? ''),
            ],
            'itens' => $sale->items->map(static fn (SaleItem $item): array => [
                'tipo' => SaleItem::typeLabel($item->tipo_item),
                'codigo' => (string) ($item->codigo_snapshot ?? ''),
                'descricao' => (string) $item->descricao,
                'quantidade' => round((float) $item->quantidade, 3),
                'valor_unitario' => round((float) $item->valor_unitario, 2),
                'desconto' => round((float) $item->desconto, 2),
                'acrescimo' => round((float) $item->acrescimo, 2),
                'valor_total' => round((float) $item->total, 2),
                'observacoes' => (string) ($item->observacoes ?? ''),
            ])->all(),
            'pagamentos' => $sale->payments->map(static fn (SalePayment $payment): array => [
                'forma_pagamento' => self::paymentLabel((string) $payment->forma_pagamento),
                'parcelas' => (int) $payment->parcelas,
                'valor' => round((float) $payment->valor, 2),
                'troco' => round((float) $payment->troco, 2),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function resolveSale(array $subject): ?Sale
    {
        $sale = $subject['sale'] ?? $subject['venda'] ?? null;

        if ($sale instanceof Sale) {
            return $sale;
        }

        $saleId = (int) ($subject['sale_id'] ?? $subject['venda_id'] ?? 0);

        return $saleId > 0 ? Sale::query()->find($saleId) : null;
    }

    private static function paymentLabel(string $code): string
    {
        foreach (FinanceiroFormaPagamento::options() as $option) {
            if ((string) ($option['value'] ?? '') === $code) {
                return (string) ($option['label'] ?? $code);
            }
        }

        return ucfirst(str_replace('_', ' ', $code));
    }
}
