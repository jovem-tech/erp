<?php

namespace App\Services\Agenda\Sources;

use Carbon\CarbonImmutable;

/**
 * Contrato para trazer as obrigacoes de um modulo para a linha do tempo da
 * agenda.
 *
 * Este e o ponto de extensao do modulo: para um modulo novo aparecer na agenda,
 * basta uma classe que implemente esta interface e uma linha de tag no
 * AppServiceProvider. Nenhum arquivo do motor precisa mudar.
 *
 * Uma AgendaSource NAO escreve na agenda - ela so descreve o que existe na
 * janela pedida. Criar, atualizar, concluir e cancelar sao decisoes do
 * AgendaSourceReconciler, que compara o retorno daqui com o que ja esta gravado.
 */
interface AgendaSource
{
    /**
     * Chave estavel, usada como `tipo` e `origem_tipo` das linhas geradas.
     * Mudar esta string depois de estar em producao orfana os compromissos ja
     * criados por ela.
     */
    public function key(): string;

    /** Rotulo exibido nos filtros da tela. */
    public function label(): string;

    /** Cor/icone do item no calendario (classe utilitaria do desktop). */
    public function icon(): string;

    /**
     * Obrigacoes em aberto no intervalo.
     *
     * @return iterable<int, AgendaSourceItem>
     */
    public function collect(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
