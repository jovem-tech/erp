<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verificação de credenciais de um usuário administrador (perfil=admin) para
 * autorizar ações sensíveis que exigem confirmação extra além da sessão de
 * quem está operando — ex.: cancelar baixa de OS, editar orçamento de OS
 * encerrada. Compartilhado entre esses fluxos para não duplicar lógica de
 * segurança (rate-limit, checagem de senha, log de tentativa inválida).
 */
class AdminCredentialVerifier
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * @return array{ok: bool, admin?: User, error?: string, retry_after?: int}
     */
    public function verify(string $email, string $password, string $throttlePrefix, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $throttleKey = $throttlePrefix . ':' . $email . '|' . $ip;

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return [
                'ok' => false,
                'error' => 'rate_limited',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ];
        }

        $admin = User::query()->where('email', $email)->first();

        if (
            ! $admin instanceof User
            || ! (bool) $admin->ativo
            || mb_strtolower(trim((string) ($admin->perfil ?? ''))) !== 'admin'
            || ! Hash::check($password, (string) $admin->senha)
        ) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            logger()->warning('[AUTH][ADMIN-VERIFY] Credenciais de administrador inválidas', [
                'throttle_prefix' => $throttlePrefix,
                'admin_email' => $email,
                'ip' => $ip,
            ]);

            return ['ok' => false, 'error' => 'invalid'];
        }

        RateLimiter::clear($throttleKey);

        return ['ok' => true, 'admin' => $admin];
    }
}
