<?php

namespace App\Jobs;

use App\Models\Chat\Message;
use App\Services\Channels\Whatsapp\WhatsappMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsappMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $messageId
    ) {
    }

    public function handle(WhatsappMessagingService $messagingService): void
    {
        $message = Message::query()->findOrFail($this->messageId);
        $messagingService->sendPendingMessage($message);
    }
}
