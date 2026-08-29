<?php

namespace App\Jobs\Orders;

use App\Models\Order;
use App\Services\Orders\OrderClosureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa o cliente sobre adiantamento/sinal recebido, fora da requisicao HTTP.
 * Ver NotifyOrderClosureJob para o motivo.
 */
class NotifyOrderAdvanceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly int $orderId,
        private readonly bool $equipamentoEntregue,
        private readonly float $saldoRestante
    ) {
        $this->onQueue('documents');
    }

    public function handle(OrderClosureService $closureService): void
    {
        $order = Order::query()->find($this->orderId);

        if (! $order instanceof Order) {
            return;
        }

        $closureService->sendAdvanceNotification(
            $order,
            $this->equipamentoEntregue,
            $this->saldoRestante
        );
    }
}
