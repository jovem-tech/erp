<?php

namespace App\Services\Agenda\Sources;

use App\Models\AgendaCompromisso;
use Carbon\CarbonImmutable;

/**
 * O que uma AgendaSource devolve: a fotografia de uma obrigacao, sem nenhuma
 * nocao de estar ou nao gravada na agenda.
 */
class AgendaSourceItem
{
    public function __construct(
        public readonly int $originId,
        public readonly string $titulo,
        public readonly CarbonImmutable $inicioEm,
        public readonly ?string $descricao = null,
        public readonly bool $diaInteiro = true,
        public readonly ?int $responsavelId = null,
        public readonly ?int $clienteId = null,
        public readonly ?int $osId = null,
        public readonly string $prioridade = AgendaCompromisso::PRIORIDADE_NORMAL,
        public readonly ?int $lembreteMinutos = null,
        /**
         * A obrigacao ja foi resolvida na origem (conta paga, followup
         * concluido). O reconciliador conclui o compromisso em vez de apagar,
         * para o historico do dia continuar legivel.
         */
        public readonly bool $resolvido = false,
    ) {}
}
