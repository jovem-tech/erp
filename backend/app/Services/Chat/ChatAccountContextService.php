<?php

namespace App\Services\Chat;

use App\Models\Chat\Account;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChatAccountContextService
{
    /**
     * @return array<int, int>
     */
    public function allowedAccountIds(): array
    {
        $configuredIds = $this->configuredAllowedAccountIds();
        $query = Account::query()->orderBy('id');

        if ($configuredIds !== []) {
            return $query
                ->whereIn('id', $configuredIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        $existingIds = $query
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        if (count($existingIds) === 1) {
            return $existingIds;
        }

        if (count($existingIds) > 1) {
            Log::warning('[CHAT][ACCESS] Multiplas contas sem allowlist explicita; acesso fail-closed acionado.', [
                'account_ids' => $existingIds,
            ]);
        }

        return [];
    }

    public function defaultAccountId(): ?int
    {
        $allowedIds = $this->allowedAccountIds();
        $configuredDefaultId = max(0, (int) config('chat.default_account_id', 0));

        if ($configuredDefaultId > 0 && in_array($configuredDefaultId, $allowedIds, true)) {
            return $configuredDefaultId;
        }

        return count($allowedIds) === 1 ? $allowedIds[0] : null;
    }

    public function defaultAccount(): Account
    {
        $accountId = $this->defaultAccountId();

        if ($accountId === null) {
            throw new RuntimeException(
                'Nenhuma conta de atendimento autorizada para a operacao atual. Configure CHAT_ALLOWED_ACCOUNT_IDS e CHAT_DEFAULT_ACCOUNT_ID quando houver multiplas contas.'
            );
        }

        return Account::query()->lockForUpdate()->findOrFail($accountId);
    }

    /**
     * @return array<int, int>
     */
    private function configuredAllowedAccountIds(): array
    {
        $configured = config('chat.allowed_account_ids', []);

        if (! is_array($configured)) {
            return [];
        }

        $allowedIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): int => (int) $value,
            $configured
        ), static fn (int $value): bool => $value > 0)));

        sort($allowedIds);

        return $allowedIds;
    }
}
