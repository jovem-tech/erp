<?php

namespace App\Services\Estoque;

use RuntimeException;

/**
 * Saldo insuficiente para a movimentacao pedida.
 *
 * Carrega a lista de ofensores no mesmo formato que o PDV ja consome, para o
 * frontend destacar a linha exata em vez de mostrar um erro generico.
 */
class SaldoInsuficienteException extends RuntimeException
{
    /**
     * @param array<int, array<string, mixed>> $faltas
     */
    public function __construct(private readonly array $faltas)
    {
        parent::__construct('Estoque insuficiente para concluir a operação.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function faltas(): array
    {
        return $this->faltas;
    }
}
