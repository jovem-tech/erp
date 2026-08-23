<?php

namespace App\Services\Agenda\Sources;

use App\Models\AgendaCompromisso;
use App\Models\OsCobrancaAgendamento;
use Carbon\CarbonImmutable;

/**
 * Disparos de cobranca automatica de saldo em aberto de OS.
 *
 * Aparecem na agenda para que a cobranca que vai sair amanha nao seja uma
 * surpresa - da chance de cancelar antes que o cliente receba a mensagem.
 */
class CobrancaOsSource implements AgendaSource
{
    public function key(): string
    {
        return 'cobranca_os';
    }

    public function label(): string
    {
        return 'Cobrança automática';
    }

    public function icon(): string
    {
        return 'bi-envelope-exclamation';
    }

    public function collect(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $agendamentos = OsCobrancaAgendamento::query()
            ->with(['order', 'client'])
            ->whereIn('status', [
                OsCobrancaAgendamento::STATUS_PENDENTE,
                OsCobrancaAgendamento::STATUS_ENVIADO,
                OsCobrancaAgendamento::STATUS_ERRO,
            ])
            ->whereNotNull('enviar_em')
            ->whereBetween('enviar_em', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->get();

        foreach ($agendamentos as $agendamento) {
            $numero = trim((string) ($agendamento->order?->numero_os ?: '#'.$agendamento->os_id));
            $cliente = trim((string) ($agendamento->client?->nome_razao ?? ''));
            $status = (string) $agendamento->status;

            yield new AgendaSourceItem(
                originId: (int) $agendamento->id,
                titulo: sprintf('Cobrança da OS %s%s', $numero, $cliente !== '' ? ' — '.$cliente : ''),
                inicioEm: CarbonImmutable::parse($agendamento->enviar_em),
                descricao: $status === OsCobrancaAgendamento::STATUS_ERRO
                    ? 'A tentativa de envio automático falhou — confira antes de reenviar.'
                    : 'Cobrança automática do saldo em aberto da OS.',
                diaInteiro: false,
                clienteId: (int) $agendamento->cliente_id > 0 ? (int) $agendamento->cliente_id : null,
                osId: (int) $agendamento->os_id > 0 ? (int) $agendamento->os_id : null,
                // Erro nao e "resolvido": pede acao humana, entao continua
                // pendente na agenda ate alguem cuidar.
                prioridade: $status === OsCobrancaAgendamento::STATUS_ERRO
                    ? AgendaCompromisso::PRIORIDADE_ALTA
                    : AgendaCompromisso::PRIORIDADE_BAIXA,
                resolvido: $status === OsCobrancaAgendamento::STATUS_ENVIADO,
            );
        }
    }
}
