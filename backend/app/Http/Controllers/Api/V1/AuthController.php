<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\AuthLoginRequest;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Integrations\EmailIntegrationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class AuthController extends BaseApiController
{
    public function __construct(
        private readonly RbacAuthorizationService $rbacAuthorizationService,
        private readonly EmailIntegrationSettingsService $emailIntegrationSettingsService
    ) {
    }

    public function login(AuthLoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $email = mb_strtolower(trim((string) $validated['email']));
        $password = (string) $validated['password'];
        $deviceName = trim((string) ($validated['device_name'] ?? '')) ?: 'mobile-app';
        $expiresAt = $this->tokenExpiresAt();
        $loginThrottleKey = $this->loginThrottleKey($email, (string) $request->ip());
        $ipThrottleKey = $this->ipThrottleKey((string) $request->ip());

        if ($this->isLoginThrottled($loginThrottleKey, $ipThrottleKey)) {
            return $this->loginThrottledResponse($request, $loginThrottleKey, $ipThrottleKey);
        }

        $user = User::query()
            ->where('email', $email)
            ->first();

        if (
            ! $user
            || ! (bool) $user->ativo
            || ! Hash::check($password, (string) $user->senha)
        ) {
            logger()->warning('[API V1][AUTH] Credenciais invalidas', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            RateLimiter::hit($loginThrottleKey, 60);
            RateLimiter::hit($ipThrottleKey, 60);

            return $this->error(
                'Credenciais invalidas.',
                401,
                'AUTH_INVALID_CREDENTIALS',
                null,
                request: $request
            );
        }

        $user->forceFill([
            'ultimo_acesso' => now(),
        ])->save();

        RateLimiter::clear($loginThrottleKey);
        RateLimiter::clear($ipThrottleKey);

        $token = $user->createToken($deviceName, $this->tokenAbilitiesFor($user), $expiresAt);

        // Set HttpOnly cookie for token storage
        $tokenCookie = Cookie::make(
            config('sanctum.cookie.name', 'erp_token'),
            $token->plainTextToken,
            (int) config('sanctum.cookie.lifetime', 1440),
            config('sanctum.cookie.path', '/'),
            config('sanctum.cookie.domain'),
            config('sanctum.cookie.secure', env('APP_ENV') === 'production'),
            true, // httpOnly - JavaScript cannot access
            false,
            config('sanctum.cookie.sameSite', 'lax')
        );

        logger()->info('[API V1][AUTH] Login efetuado', [
            'user_id' => $user->id,
            'device_name' => $deviceName,
            'ip' => $request->ip(),
        ]);

        return $this->success([
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $this->userPayload($user),
        ], request: $request)->withCookie($tokenCookie);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $email = mb_strtolower(trim((string) $validated['email']));
        $frontend = (string) ($validated['frontend'] ?? 'desktop');

        if (! $this->emailIntegrationSettingsService->operationalMailerAvailable()) {
            logger()->warning('[API V1][AUTH] Canal de redefinicao indisponivel', [
                'frontend' => $frontend,
                'ip' => $request->ip(),
                'mailer' => (string) config('mail.default', ''),
            ]);

            return $this->error(
                'A recuperacao de senha por e-mail esta temporariamente indisponivel. Contate o administrador.',
                503,
                'AUTH_PASSWORD_RESET_CHANNEL_UNAVAILABLE',
                null,
                request: $request
            );
        }

        $user = User::query()
            ->where('email', $email)
            ->where('ativo', true)
            ->first();

        try {
            if ($user instanceof User) {
                $token = Password::broker()->createToken($user);
                $user->sendPasswordResetNotification($token, $frontend);

                logger()->info('[API V1][AUTH] Link de redefinição solicitado', [
                    'user_id' => $user->id,
                    'email' => $email,
                    'frontend' => $frontend,
                    'ip' => $request->ip(),
                ]);
            } else {
                logger()->warning('[API V1][AUTH] Solicitação de redefinição para e-mail inexistente ou inativo', [
                    'email' => $email,
                    'ip' => $request->ip(),
                ]);
            }
        } catch (Throwable $throwable) {
            if ($user instanceof User) {
                Password::broker()->deleteToken($user);
            }

            logger()->error('[API V1][AUTH] Falha ao enviar link de redefinição', [
                'email' => $email,
                'ip' => $request->ip(),
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return $this->error(
                'Não foi possível enviar o link de redefinição agora.',
                500,
                'AUTH_PASSWORD_RESET_SEND_FAILED',
                null,
                request: $request
            );
        }

        return $this->success([
            'reset_link_sent' => true,
            'delivery' => [
                'mode' => 'email',
            ],
        ], request: $request);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $email = mb_strtolower(trim((string) $validated['email']));
        $token = (string) $validated['token'];

        $user = User::query()
            ->where('email', $email)
            ->where('ativo', true)
            ->first();

        if (! $user instanceof User) {
            return $this->error(
                'O link de redefinição é inválido ou expirou.',
                422,
                'AUTH_PASSWORD_RESET_INVALID_TOKEN',
                null,
                request: $request
            );
        }

        $status = Password::broker()->reset([
            'email' => $email,
            'token' => $token,
            'password' => (string) $validated['password'],
            'password_confirmation' => (string) ($validated['password_confirmation'] ?? $validated['password']),
        ], function (User $user, string $password): void {
            $user->forceFill([
                'senha' => Hash::make($password),
            ])->save();

            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error(
                'O link de redefinição é inválido ou expirou.',
                422,
                'AUTH_PASSWORD_RESET_INVALID_TOKEN',
                null,
                request: $request
            );
        }

        logger()->info('[API V1][AUTH] Senha redefinida com sucesso', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        return $this->success([
            'password_reset' => true,
        ], request: $request);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! (bool) $user->ativo) {
            return $this->error(
                'Usuário não autenticado.',
                401,
                'AUTH_REQUIRED',
                null,
                request: $request
            );
        }

        return $this->success(
            $this->userPayload($user),
            request: $request
        );
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user?->currentAccessToken();

        if (! $user instanceof User || ! $currentToken) {
            return $this->error(
                'Usuário não autenticado.',
                401,
                'AUTH_REQUIRED',
                null,
                request: $request
            );
        }

        $deviceName = trim((string) $currentToken->name) ?: 'mobile-app';
        $currentToken->delete();
        $expiresAt = $this->tokenExpiresAt();

        $newToken = $user->createToken($deviceName, $this->tokenAbilitiesFor($user), $expiresAt);

        // Set HttpOnly cookie with new token
        $tokenCookie = Cookie::make(
            config('sanctum.cookie.name', 'erp_token'),
            $newToken->plainTextToken,
            (int) config('sanctum.cookie.lifetime', 1440),
            config('sanctum.cookie.path', '/'),
            config('sanctum.cookie.domain'),
            config('sanctum.cookie.secure', env('APP_ENV') === 'production'),
            true, // httpOnly
            false,
            config('sanctum.cookie.sameSite', 'lax')
        );

        logger()->info('[API V1][AUTH] Token renovado', [
            'user_id' => $user->id,
            'device_name' => $deviceName,
            'ip' => $request->ip(),
        ]);

        return $this->success([
            'access_token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ], request: $request)->withCookie($tokenCookie);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user?->currentAccessToken();

        if (! $user instanceof User || ! $currentToken) {
            return $this->error(
                'Usuário não autenticado.',
                401,
                'AUTH_REQUIRED',
                null,
                request: $request
            );
        }

        $currentToken->delete();

        // Clear HttpOnly token cookie
        $tokenCookie = Cookie::forget(
            config('sanctum.cookie.name', 'erp_token'),
            config('sanctum.cookie.path', '/'),
            config('sanctum.cookie.domain'),
        );

        logger()->info('[API V1][AUTH] Logout efetuado', [
            'user_id' => $user->id,
            'device_name' => $currentToken->name,
            'ip' => $request->ip(),
        ]);

        return $this->success([
            'revoked' => true,
        ], request: $request)->withCookie($tokenCookie);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! (bool) $user->ativo) {
            return $this->error(
                'Usuário não autenticado.',
                401,
                'AUTH_REQUIRED',
                null,
                request: $request
            );
        }

        $validated = $request->validated();
        $user->forceFill([
            'nome' => trim((string) $validated['nome']),
        ])->save();

        return $this->success(
            $this->userPayload($user->refresh()),
            request: $request
        );
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user?->currentAccessToken();

        if (! $user instanceof User || ! (bool) $user->ativo || ! $currentToken) {
            return $this->error(
                'Usuário não autenticado.',
                401,
                'AUTH_REQUIRED',
                null,
                request: $request
            );
        }

        $validated = $request->validated();
        if (! Hash::check((string) $validated['current_password'], (string) $user->senha)) {
            return $this->error(
                'A senha atual não confere.',
                422,
                'AUTH_INVALID_CURRENT_PASSWORD',
                null,
                request: $request
            );
        }

        $revokedTokens = (int) $user->tokens()->count();
        $user->forceFill([
            'senha' => Hash::make((string) $validated['password']),
        ])->save();

        $user->tokens()->delete();

        return $this->success(
            [
                'requires_relogin' => true,
                'revoked_tokens' => $revokedTokens,
            ],
            request: $request
        );
    }

    private function isLoginThrottled(string $loginThrottleKey, string $ipThrottleKey): bool
    {
        return RateLimiter::tooManyAttempts($loginThrottleKey, 5)
            || RateLimiter::tooManyAttempts($ipThrottleKey, 20);
    }

    private function loginThrottleKey(string $email, string $ip): string
    {
        return 'auth-login:' . ($email !== '' ? $email : 'unknown') . '|' . $ip;
    }

    private function ipThrottleKey(string $ip): string
    {
        return 'auth-login-ip:' . $ip;
    }

    private function loginThrottledResponse(Request $request, string $loginThrottleKey, string $ipThrottleKey): JsonResponse
    {
        $retryAfter = max(
            RateLimiter::availableIn($loginThrottleKey),
            RateLimiter::availableIn($ipThrottleKey)
        );

        return $this->error(
            'Muitas tentativas de login. Aguarde um pouco e tente novamente.',
            429,
            'AUTH_LOGIN_RATE_LIMITED',
            [
                'retry_after' => $retryAfter,
            ],
            request: $request
        );
    }

    private function tokenExpiresAt(): \Illuminate\Support\Carbon
    {
        $expirationMinutes = max(1, (int) config('sanctum.expiration', 10080));

        return now()->addMinutes($expirationMinutes);
    }

    /**
     * @return array<int, string>
     */
    private function tokenAbilitiesFor(User $user): array
    {
        $rbac = $this->rbacAuthorizationService->resolveForUser($user);
        $permissions = is_array($rbac['permissions'] ?? null) ? $rbac['permissions'] : [];
        $abilities = [];

        foreach ($permissions as $module => $actions) {
            if (! is_string($module) || ! is_array($actions)) {
                continue;
            }

            foreach ($actions as $action) {
                if (is_string($action) && trim($action) !== '') {
                    $abilities[] = $module . ':' . trim($action);
                }
            }
        }

        $abilities = array_values(array_unique($abilities));

        return $abilities !== [] ? $abilities : ['authenticated'];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $rbac = $this->rbacAuthorizationService->resolveForUser($user);

        return [
            'id' => (int) $user->id,
            'nome' => (string) ($user->nome ?? ''),
            'email' => (string) ($user->email ?? ''),
            'perfil' => (string) ($user->perfil ?? ''),
            'grupo_id' => (int) ($user->grupo_id ?? 0),
            'group' => $rbac['group'] ?? null,
            'modules' => $rbac['modules'] ?? [],
            'permissions' => $rbac['permissions'] ?? [],
            'foto' => (string) ($user->foto ?? ''),
            'ativo' => (bool) $user->ativo,
            'ultimo_acesso' => $user->ultimo_acesso?->toIso8601String(),
        ];
    }
}
