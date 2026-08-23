<?php

namespace App\Services\Agenda\Sources;

use App\Models\Financeiro;

class ContasReceberSource extends ContasVencimentoSource
{
    public function key(): string
    {
        return 'conta_receber';
    }

    public function label(): string
    {
        return 'Contas a receber';
    }

    public function icon(): string
    {
        return 'bi-arrow-up-circle';
    }

    protected function tipo(): string
    {
        return Financeiro::TIPO_RECEBER;
    }

    protected function prefixo(): string
    {
        return 'Receber';
    }
}
