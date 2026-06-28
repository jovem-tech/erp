<?php

namespace App\Events;

use App\Models\Chat\Message;
use App\Services\Chat\ConversationPayloadService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MessageCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly Message $message
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;

        return [
            new PrivateChannel('conta.' . $conversation->conta_id . '.conversas'),
            new PrivateChannel('conversa.' . $this->message->conversa_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => app(ConversationPayloadService::class)->message(
                $this->message->loadMissing('attachments')
            ),
        ];
    }
}
