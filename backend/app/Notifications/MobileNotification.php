<?php

namespace App\Notifications;

use App\Notifications\Channels\MobileInboxChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MobileNotification extends Notification
{
    use Queueable;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly array $payload
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [MobileInboxChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toMobileInbox(object $notifiable): array
    {
        $kind = trim((string) ($this->payload['kind'] ?? ''));
        $title = trim((string) ($this->payload['title'] ?? ''));
        $body = trim((string) ($this->payload['body'] ?? ''));
        $route = trim((string) ($this->payload['route'] ?? ''));

        return [
            'tipo_evento' => $kind !== '' ? $kind : 'system.info',
            'titulo' => $title !== '' ? $title : 'Atualização',
            'corpo' => $body !== '' ? $body : 'Você tem uma nova atualização.',
            'rota_destino' => $route !== '' ? $route : null,
            'payload' => $this->payload,
        ];
    }
}
