<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Verificação de credenciais de um usuário administrador para autorizar ações
 * sensíveis que exigem confirmação extra além da sessão de
 * quem está operando — ex.: cancelar baixa de OS, editar orçamento de OS
 * encerrada. Compartilhado entre esses fluxos para não duplicar lógica de
 * segurança (RBAC, rate-limit, checagem de senha e log de tentativa inválida).
 */
class AdminCredentialVerifier
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(private readonly RbacAuthorizationService $rbac) {}

    /**
     * @return array{ok: bool, admin?: User, error?: string, retry_after?: int}
     */
    public function verify(
        string $email,
        string $password,
        string $throttlePrefix,
        string $ip,
        ?string $requiredAbility = null
    ): array
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

        $authorized = $admin instanceof User
            && (bool) $admin->ativo
            && $this->isAuthorizedAdministrator($admin, $requiredAbility);

        if (! $authorized || ! Hash::check($password, (string) $admin->senha)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            // Uma falha operacional no canal de log nunca pode transformar uma
            // credencial recusada em HTTP 500. A autorização permanece negada.
            try {
                Log::warning('[AUTH][ADMIN-VERIFY] Credenciais de administrador inválidas', [
                    'throttle_prefix' => $throttlePrefix,
                    'admin_email' => $email,
                    'ip' => $ip,
                ]);
            } catch (Throwable) {
                // O rate limit continua ativo e nenhuma ação sensível é executada.
            }

            return ['ok' => false, 'error' => 'invalid'];
        }

        RateLimiter::clear($throttleKey);

        return ['ok' => true, 'admin' => $admin];
    }

    private function isAuthorizedAdministrator(User $user, ?string $requiredAbility): bool
    {
        if ($requiredAbility === null) {
            return mb_strtolower(trim((string) ($user->perfil ?? ''))) === 'admin';
        }

        $parts = explode(':', $requiredAbility, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        return $this->rbac->allows($user, $parts[0], $parts[1]);
    }
}
