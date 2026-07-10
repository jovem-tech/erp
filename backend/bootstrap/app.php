<?php

use App\Support\ApiResponse;
use App\Http\Middleware\AuthorizeModuleAction;
use App\Http\Middleware\ForceHttps;
use Dotenv\Dotenv;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$basePath = dirname(__DIR__);

if (PHP_SAPI !== 'cli' && is_file($basePath.'/.env')) {
    // Recarrega a .env do backend para evitar vazamento de variáveis do desktop
    // quando os dois Laravel compartilham o mesmo Apache/PHP no XAMPP.
    Dotenv::createUnsafeMutable($basePath)->load();
}

return Application::configure(basePath: $basePath)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // channels.php NAO e registrado aqui de proposito: o helper withRouting()
        // chamaria Broadcast::routes() com middleware "web" (padrao) depois do boot dos
        // service providers, sobrescrevendo a customizacao para auth:sanctum feita em
        // AppServiceProvider::boot() (ver specs/010-inbox-whatsapp-tempo-real/plan.md).
        // routes/channels.php e carregado manualmente la, com o middleware correto.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global (nao so' web/api): rotas registradas fora desses grupos, como
        // /broadcasting/auth (Central de Atendimento, specs/010-inbox-whatsapp-tempo-real)
        // e /webhooks/whatsapp, tambem precisam responder preflight OPTIONS corretamente
        // para origens diferentes (ex.: frontends/chat em outra porta). Como middleware de
        // rota especifica, HandleCors nao intercepta OPTIONS quando o verbo nao esta
        // registrado na rota — precisa estar no topo do stack global.
        $middleware->prepend(HandleCors::class);
        $middleware->redirectGuestsTo(null);
        $middleware->validateCsrfTokens(except: [
            'webhooks/whatsapp',
        ]);
        $middleware->alias([
            'rbac' => AuthorizeModuleAction::class,
        ]);
        $middleware->append(ForceHttps::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApiRequest = static function (Request $request): bool {
            return $request->is('api/*') || $request->expectsJson();
        };

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isApiRequest) {
            if (!$isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'Usuário não autenticado.',
                401,
                'AUTH_REQUIRED',
                null,
                [],
                $request
            );
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($isApiRequest) {
            if (!$isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'Acesso negado para esta operação.',
                403,
                'FORBIDDEN',
                null,
                [],
                $request
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) use ($isApiRequest) {
            if (!$isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'Falha na validação dos dados enviados.',
                422,
                'VALIDATION_ERROR',
                $exception->errors(),
                [],
                $request
            );
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) use ($isApiRequest) {
            if (!$isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'Rota não encontrada.',
                404,
                'API_NOT_FOUND',
                null,
                [],
                $request
            );
        });

        // Fallback para qualquer exceção não tratada acima (bugs, falhas de banco,
        // timeouts, etc). Evita que o handler padrão do Laravel devolva trace/arquivo
        // de origem no JSON quando APP_DEBUG=true. O log completo continua sendo
        // gravado normalmente via report() antes deste callback rodar.
        $exceptions->render(function (Throwable $exception, Request $request) use ($isApiRequest) {
            if (!$isApiRequest($request)) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            if ($status === 429) {
                return ApiResponse::error(
                    'Muitas tentativas. Aguarde alguns instantes e tente novamente.',
                    429,
                    'RATE_LIMITED',
                    null,
                    [],
                    $request
                );
            }

            $message = $status >= 500
                ? 'Ocorreu um erro inesperado. Tente novamente em instantes.'
                : ($exception->getMessage() ?: 'Não foi possível processar a solicitação.');

            return ApiResponse::error(
                $message,
                $status,
                $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_ERROR',
                null,
                [],
                $request
            );
        });
    })->create();
