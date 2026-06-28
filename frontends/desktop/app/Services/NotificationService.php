<?php

namespace App\Services;

use App\Support\DesktopSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, unread_count: int, pagination: array<string, mixed>}
     */
    public function paginate(array $filters = []): array
    {
        if (! DesktopSession::hasToken()) {
            return [
                'items' => [],
                'unread_count' => 0,
                'pagination' => [],
            ];
        }

        $response = $this->apiClient->get('/notifications', $filters);

        $items = array_map(
            fn (array $notification): array => $this->normalize($notification),
            $response['data']['items'] ?? []
        );

        return [
            'items' => $items,
            'unread_count' => (int) ($response['data']['unread_count'] ?? 0),
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, unread_count: int, pagination: array<string, mixed>}
     */
    public function summary(int $perPage = 6): array
    {
        $cacheKey = $this->summaryCacheKey($perPage);

        if ($cacheKey === null) {
            return $this->paginate([
                'per_page' => $perPage,
            ]);
        }

        return Cache::remember($cacheKey, now()->addSeconds(30), function () use ($perPage): array {
            return $this->paginate([
                'per_page' => $perPage,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function open(string $id): array
    {
        $response = $this->apiClient->patch('/notifications/' . $id . '/read');
        $notification = $response['data']['notification'] ?? [];
        $this->forgetSummaryCache();

        return [
            'notification' => $this->normalize(is_array($notification) ? $notification : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function markAllRead(): array
    {
        $response = $this->apiClient->patch('/notifications/read-all');
        $this->forgetSummaryCache();

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $notification): array
    {
        $route = trim((string) ($notification['rota_destino'] ?? ''));
        $url = $this->resolveUrl($route);
        $icon = trim((string) ($notification['icone'] ?? ''));

        return [
            'id' => (string) ($notification['id'] ?? ''),
            'tipo' => (string) ($notification['tipo'] ?? 'notificacao'),
            'titulo' => (string) ($notification['titulo'] ?? 'Notificação'),
            'corpo' => (string) ($notification['corpo'] ?? ''),
            'rota_destino' => $route,
            'url' => $url,
            'icone' => $icon !== '' ? 'bi bi-' . Str::of($icon)->trim()->replace('_', '-')->toString() : 'bi bi-bell',
            'dados' => is_array($notification['dados'] ?? null) ? $notification['dados'] : [],
            'lida_em' => $this->normalizeTimestamp($notification['lida_em'] ?? null),
            'criada_em' => $this->normalizeTimestamp($notification['criada_em'] ?? null),
            'criada_em_humano' => $this->humanizeTimestamp($notification['criada_em'] ?? null),
        ];
    }

    private function resolveUrl(string $route): string
    {
        if ($route === '') {
            return route('notifications.index');
        }

        if (Str::startsWith($route, '/')) {
            return url($route);
        }

        if (filter_var($route, FILTER_VALIDATE_URL) !== false) {
            $parsedRoute = parse_url($route);
            $parsedApp = parse_url((string) config('app.url'));

            if (($parsedRoute['host'] ?? null) !== null && ($parsedApp['host'] ?? null) !== null) {
                if ((string) $parsedRoute['host'] === (string) $parsedApp['host']) {
                    return $route;
                }
            }
        }

        return route('notifications.index');
    }

    private function normalizeTimestamp(mixed $timestamp): ?string
    {
        if (! is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function humanizeTimestamp(mixed $timestamp): string
    {
        if (! is_string($timestamp) || trim($timestamp) === '') {
            return 'Agora';
        }

        try {
            $carbon = Carbon::parse($timestamp);

            return $carbon->diffForHumans();
        } catch (\Throwable) {
            return 'Agora';
        }
    }

    private function summaryCacheKey(int $perPage = 6): ?string
    {
        $userId = (int) (DesktopSession::user()['id'] ?? 0);

        if ($userId <= 0 || $perPage <= 0) {
            return null;
        }

        return 'desktop_notifications_summary:' . $userId . ':' . $perPage;
    }

    private function forgetSummaryCache(int $perPage = 6): void
    {
        $cacheKey = $this->summaryCacheKey($perPage);

        if ($cacheKey !== null) {
            Cache::forget($cacheKey);
        }
    }
}
