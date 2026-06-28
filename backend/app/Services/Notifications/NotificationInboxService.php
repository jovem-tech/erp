<?php

namespace App\Services\Notifications;

use App\Models\MobileNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class NotificationInboxService
{
    public function paginateForUser(User $user, bool $onlyUnread, int $perPage): LengthAwarePaginator
    {
        $query = MobileNotification::query()
            ->where('usuario_id', (int) $user->id)
            ->orderByDesc('id');

        if ($onlyUnread) {
            $query->whereNull('lida_em');
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (MobileNotification $notification): array => $this->map($notification)
            )
        );

        return $paginator;
    }

    public function unreadCountForUser(User $user): int
    {
        return (int) MobileNotification::query()
            ->where('usuario_id', (int) $user->id)
            ->whereNull('lida_em')
            ->count();
    }

    public function lastNotificationIdForUser(User $user): int
    {
        return (int) (MobileNotification::query()
            ->where('usuario_id', (int) $user->id)
            ->max('id') ?? 0);
    }

    public function markAsRead(User $user, int $notificationId): ?MobileNotification
    {
        $item = $this->findForUser($user, $notificationId);
        if (! $item instanceof MobileNotification) {
            return null;
        }

        if ($item->lida_em === null) {
            $item->forceFill([
                'lida_em' => Carbon::now(),
            ])->save();
        }

        return $item->refresh();
    }

    public function markAllRead(User $user): array
    {
        $timestamp = Carbon::now();

        $updatedCount = (int) MobileNotification::query()
            ->where('usuario_id', (int) $user->id)
            ->whereNull('lida_em')
            ->update([
                'lida_em' => $timestamp,
                'updated_at' => $timestamp,
            ]);

        return [
            'updated_count' => $updatedCount,
            'updated_at' => $timestamp->toIso8601String(),
            'unread_count' => 0,
        ];
    }

    public function clearRead(User $user): array
    {
        $deletedCount = (int) MobileNotification::query()
            ->where('usuario_id', (int) $user->id)
            ->whereNotNull('lida_em')
            ->delete();

        return [
            'deleted_count' => $deletedCount,
            'unread_count' => $this->unreadCountForUser($user),
        ];
    }

    public function map(MobileNotification $notification): array
    {
        $payload = $this->decodePayload($notification->payload_json);

        return [
            'id' => (int) $notification->id,
            'tipo_evento' => (string) ($notification->tipo_evento ?? ''),
            'titulo' => (string) ($notification->titulo ?? ''),
            'corpo' => (string) ($notification->corpo ?? ''),
            'rota_destino' => $this->normalizeDestinationRoute($notification->rota_destino, $payload),
            'payload' => $payload,
            'lida_em' => $notification->lida_em?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function findForUser(User $user, int $notificationId): ?MobileNotification
    {
        if ($notificationId <= 0) {
            return null;
        }

        return MobileNotification::query()
            ->where('usuario_id', (int) $user->id)
            ->whereKey($notificationId)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(?string $json): ?array
    {
        $raw = trim((string) $json);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function normalizeDestinationRoute(?string $route, ?array $payload = null): ?string
    {
        $value = trim((string) $route);
        $conversaId = (int) ($payload['conversa_id'] ?? 0);
        $osId = (int) ($payload['os_id'] ?? 0);

        if ($value !== '' && preg_match('#^/?conversas/(\d+)$#', $value, $matches)) {
            $conversaId = (int) ($matches[1] ?? 0);
            $value = '';
        }

        if ($value !== '') {
            $path = parse_url($value, PHP_URL_PATH);
            $normalizedPath = is_string($path) ? '/' . trim($path, '/') : '';

            if ($normalizedPath !== '') {
                if (preg_match('#/conversas/(\d+)$#', $normalizedPath, $matches)) {
                    $conversaId = (int) ($matches[1] ?? 0);
                    $value = '';
                }

                if ($osId > 0 && preg_match('#/(?:public/)?os/?$#', $normalizedPath) === 1) {
                    return '/os/' . $osId;
                }

                if (preg_match('#/os/(\d+)$#', $normalizedPath, $matches)) {
                    return '/os/' . (int) ($matches[1] ?? 0);
                }

                if ($conversaId > 0 && preg_match('#/(?:public/)?atendimento-whatsapp/?$#', $normalizedPath) === 1) {
                    return '/atendimento-whatsapp?conversa_id=' . $conversaId;
                }
            }
        }

        if ($conversaId > 0) {
            return '/atendimento-whatsapp?conversa_id=' . $conversaId;
        }

        if ($osId > 0 && ($value === '' || preg_match('#^/?os/?$#', $value) === 1)) {
            return '/os/' . $osId;
        }

        return $value !== '' ? $value : null;
    }
}
