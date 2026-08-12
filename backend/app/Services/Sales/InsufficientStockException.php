<?php

namespace App\Services\Sales;

use RuntimeException;

/**
 * Saldo insuficiente para os itens de uma venda.
 *
 * Não é um erro terminal: o operador pode confirmar a venda mesmo assim
 * (`confirmar_estoque_insuficiente`). Carrega a lista completa de ofensores
 * para o PDV destacar as linhas — ver specs/027-vendas-balcao-pdv/spec.md.
 */
final class InsufficientStockException extends RuntimeException
{
    /**
     * @param  array<int, array{peca_id: int, codigo: string, nome: string, disponivel: float, solicitado: float}>  $shortages
     */
    public function __construct(private readonly array $shortages)
    {
        parent::__construct('Não há saldo em estoque suficiente para os itens desta venda.');
    }

    /**
     * @return array<int, array{peca_id: int, codigo: string, nome: string, disponivel: float, solicitado: float}>
     */
    public function shortages(): array
    {
        return $this->shortages;
    }
}
