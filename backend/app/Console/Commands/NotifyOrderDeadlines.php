<?php

namespace App\Console\Commands;

use App\Models\MobileNotification;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Avisa no sino sobre prazos de reparo (os.data_previsao):
 *  - prazo que termina HOJE  -> "Prazo da OS termina hoje"
 *  - prazo que venceu ONTEM  -> "Prazo da OS vencido"
 *
 * Cada OS gera o aviso uma unica vez por dia/tipo (dedupe por
 * rota_destino + tipo_evento + data), entao o comando pode rodar mais de uma
 * vez no dia sem duplicar. Destinatarios: tecnico responsavel + admins ativos.
 */
class NotifyOrderDeadlines extends Command
{
    protected $signature = 'app:notify-order-deadlines';

    protected $description = 'Notifica no sino as OS com prazo de reparo terminando hoje ou vencido ontem.';

    public function handle(NotificationDispatchService $dispatch): int
    {
        $today = Carbon::today();
        $sentToday = $this->notifyForDate($dispatch, $today, 'order.deadline.today');
        $sentOverdue = $this->notifyForDate($dispatch, $today->copy()->subDay(), 'order.deadline.overdue');

        $this->info(sprintf(
            'Prazos notificados — terminam hoje: %d OS | vencidos ontem: %d OS',
            $sentToday,
            $sentOverdue
        ));

        return self::SUCCESS;
    }

    private function notifyForDate(NotificationDispatchService $dispatch, Carbon $deadline, string $tipoEvento): int
    {
        $orders = Order::query()
            ->with(['client'])
            ->whereDate('data_previsao', $deadline->toDateString())
            // So OS ainda abertas: encerradas de verdade (closureCodes) e
            // canceladas nao tem mais prazo a cumprir.
            ->whereNotIn('status', array_merge(OrderStatus::closureCodes(), ['cancelado']))
            ->get();

        $admins = $dispatch->activeAdminIds();
        $notified = 0;

        foreach ($orders as $order) {
            $route = '/os/' . (int) $order->id;

            // Dedupe: um aviso por OS/tipo/dia, mesmo com re-execucoes.
            $jaAvisada = MobileNotification::query()
                ->where('tipo_evento', $tipoEvento)
                ->where('rota_destino', $route)
                ->whereDate('created_at', Carbon::today()->toDateString())
                ->exists();

            if ($jaAvisada) {
                continue;
            }

            $numeroOs = (string) ($order->numero_os ?: ('#' . $order->id));
            $cliente = trim((string) ($order->client?->nome_razao ?? ''));
            $prazoFormatado = $deadline->format('d/m/Y');

            $isToday = $tipoEvento === 'order.deadline.today';

            $dispatch->toUsers(
                array_merge([(int) ($order->tecnico_id ?? 0)], $admins),
                [
                    'kind' => $tipoEvento,
                    'title' => $isToday ? 'Prazo da OS termina hoje' : 'Prazo da OS vencido',
                    'body' => $isToday
                        ? sprintf('A OS %s%s tem prazo de reparo até hoje (%s).', $numeroOs, $cliente !== '' ? ' (' . $cliente . ')' : '', $prazoFormatado)
                        : sprintf('O prazo de reparo da OS %s%s venceu em %s.', $numeroOs, $cliente !== '' ? ' (' . $cliente . ')' : '', $prazoFormatado),
                    'route' => $route,
                    'icon' => $isToday ? 'alarm' : 'alarm-fill',
                    'order_id' => (int) $order->id,
                    'numero_os' => $numeroOs,
                    'data_previsao' => $deadline->toDateString(),
                ]
            );

            $notified++;
        }

        return $notified;
    }
}
