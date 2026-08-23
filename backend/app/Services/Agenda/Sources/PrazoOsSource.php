<?php

namespace App\Services\Agenda\Sources;

use App\Models\AgendaCompromisso;
use App\Models\Order;
use App\Models\OrderStatus;
use Carbon\CarbonImmutable;

/**
 * Prazo de reparo prometido ao cliente (os.data_previsao).
 *
 * Reaproveita a MESMA regra de "OS ainda tem prazo a cumprir" do comando
 * app:notify-order-deadlines (OrderStatus::DEADLINE_FREEZE_CODES). Duplicar
 * essa lista faria o sino e a agenda divergirem no dia em que um status novo
 * fosse acrescentado.
 */
class PrazoOsSource implements AgendaSource
{
    public function key(): string
    {
        return 'prazo_os';
    }

    public function label(): string
    {
        return 'Prazo de reparo';
    }

    public function icon(): string
    {
        return 'bi-alarm';
    }

    public function collect(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $orders = Order::query()
            ->with(['client'])
            ->whereNotNull('data_previsao')
            ->whereBetween('data_previsao', [$from->toDateString(), $to->toDateString()])
            ->get();

        foreach ($orders as $order) {
            $numero = trim((string) ($order->numero_os ?: '#'.$order->id));
            $cliente = trim((string) ($order->client?->nome_razao ?? ''));
            $status = (string) $order->status;

            yield new AgendaSourceItem(
                originId: (int) $order->id,
                titulo: sprintf('Prazo da OS %s%s', $numero, $cliente !== '' ? ' — '.$cliente : ''),
                inicioEm: CarbonImmutable::parse($order->data_previsao)->setTime(8, 0),
                descricao: 'Prazo de reparo prometido ao cliente.',
                diaInteiro: true,
                responsavelId: (int) $order->tecnico_id > 0 ? (int) $order->tecnico_id : null,
                clienteId: (int) $order->cliente_id > 0 ? (int) $order->cliente_id : null,
                osId: (int) $order->id,
                prioridade: AgendaCompromisso::PRIORIDADE_ALTA,
                // Status que congela o prazo = a OS nao tem mais prazo a
                // cumprir. Mesma definicao usada pelo aviso do sino.
                resolvido: in_array($status, OrderStatus::DEADLINE_FREEZE_CODES, true),
            );
        }
    }
}
