<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Integrations\EmailIntegrationSettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $versionFile = base_path('../shared/version.php');

        config([
            'app.version' => is_file($versionFile)
                ? (string) require $versionFile
                : '3.0.0',
        ]);

        if ($this->app->environment('testing')) {
            return;
        }

        config([
            'database.default' => 'mysql',
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/chat'));
        app(EmailIntegrationSettingsService::class)->applyRuntimeConfig();
        $this->configureRateLimiting();

        // O sistema-erp e 100% Bearer/Sanctum (sem cookie de sessao); o endpoint padrao
        // /broadcasting/auth do Laravel assume guard "web" se nao for sobrescrito aqui.
        // Ver specs/010-inbox-whatsapp-tempo-real/plan.md, "Ponto critico de autenticacao".
        // CORS desta rota e' tratado globalmente (ver bootstrap/app.php) porque
        // HandleCors como middleware de rota nao intercepta o preflight OPTIONS
        // corretamente quando o verbo OPTIONS nao esta registrado na rota.
        Broadcast::routes(['middleware' => ['auth:sanctum']]);
        // Carregado com require (nao loadRoutesFrom) de proposito: loadRoutesFrom
        // e' ignorado quando as rotas estao cacheadas (route:cache), o que deixaria
        // os canais de broadcasting sem registro e faria /broadcasting/auth retornar
        // 403 em producao. As definicoes de canal (Broadcast::channel) nao fazem
        // parte do cache de rotas, entao precisam ser sempre executadas.
        require base_path('routes/channels.php');

        $rbacAuthorizationService = app(RbacAuthorizationService::class);

        Gate::before(function ($user, string $ability) use ($rbacAuthorizationService): ?bool {
            if (! $user instanceof User || ! str_contains($ability, ':')) {
                return null;
            }

            [$module, $action] = explode(':', $ability, 2);

            return $rbacAuthorizationService->allows($user, $module, $action);
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('password-reset', static function (Request $request): array {
            $email = strtolower(trim((string) $request->input('email', '')));
            $emailKey = $email !== '' ? hash('sha256', $email) : 'missing-email';
            $ip = (string) ($request->ip() ?: 'unknown');

            return [
                // Protege contra abuso em um unico e-mail, sem transformar o IP do
                // desktop/BFF em gargalo global para toda a assistencia.
                Limit::perMinute(5)->by('email:'.$emailKey.'|ip:'.$ip),
                Limit::perMinute(60)->by('ip:'.$ip),
            ];
        });
    }
}
