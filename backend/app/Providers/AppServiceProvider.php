<?php

namespace App\Providers;

use App\Contracts\Files\FileCatalog;
use App\Contracts\Files\FileStorage;
use App\Contracts\Files\MalwareScanner;
use App\Contracts\Files\PdfThumbnailRenderer;
use App\Enums\Files\FileManagerMode;
use App\Models\OrderDocumentFile;
use App\Models\User;
use App\Observers\OrderDocumentFileObserver;
use App\Services\Agenda\Sources\AgendaSourceRegistry;
use App\Services\Agenda\Sources\CobrancaOsSource;
use App\Services\Agenda\Sources\ContasPagarSource;
use App\Services\Agenda\Sources\ContasReceberSource;
use App\Services\Agenda\Sources\PrazoOsSource;
use App\Services\Agenda\Sources\RetornoPosServicoSource;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Backups\BackupRootRegistry;
use App\Services\Backups\BackupSettingsService;
use App\Services\Backups\Contracts\ProcessRunner;
use App\Services\Backups\SymfonyProcessRunner;
use App\Services\Company\CompanyProfileService;
use App\Services\Files\Authorizers\ChatAttachmentFileAuthorizer;
use App\Services\Files\Authorizers\ConfigurationFileAuthorizer;
use App\Services\Files\Authorizers\EquipmentFileAuthorizer;
use App\Services\Files\Authorizers\OrderFileAuthorizer;
use App\Services\Files\Authorizers\UserProfilePhotoFileAuthorizer;
use App\Services\Files\Authorizers\UserSignatureFileAuthorizer;
use App\Services\Files\EloquentFileCatalog;
use App\Services\Files\FileAuthorizationRegistry;
use App\Services\Files\FileManagerConfiguration;
use App\Services\Files\LocalFileStorage;
use App\Services\Files\NullMalwareScanner;
use App\Services\Files\PopplerPdfThumbnailRenderer;
use App\Services\Integrations\EmailIntegrationSettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FileStorage::class, LocalFileStorage::class);
        $this->app->bind(FileCatalog::class, EloquentFileCatalog::class);
        $this->app->bind(MalwareScanner::class, NullMalwareScanner::class);
        $this->app->bind(PdfThumbnailRenderer::class, PopplerPdfThumbnailRenderer::class);
        $this->app->singleton(FileAuthorizationRegistry::class);

        // Fontes da Agenda. PARA LIGAR UM MODULO NOVO A AGENDA: implemente
        // App\Services\Agenda\Sources\AgendaSource e acrescente a classe a
        // esta tag. Nenhum outro arquivo do motor precisa mudar.
        $this->app->tag([
            ContasPagarSource::class,
            ContasReceberSource::class,
            RetornoPosServicoSource::class,
            PrazoOsSource::class,
            CobrancaOsSource::class,
        ], 'agenda.sources');

        $this->app->singleton(
            AgendaSourceRegistry::class,
            static fn ($app): AgendaSourceRegistry => new AgendaSourceRegistry($app->tagged('agenda.sources'))
        );

        // Costura de teste: backend/phpunit.xml forca sqlite :memory:, entao
        // mysqldump nunca roda na suite - os testes trocam esta ligacao.
        $this->app->bind(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->singleton(BackupRootRegistry::class);
        $this->app->singleton(BackupSettingsService::class);

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
        if ((string) config('file-manager.mode', 'off') !== FileManagerMode::Off->value) {
            app(FileManagerConfiguration::class)->assertValid();
        }

        $fileAuthorizers = app(FileAuthorizationRegistry::class);
        $fileAuthorizers->register('configuration', app(ConfigurationFileAuthorizer::class));
        $fileAuthorizers->register('equipment', app(EquipmentFileAuthorizer::class));
        $fileAuthorizers->register('order', app(OrderFileAuthorizer::class));
        $fileAuthorizers->register('user_signature', app(UserSignatureFileAuthorizer::class));
        $fileAuthorizers->register('user', app(UserProfilePhotoFileAuthorizer::class));
        $fileAuthorizers->register('chat_attachment', app(ChatAttachmentFileAuthorizer::class));
        OrderDocumentFile::observe(app(OrderDocumentFileObserver::class));

        // As paginas HTML publicas da API (orcamento, documentos compartilhados
        // e telas de erro) exibem o mesmo favicon do desktop. Resolver a logo
        // aqui evita repetir a consulta em cada controller.
        View::composer('partials.favicon', function ($view): void {
            try {
                $hasLogo = app(CompanyProfileService::class)->resolveLogoFile() !== null;
            } catch (\Throwable) {
                $hasLogo = false;
            }

            $view->with('erpCompanyHasLogo', $hasLogo);
        });

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

        // Desativar um usuario (ativo = 0) precisa derrubar o acesso dele NA HORA.
        // Sem isto o token Sanctum continua valido ate expirar (SANCTUM_EXPIRATION,
        // 7 dias por padrao): um funcionario desligado seguia com acesso total a
        // API. O UserController tambem revoga os tokens ao desativar; esta checagem
        // e a rede de seguranca que cobre qualquer outro caminho (edicao direta no
        // banco, importacao, script de manutencao).
        Sanctum::authenticateAccessTokensUsing(
            static function ($accessToken, bool $isValid): bool {
                if (! $isValid) {
                    return false;
                }

                $tokenable = $accessToken->tokenable;

                return ! $tokenable instanceof User || (bool) $tokenable->ativo;
            }
        );

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
