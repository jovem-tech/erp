<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\MobileNotification;

/**
 * Despacho de notificacoes do sino para um conjunto de usuarios.
 *
 * Centraliza o padrao usado pelos emissores (orcamentos, baixa/adiantamento,
 * prazos): deduplica ids, ignora usuarios inativos e envia via
 * MobileNotification (que grava em mobile_notifications e transmite em tempo
 * real pelo MobileInboxChannel).
 */
class NotificationDispatchService
{
    /**
     * @param array<int, int|null> $userIds
     * @param array<string, mixed> $payload
     */
    public function toUsers(array $userIds, array $payload): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return;
        }

        User::query()
            ->whereIn('id', $ids)
            ->where('ativo', true)
            ->get()
            ->each(static function (User $user) use ($payload): void {
                $user->notify(new MobileNotification($payload));
            });
    }

    /**
     * Ids de todos os administradores ativos — usado por avisos operacionais
     * sem um ator especifico (ex.: prazos de reparo vencendo).
     *
     * @return array<int, int>
     */
    public function activeAdminIds(): array
    {
        return User::query()
            ->where('ativo', true)
            ->whereRaw("LOWER(TRIM(COALESCE(perfil, ''))) = 'admin'")
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
