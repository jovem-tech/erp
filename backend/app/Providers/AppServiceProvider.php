<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
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

        // O sistema-erp e 100% Bearer/Sanctum (sem cookie de sessao); o endpoint padrao
        // /broadcasting/auth do Laravel assume guard "web" se nao for sobrescrito aqui.
        // Ver specs/010-inbox-whatsapp-tempo-real/plan.md, "Ponto critico de autenticacao".
        // CORS desta rota e' tratado globalmente (ver bootstrap/app.php) porque
        // HandleCors como middleware de rota nao intercepta o preflight OPTIONS
        // corretamente quando o verbo OPTIONS nao esta registrado na rota.
        Broadcast::routes(['middleware' => ['auth:sanctum']]);
        $this->loadRoutesFrom(base_path('routes/channels.php'));

        $rbacAuthorizationService = app(RbacAuthorizationService::class);

        Gate::before(function ($user, string $ability) use ($rbacAuthorizationService): ?bool {
            if (! $user instanceof User || ! str_contains($ability, ':')) {
                return null;
            }

            [$module, $action] = explode(':', $ability, 2);

            return $rbacAuthorizationService->allows($user, $module, $action);
        });
    }
}
