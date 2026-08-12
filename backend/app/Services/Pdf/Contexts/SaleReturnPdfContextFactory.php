<?php

namespace App\Services\Pdf\Contexts;

use App\Models\FinanceiroFormaPagamento;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SaleReturnPayment;

/**
 * Contexto do comprovante de devolução — specs/029-devolucao-troca.
 *
 * `empresa.*` e `documento.*` são injetados pelo PdfGenerationService.
 */
class SaleReturnPdfContextFactory implements PdfContextFactoryInterface
{
    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $subject, array $options = []): array
    {
        $devolucao = $this->resolveReturn($subject);

        if (! $devolucao instanceof SaleReturn) {
            return [];
        }

        $devolucao->loadMissing(['items.saleItem', 'payments', 'sale.client', 'creator']);
        $sale = $devolucao->sale;

        return [
            'devolucao' => [
                'numero' => (string) $devolucao->numero,
                'data' => $devolucao->data_devolucao,
                'motivo' => (string) $devolucao->motivo,
                'operador' => (string) ($devolucao->creator?->nome ?? ''),
                'venda_numero' => (string) ($sale?->numero ?? ''),
                'venda_data' => $sale?->data_venda,
                'subtotal_itens' => round((float) $devolucao->subtotal_itens, 2),
                'valor_devolvido' => round((float) $devolucao->valor_devolvido, 2),
                'valor_reembolsado' => round((float) $devolucao->valor_reembolsado, 2),
                'valor_abatido' => round((float) $devolucao->valor_abatido, 2),
            ],
            'cliente' => [
                'nome' => $sale?->customerName() ?? 'Consumidor final',
                'telefone' => (string) ($sale?->client?->telefone1 ?? $sale?->telefone_contato ?? ''),
                'documento' => (string) ($sale?->client?->cpf_cnpj ?? $sale?->cliente_documento_avulso ?? ''),
            ],
            'itens' => $devolucao->items->map(static fn (SaleReturnItem $item): array => [
                'codigo' => (string) ($item->saleItem?->codigo_snapshot ?? ''),
                'descricao' => (string) ($item->saleItem?->descricao ?? ''),
                'quantidade' => round((float) $item->quantidade, 3),
                'valor_unitario' => round((float) $item->valor_unitario, 2),
                'valor_total' => round((float) $item->valor_reembolsado, 2),
            ])->all(),
            'reembolsos' => $devolucao->payments->map(static fn (SaleReturnPayment $p): array => [
                'forma_pagamento' => self::paymentLabel((string) $p->forma_pagamento),
                'valor' => round((float) $p->valor, 2),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function resolveReturn(array $subject): ?SaleReturn
    {
        $devolucao = $subject['return'] ?? $subject['devolucao'] ?? null;

        if ($devolucao instanceof SaleReturn) {
            return $devolucao;
        }

        $id = (int) ($subject['return_id'] ?? $subject['devolucao_id'] ?? 0);

        return $id > 0 ? SaleReturn::query()->find($id) : null;
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
