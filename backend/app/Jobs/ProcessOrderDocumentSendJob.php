<?php

namespace App\Jobs;

use App\Models\OrderDocumentSend;
use App\Services\Orders\OrderDocumentCenterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOrderDocumentSendJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $sendId
    ) {
    }

    public function handle(OrderDocumentCenterService $documentCenterService): void
    {
        $documentCenterService->processQueuedSend($this->sendId);
    }

    public function failed(?Throwable $exception): void
    {
        OrderDocumentSend::query()
            ->whereKey($this->sendId)
            ->where('status', 'na_fila')
            ->update([
                'status' => 'erro',
                'erro_sanitizado' => 'O processador de envios ficou indisponível. Tente enviar o documento novamente.',
                'updated_at' => now(),
            ]);

        Log::error('Falha definitiva ao processar envio documental.', [
            'send_id' => $this->sendId,
            'exception_type' => $exception !== null ? $exception::class : null,
        ]);
    }
}
