<?php

namespace App\Events;

use App\Models\Chat\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ConversationStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $statusAnterior
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conta.' . $this->conversation->conta_id . '.conversas'),
            new PrivateChannel('conversa.' . $this->conversation->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.status_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversa_id' => $this->conversation->id,
            'status_anterior' => $this->statusAnterior,
            'status_novo' => $this->conversation->status,
        ];
    }
}
