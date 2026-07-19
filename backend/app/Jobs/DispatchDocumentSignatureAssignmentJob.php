<?php

namespace App\Jobs;

use App\Services\Signatures\DocumentSignatureAssignmentNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchDocumentSignatureAssignmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $signatureRequestId
    ) {
        $this->onQueue('default');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'document-signature-assignment:' . $this->signatureRequestId;
    }

    public function handle(DocumentSignatureAssignmentNotifier $notifier): void
    {
        $notifier->dispatchExternal($this->signatureRequestId);
    }
}
