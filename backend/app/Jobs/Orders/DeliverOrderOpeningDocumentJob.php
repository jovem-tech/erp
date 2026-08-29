<?php

namespace App\Jobs\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Gera o PDF de abertura e o entrega ao cliente FORA da requisicao HTTP.
 *
 * Antes isso acontecia dentro do POST /orders: geracao do PDF em dois formatos
 * mais ate' duas tentativas de WhatsApp (inbox e, se ela falhar, envio direto),
 * cada uma com timeout de 20s no IntegrationSettingsService. Na pratica a
 * abertura de OS — a operacao mais frequente da assistencia — podia segurar um
 * worker do PHP-FPM por dezenas de segundos, e o pool do desktop e' pequeno.
 *
 * A fila e' `documents`, a mesma que o Supervisor ja consome junto de `default`
 * (ver supervisor/sistema-erp-queue-worker.conf e o fallback agendado em
 * routes/console.php).
 */
class DeliverOrderOpeningDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Espacamento generoso entre tentativas: quando o provedor de WhatsApp esta
     * fora, insistir em segundos so' queima tentativa.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly int $orderId,
        private readonly int $actorId
    ) {
        $this->onQueue('documents');
    }

    public function handle(OrderWorkflowService $workflowService): void
    {
        $order = Order::query()->find($this->orderId);
        $actor = User::query()->find($this->actorId);

        if (! $order instanceof Order || ! $actor instanceof User) {
            // OS ou usuario removidos entre o enfileiramento e o consumo: nao ha
            // o que entregar, e falhar aqui so' geraria ruido de retentativa.
            return;
        }

        $workflowService->deliverOpeningDocument($order, $actor);
    }
}
