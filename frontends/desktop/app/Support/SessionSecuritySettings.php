<?php

namespace App\Support;

use App\Models\SessionSecuritySetting;
use Throwable;

class SessionSecuritySettings
{
    /**
     * Sem cache estático de propósito: isso é um valor por-processo em
     * PHP-FPM (uma requisição = um processo, custo de mais uma leitura numa
     * tabela de 1 linha é irrelevante), mas em PHPUnit todos os testes rodam
     * no MESMO processo — um cache estático vazaria a configuração de um
     * teste para o próximo mesmo com RefreshDatabase resetando as tabelas.
     */
    public static function current(): SessionSecuritySetting
    {
        try {
            $setting = SessionSecuritySetting::first();
        } catch (Throwable) {
            $setting = null;
        }

        return $setting ?? new SessionSecuritySetting([
            'idle_timeout_minutes' => (int) config('session.desktop_idle_timeout', 120),
            'remember_me_enabled' => true,
            'remember_me_lifetime_days' => (int) round(((int) config('session.lifetime', 43200)) / 1440),
            'warn_on_close' => true,
        ]);
    }

    public static function idleTimeoutMinutes(): int
    {
        return (int) self::current()->idle_timeout_minutes;
    }

    public static function rememberMeEnabled(): bool
    {
        return (bool) self::current()->remember_me_enabled;
    }

    public static function rememberMeLifetimeDays(): int
    {
        return (int) self::current()->remember_me_lifetime_days;
    }

    public static function warnOnClose(): bool
    {
        return (bool) self::current()->warn_on_close;
    }

    /**
     * @param array{idle_timeout_minutes: int, remember_me_enabled: bool, remember_me_lifetime_days: int} $attributes
     */
    public static function update(array $attributes): SessionSecuritySetting
    {
        $setting = SessionSecuritySetting::first() ?? new SessionSecuritySetting();
        $setting->fill($attributes);
        $setting->save();

        return $setting;
    }

    /**
     * Aplica as configurações persistidas em config('session.*') no início da
     * requisição — precisa acontecer antes do StartSession ler/gravar a
     * sessão, por isso é chamado a partir de AppServiceProvider::boot() e não
     * de um middleware (que rodaria tarde demais para o teto de storage).
     */
    public static function applyToRuntimeConfig(): void
    {
        $settings = self::current();

        config([
            'session.desktop_idle_timeout' => $settings->idle_timeout_minutes,
            'session.lifetime' => max(60, $settings->remember_me_lifetime_days * 1440),
        ]);
    }
}
