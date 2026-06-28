<?php

namespace App\Support;

class DesktopSession
{
    private const SESSION_KEY = 'desktop_auth';

    public static function hasToken(): bool
    {
        return self::token() !== null;
    }

    public static function token(): ?string
    {
        $token = session(self::SESSION_KEY . '.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function user(): array
    {
        $user = session(self::SESSION_KEY . '.user', []);

        return is_array($user) ? $user : [];
    }

    /**
     * @return string[]
     */
    public static function modules(): array
    {
        $modules = self::user()['modules'] ?? [];

        return is_array($modules) ? array_values(array_filter($modules, 'is_string')) : [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function permissions(): array
    {
        $permissions = self::user()['permissions'] ?? [];

        return is_array($permissions) ? $permissions : [];
    }

    public static function can(string $module, string $action = 'visualizar'): bool
    {
        $permissions = self::permissions();

        if (! array_key_exists($module, $permissions) || ! is_array($permissions[$module])) {
            return false;
        }

        return in_array($action, $permissions[$module], true);
    }

    public static function shouldSyncProfile(): bool
    {
        $lastSynced = session(self::SESSION_KEY . '.synced_at');
        $ttl = (int) config('services.erp_api.profile_sync_ttl', 300);

        if (! is_int($lastSynced) && ! ctype_digit((string) $lastSynced)) {
            return true;
        }

        return (time() - (int) $lastSynced) >= $ttl;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function store(string $token, array $user): void
    {
        session([
            self::SESSION_KEY => [
                'token' => $token,
                'user' => $user,
                'synced_at' => time(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function refreshUser(array $user): void
    {
        session([
            self::SESSION_KEY . '.user' => $user,
            self::SESSION_KEY . '.synced_at' => time(),
        ]);
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
