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

    public static function hasAuthorizationSnapshot(): bool
    {
        $user = self::user();

        if ($user === []) {
            return false;
        }

        $modules = $user['modules'] ?? null;
        $permissions = $user['permissions'] ?? null;

        if (! is_array($modules) || ! is_array($permissions)) {
            return false;
        }

        return $modules !== [] || $permissions !== [];
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
        if (! self::hasAuthorizationSnapshot()) {
            return true;
        }

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
    public static function store(string $token, array $user, bool $rememberMe = false): void
    {
        session([
            self::SESSION_KEY => [
                'token' => $token,
                'user' => $user,
                'synced_at' => time(),
                'remember_me' => $rememberMe,
                'last_activity' => time(),
            ],
        ]);
    }

    /**
     * Sessão marcada como "Manter-me conectado" no login: sobrevive ao
     * fechamento do navegador e não é derrubada pelo timeout de inatividade
     * (ver isIdleTimedOut()). Fora isso, é uma sessão comum que morre com o
     * navegador — ver config/session.php:expire_on_close.
     */
    public static function rememberMe(): bool
    {
        // Verificação dupla: se o admin desativar "Manter-me conectado" em
        // Configurações do Sistema > Sessão e Segurança, sessões já marcadas
        // como remember_me=true (criadas antes da mudança) param de valer
        // imediatamente, sem precisar esperar o usuário logar de novo.
        if (! SessionSecuritySettings::rememberMeEnabled()) {
            return false;
        }

        return (bool) session(self::SESSION_KEY . '.remember_me', false);
    }

    public static function touchActivity(): void
    {
        session([self::SESSION_KEY . '.last_activity' => time()]);
    }

    /**
     * Timeout de inatividade próprio (não é o 'lifetime' nativo do Laravel,
     * que agora é um teto de storage generoso para acomodar o remember-me —
     * ver config/session.php). Sessões sem "Manter-me conectado" marcado são
     * encerradas após N minutos sem nenhuma requisição autenticada.
     */
    public static function isIdleTimedOut(): bool
    {
        if (self::rememberMe()) {
            return false;
        }

        $lastActivity = (int) session(self::SESSION_KEY . '.last_activity', 0);

        if ($lastActivity <= 0) {
            return false;
        }

        // Leitura direta (não via config('session.desktop_idle_timeout')): esse
        // config só é atualizado uma vez, no boot da aplicação — correto a
        // cada requisição real em PHP-FPM, mas em testes (várias chamadas
        // reaproveitando o mesmo app já bootado) ficaria preso no valor de
        // quando o app subiu. SessionSecuritySettings::idleTimeoutMinutes()
        // sempre lê o valor atual do banco.
        $idleTimeoutMinutes = SessionSecuritySettings::idleTimeoutMinutes();

        return (time() - $lastActivity) > ($idleTimeoutMinutes * 60);
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
        session()->forget('desktop_theme');
    }
}
