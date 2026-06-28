<?php

namespace App\Notifications\Channels;

use App\Models\MobileNotification as MobileNotificationModel;
use Illuminate\Notifications\Notification;

class MobileInboxChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toMobileInbox')) {
            return;
        }

        $userId = (int) ($notifiable->id ?? 0);
        if ($userId <= 0) {
            return;
        }

        /** @var mixed $rawPayload */
        $rawPayload = $notification->toMobileInbox($notifiable);
        if (! is_array($rawPayload)) {
            return;
        }

        $tipoEvento = trim((string) ($rawPayload['tipo_evento'] ?? ''));
        $titulo = trim((string) ($rawPayload['titulo'] ?? ''));
        $corpo = trim((string) ($rawPayload['corpo'] ?? ''));
        $rotaDestino = trim((string) ($rawPayload['rota_destino'] ?? ''));
        $payload = is_array($rawPayload['payload'] ?? null) ? $rawPayload['payload'] : [];

        MobileNotificationModel::query()->create([
            'usuario_id' => $userId,
            'tipo_evento' => $tipoEvento !== '' ? $tipoEvento : 'system.info',
            'titulo' => $titulo !== '' ? $titulo : 'Atualização',
            'corpo' => $corpo !== '' ? $corpo : 'Você tem uma nova atualização.',
            'rota_destino' => $rotaDestino !== '' ? $rotaDestino : null,
            'payload_json' => $payload !== [] ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }
}
