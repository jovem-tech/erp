<?php

namespace App\Jobs;

use App\Services\Orders\OrderDocumentCenterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOrderDocumentSendJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $sendId
    ) {
    }

    public function handle(OrderDocumentCenterService $documentCenterService): void
    {
        $documentCenterService->processQueuedSend($this->sendId);
    }
}
