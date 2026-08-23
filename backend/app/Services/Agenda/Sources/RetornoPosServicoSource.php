<?php

namespace App\Services\Agenda\Sources;

use App\Models\AgendaCompromisso;
use App\Models\CrmFollowup;
use Carbon\CarbonImmutable;

/**
 * Retornos pos-servico agendados na baixa da OS.
 *
 * Esta fonte e a razao mais direta de o modulo existir: o toggle "Retorno
 * pos-servico" da tela de baixa ja gravava em `crm_followups` desde
 * OrderClosureService::createReturnFollowup(), e nenhuma tela do sistema lia
 * essa tabela. O compromisso era agendado e nunca mais visto.
 */
class RetornoPosServicoSource implements AgendaSource
{
    public function key(): string
    {
        return 'retorno_pos_servico';
    }

    public function label(): string
    {
        return 'Retorno pós-serviço';
    }

    public function icon(): string
    {
        return 'bi-telephone-outbound';
    }

    public function collect(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $followups = CrmFollowup::query()
            ->with(['client'])
            ->whereIn('status', [CrmFollowup::STATUS_PENDENTE, CrmFollowup::STATUS_CONCLUIDO])
            ->whereBetween('data_prevista', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->get();

        foreach ($followups as $followup) {
            $cliente = trim((string) ($followup->client?->nome_razao ?? ''));

            yield new AgendaSourceItem(
                originId: (int) $followup->id,
                titulo: mb_substr(trim((string) $followup->titulo) ?: 'Retorno pós-serviço', 0, 160),
                inicioEm: CarbonImmutable::parse($followup->data_prevista),
                descricao: trim(implode("\n", array_filter([
                    trim((string) $followup->descricao),
                    $cliente !== '' ? 'Cliente: '.$cliente : '',
                ]))) ?: null,
                // Ligar para um cliente tem hora: a baixa ja agenda 10:00.
                diaInteiro: false,
                responsavelId: (int) $followup->usuario_responsavel > 0
                    ? (int) $followup->usuario_responsavel
                    : null,
                clienteId: (int) $followup->cliente_id > 0 ? (int) $followup->cliente_id : null,
                osId: (int) $followup->os_id > 0 ? (int) $followup->os_id : null,
                prioridade: AgendaCompromisso::PRIORIDADE_NORMAL,
                lembreteMinutos: 30,
                resolvido: (string) $followup->status === CrmFollowup::STATUS_CONCLUIDO,
            );
        }
    }
}
