<?php

namespace App\Notifications\Channels;

use App\Events\NotificationCreated;
use App\Models\MobileNotification as MobileNotificationModel;
use Illuminate\Notifications\Notification;
use Throwable;

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

        $model = MobileNotificationModel::query()->create([
            'usuario_id' => $userId,
            'tipo_evento' => $tipoEvento !== '' ? $tipoEvento : 'system.info',
            'titulo' => $titulo !== '' ? $titulo : 'Atualização',
            'corpo' => $corpo !== '' ? $corpo : 'Você tem uma nova atualização.',
            'rota_destino' => $rotaDestino !== '' ? $rotaDestino : null,
            'payload_json' => $payload !== [] ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);

        // Tempo real: avisa o sino do usuario sem exigir reload. Falha de
        // broadcast (Reverb fora do ar etc.) nunca pode quebrar a acao que
        // gerou a notificacao — a linha ja esta gravada e aparece no proximo
        // carregamento normalmente.
        try {
            broadcast(new NotificationCreated($userId, [
                'id' => (int) $model->id,
                'tipo_evento' => (string) $model->tipo_evento,
                'titulo' => (string) $model->titulo,
                'corpo' => (string) $model->corpo,
                'rota_destino' => $model->rota_destino,
                'icon' => (string) ($payload['icon'] ?? ''),
            ]));
        } catch (Throwable $exception) {
            logger()->warning('[NOTIFICACOES] Falha ao transmitir notificacao em tempo real', [
                'usuario_id' => $userId,
                'notificacao_id' => (int) $model->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
