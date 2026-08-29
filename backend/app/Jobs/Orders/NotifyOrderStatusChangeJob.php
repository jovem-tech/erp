<?php

namespace App\Jobs\Orders;

use App\Models\Order;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa o cliente por WhatsApp que o status da OS mudou, FORA da requisicao.
 *
 * O envio tenta a Central de Atendimento (inbox) e, se ela falhar, cai para o
 * envio direto pelo gateway — duas chamadas HTTP externas de ate' 20s cada.
 * Dentro da requisicao isso fazia uma simples troca de status segurar um worker
 * do PHP-FPM por ate' 40s, com o operador olhando para a tela travada.
 *
 * O evento no historico da OS continua sendo registrado em TODOS os desfechos
 * (sucesso, falha, cliente sem telefone) — a garantia nao mudou, so' passou a
 * ser gravada pelo worker em vez do processo web.
 */
class NotifyOrderStatusChangeJob implements ShouldQueue
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
        private readonly string $newStatus,
        private readonly ?string $observacao = null,
        private readonly ?string $mensagemCliente = null
    ) {
        $this->onQueue('documents');
    }

    public function handle(OrderWorkflowService $workflowService): void
    {
        $order = Order::query()->find($this->orderId);

        if (! $order instanceof Order) {
            return;
        }

        $workflowService->sendStatusChangeClientNotification(
            $order,
            $this->newStatus,
            $this->observacao,
            $this->mensagemCliente
        );
    }
}
