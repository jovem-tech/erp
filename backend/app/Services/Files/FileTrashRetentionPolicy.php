<?php

namespace App\Services\Files;

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FileTrashRetentionPolicy
{
    private const DAYS_KEY = 'file_manager_trash_retention_days';

    private const CONFIGURED_BY_KEY = 'file_manager_trash_retention_configured_by';

    /** @return array{days: int, enabled: bool, configured_by: int|null, configured_at: string|null, allowed_days: array<int, int>} */
    public function settings(): array
    {
        $rows = collect();
        if (Schema::hasTable('configuracoes')) {
            $rows = Configuration::query()
                ->whereIn('chave', [self::DAYS_KEY, self::CONFIGURED_BY_KEY])
                ->get(['chave', 'valor', 'updated_at'])
                ->keyBy('chave');
        }

        $daysRow = $rows->get(self::DAYS_KEY);
        $configuredByRow = $rows->get(self::CONFIGURED_BY_KEY);
        $days = $this->normalizeDays($daysRow?->valor ?? config('file-manager.retention.trash_days', 30));
        $configuredBy = (int) ($configuredByRow?->valor ?? 0);

        return [
            'days' => $days,
            'enabled' => $days > 0,
            'configured_by' => $configuredBy > 0 ? $configuredBy : null,
            'configured_at' => $daysRow?->updated_at?->toIso8601String(),
            'allowed_days' => $this->allowedDays(),
        ];
    }

    /** @return array{days: int, enabled: bool, configured_by: int|null, configured_at: string|null, allowed_days: array<int, int>} */
    public function save(int $days, User $actor): array
    {
        $days = $this->normalizeDays($days, true);
        if (! Schema::hasTable('configuracoes')) {
            throw new \RuntimeException('Tabela de configurações indisponível para persistir a retenção.');
        }

        DB::transaction(function () use ($days, $actor): void {
            $this->upsert(self::DAYS_KEY, (string) $days, 'numero');
            $this->upsert(self::CONFIGURED_BY_KEY, (string) $actor->id, 'numero');
        });

        return $this->settings();
    }

    public function cutoff(?Carbon $now = null): ?Carbon
    {
        $days = $this->settings()['days'];

        return $days > 0 ? ($now ?? now())->copy()->subDays($days) : null;
    }

    /** @return array<int, int> */
    public function allowedDays(): array
    {
        $days = array_map('intval', (array) config('file-manager.retention.allowed_trash_days', [0, 7, 30, 90]));
        $days = array_values(array_unique(array_filter($days, static fn (int $day): bool => $day >= 0)));
        sort($days);

        return $days !== [] ? $days : [0, 7, 30, 90];
    }

    private function normalizeDays(mixed $value, bool $strict = false): int
    {
        $days = (int) $value;
        if (in_array($days, $this->allowedDays(), true)) {
            return $days;
        }
        if ($strict) {
            throw new \InvalidArgumentException('Prazo de retenção inválido.');
        }

        $fallback = (int) config('file-manager.retention.trash_days', 30);

        return in_array($fallback, $this->allowedDays(), true) ? $fallback : 30;
    }

    private function upsert(string $key, string $value, string $type): void
    {
        Configuration::query()->updateOrCreate(
            ['chave' => $key],
            ['valor' => $value, 'tipo' => $type]
        );
    }
}
