<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\ConstantTimeCredentialCheck;
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

        // Roda o bcrypt sempre, inclusive quando o e-mail nao existe ou nao e
        // administrador — senao o tempo de resposta vira um oraculo de "quem e
        // admin", que e exatamente o reconhecimento para atacar este fluxo.
        $passwordMatches = ConstantTimeCredentialCheck::matches($password, $admin?->senha);

        if (! $authorized || ! $passwordMatches) {
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

    /**
     * Módulo:ação que caracteriza um super administrador quando o fluxo não
     * exige uma habilidade específica.
     *
     * Quem edita grupos de permissão pode conceder qualquer permissão a
     * qualquer um, inclusive a si mesmo — então já é super administrador de
     * fato, e recusar a confirmação dele seria uma trava só de fachada.
     */
    private const SUPER_ADMIN_ABILITY = 'grupos:editar';

    /**
     * Duas portas, de propósito:
     *
     * 1. `perfil = 'admin'`, o marcador legado. Mantido para os fluxos que já
     *    dependem dele continuarem funcionando sem migração de dados.
     * 2. RBAC `grupos:editar`. O campo "Perfil" da tela de usuários é apenas o
     *    NOME do grupo exibido em modo leitura (ver users/_index-scripts.blade.php
     *    no desktop) — quem está num grupo "Super Administrador" tem perfil
     *    diferente de 'admin' e, só com a regra legada, era recusado mesmo
     *    tendo todas as permissões do sistema.
     *
     * Quando o chamador passa uma habilidade específica ($requiredAbility), ela
     * manda sozinha: o fluxo está dizendo exatamente quem pode autorizar.
     */
    private function isAuthorizedAdministrator(User $user, ?string $requiredAbility): bool
    {
        if ($requiredAbility === null) {
            if (mb_strtolower(trim((string) ($user->perfil ?? ''))) === 'admin') {
                return true;
            }

            return $this->allowsAbility($user, self::SUPER_ADMIN_ABILITY);
        }

        return $this->allowsAbility($user, $requiredAbility);
    }

    private function allowsAbility(User $user, string $ability): bool
    {
        $parts = explode(':', $ability, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        return $this->rbac->allows($user, $parts[0], $parts[1]);
    }
}
