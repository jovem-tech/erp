<?php

namespace App\Services\Agenda\Sources;

use App\Models\Financeiro;

class ContasPagarSource extends ContasVencimentoSource
{
    public function key(): string
    {
        return 'conta_pagar';
    }

    public function label(): string
    {
        return 'Contas a pagar';
    }

    public function icon(): string
    {
        return 'bi-arrow-down-circle';
    }

    protected function tipo(): string
    {
        return Financeiro::TIPO_PAGAR;
    }

    protected function prefixo(): string
    {
        return 'Pagar';
    }
}
