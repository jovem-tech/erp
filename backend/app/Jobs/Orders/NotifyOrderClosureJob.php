<?php

namespace App\Jobs\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderClosureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa o cliente sobre o encerramento da OS (ou sobre um adiantamento) fora da
 * requisicao HTTP.
 *
 * Mesmo motivo dos outros jobs de notificacao: o envio tenta o inbox e cai para
 * o gateway direto, duas chamadas externas de ate' 20s. Encerrar OS e' operacao
 * de balcao, com o cliente esperando na frente do operador — e' justamente onde
 * a tela travada custa mais caro.
 */
class NotifyOrderClosureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    /**
     * @param array<int, array<string, mixed>> $recebimentos
     */
    public function __construct(
        private readonly int $orderId,
        private readonly string $statusAplicadoCodigo,
        private readonly string $dataEntrega,
        private readonly string $observacaoEncerramento,
        private readonly array $recebimentos,
        private readonly float $saldoRestante,
        private readonly float $valorTitulo,
        private readonly int $actorId
    ) {
        $this->onQueue('documents');
    }

    public function handle(OrderClosureService $closureService): void
    {
        $order = Order::query()->find($this->orderId);
        $actor = User::query()->find($this->actorId);

        if (! $order instanceof Order || ! $actor instanceof User) {
            return;
        }

        $closureService->sendClosureNotification(
            $order,
            $this->statusAplicadoCodigo,
            $this->dataEntrega,
            $this->observacaoEncerramento,
            $this->recebimentos,
            $this->saldoRestante,
            $this->valorTitulo,
            $actor
        );
    }
}
