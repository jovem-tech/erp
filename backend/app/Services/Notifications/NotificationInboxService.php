<?php

namespace App\Services\Notifications;

use App\Models\MobileNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class NotificationInboxService
{
    public const BOX_ALL = 'all';

    public const BOX_OPERATIONAL = 'operational';

    public const BOX_CORRESPONDENCE = 'correspondence';

    public function paginateForUser(User $user, bool $onlyUnread, int $perPage, string $box = self::BOX_ALL): LengthAwarePaginator
    {
        $query = $this->queryForUser($user, $box)->orderByDesc('id');

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

    public function unreadCountForUser(User $user, string $box = self::BOX_ALL): int
    {
        return (int) $this->queryForUser($user, $box)
            ->whereNull('lida_em')
            ->count();
    }

    public function lastNotificationIdForUser(User $user, string $box = self::BOX_ALL): int
    {
        return (int) ($this->queryForUser($user, $box)
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

    public function markAllRead(User $user, string $box = self::BOX_ALL): array
    {
        $timestamp = Carbon::now();

        $updatedCount = (int) $this->queryForUser($user, $box)
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

    public function clearRead(User $user, string $box = self::BOX_ALL): array
    {
        $deletedCount = (int) $this->queryForUser($user, $box)
            ->whereNotNull('lida_em')
            ->delete();

        return [
            'deleted_count' => $deletedCount,
            'unread_count' => $this->unreadCountForUser($user, $box),
        ];
    }

    public function map(MobileNotification $notification): array
    {
        $payload = $this->decodePayload($notification->payload_json);

        return [
            'id' => (int) $notification->id,
            'tipo_evento' => (string) ($notification->tipo_evento ?? ''),
            'caixa' => $this->boxForEvent((string) ($notification->tipo_evento ?? '')),
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

    public function normalizeBox(string $box): string
    {
        $normalized = strtolower(trim($box));

        return in_array($normalized, [self::BOX_OPERATIONAL, self::BOX_CORRESPONDENCE], true)
            ? $normalized
            : self::BOX_ALL;
    }

    private function queryForUser(User $user, string $box): Builder
    {
        $query = MobileNotification::query()->where('usuario_id', (int) $user->id);

        return $this->applyBox($query, $this->normalizeBox($box));
    }

    private function applyBox(Builder $query, string $box): Builder
    {
        if ($box === self::BOX_CORRESPONDENCE) {
            return $query->where(static function (Builder $builder): void {
                $builder->where('tipo_evento', 'like', 'message.%')
                    ->orWhere('tipo_evento', 'like', 'document.%');
            });
        }

        if ($box === self::BOX_OPERATIONAL) {
            return $query
                ->where('tipo_evento', 'not like', 'message.%')
                ->where('tipo_evento', 'not like', 'document.%');
        }

        return $query;
    }

    private function boxForEvent(string $eventType): string
    {
        $normalized = strtolower(trim($eventType));

        return str_starts_with($normalized, 'message.') || str_starts_with($normalized, 'document.')
            ? self::BOX_CORRESPONDENCE
            : self::BOX_OPERATIONAL;
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
