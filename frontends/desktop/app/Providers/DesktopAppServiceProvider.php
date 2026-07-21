<?php

namespace App\Providers;

use App\Services\CompanyProfileService;
use App\Services\SearchService;
use App\Support\DesktopNavigation;
use App\Support\DesktopSession;
use App\Support\SessionSecuritySettings;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class DesktopAppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Protocolo de versionamento (VERSIONING.md): o arquivo VERSION na raiz
        // do monorepo e a fonte unica da verdade (MAJOR.MINOR.PATCH.HOTFIX).
        // shared/version.php (3 posicoes) permanece como fallback.
        $versionFile = base_path('../../VERSION');
        $version = is_file($versionFile)
            ? trim((string) file_get_contents($versionFile))
            : '';

        if ($version === '') {
            $legacyVersionFile = base_path('../../shared/version.php');
            $version = is_file($legacyVersionFile)
                ? (string) require $legacyVersionFile
                : '3.0.0';
        }

        config([
            'app.version' => $version,
        ]);

        $this->app->singleton(DesktopNavigation::class, fn (): DesktopNavigation => new DesktopNavigation());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Precisa rodar aqui (bootstrap da aplicação), não em um middleware:
        // o teto de storage da sessão (session.lifetime) tem que estar certo
        // ANTES do StartSession ler/gravar a sessão da requisição, e nesse
        // ponto ainda não sabemos nada sobre a sessão em si — só o valor
        // configurado pelo admin em Configurações do Sistema > Sessão e
        // Segurança (ou o padrão do .env, se a tabela ainda não existir).
        SessionSecuritySettings::applyToRuntimeConfig();

        View::composer('*', function ($view): void {
            $appName = trim((string) config('app.name', ));
            $version = (string) config('app.version', '3.0.0');

            $view->with('desktopUser', DesktopSession::user());
            $view->with('desktopNavigation', app(DesktopNavigation::class)->sections());
            $view->with('desktopSearchScopes', app(SearchService::class)->scopes());
            $view->with('desktopNotifications', [
                'items' => [],
                'unread_count' => 0,
                'pagination' => [],
            ]);
            $view->with('desktopSystemFooter', [
                'version' => $version !== '' ? $version : '3.0.0',
                'copyright' => sprintf('(c) %s %s', date('Y'), $appName !== '' ? $appName : 'Sistema ERP Desktop'),
                'developed_by' => 'Jovem Tech',
            ]);

            $view->with('desktopCompanyBranding', DesktopSession::hasToken()
                ? app(CompanyProfileService::class)->branding()
                : ['name' => 'Sistema ERP', 'has_logo' => false]);

            // Guard de "fechar o navegador = deslogar" para sessões SEM
            // "Manter-me conectado": ativo apenas quando há sessão e ela não é
            // lembrada. justLoggedIn evita falso-positivo no primeiro
            // carregamento logo após o login (flash de uma requisição só).
            $view->with('desktopSessionGuard', [
                'active' => DesktopSession::hasToken() && ! DesktopSession::rememberMe(),
                'justLoggedIn' => (bool) session('session_just_started', false),
            ]);
        });
    }
}
