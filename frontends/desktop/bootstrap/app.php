<?php

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Http\Middleware\EnsureBackendToken;
use App\Http\Middleware\EnsureRoutePermission;
use App\Support\DesktopNavigation;
use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$basePath = dirname(__DIR__);

if (PHP_SAPI !== 'cli' && is_file($basePath.'/.env')) {
    // Recarrega a .env do desktop para neutralizar valores herdados do backend
    // quando os dois Laravel compartilham o mesmo Apache/PHP no XAMPP.
    Dotenv::createUnsafeMutable($basePath)->load();
}

return Application::configure(basePath: $basePath)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'desktop.auth' => EnsureBackendToken::class,
            'desktop.permission' => EnsureRoutePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Evita que a senha de admin (confirmação de "Cancelar baixa") fique
        // gravada em session('_old_input') se a validação nativa do
        // Request::validate() falhar antes mesmo de chegar na API.
        $exceptions->dontFlash('admin_password');

        $exceptions->render(function (ApiAuthenticationException $exception, Request $request) {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()->route('login');
        });

        $exceptions->render(function (ApiAuthorizationException $exception) {
            return redirect()
                ->route(DesktopNavigation::firstAllowedRouteName())
                ->with('error', $exception->getMessage() ?: 'Você não tem permissão para acessar este recurso.');
        });

        $exceptions->render(function (ApiRequestException $exception, Request $request) {
            $redirect = back();

            if (! url()->previous() || url()->previous() === $request->fullUrl()) {
                $redirect = redirect()->route(DesktopNavigation::firstAllowedRouteName());
            }

            return $redirect
                ->withInput($request->except(['password', 'admin_password']))
                ->with('error', $exception->getMessage())
                ->with('api_error_details', $exception->details() ?? []);
        });

        // Fallback para qualquer exceção não tratada acima, apenas em requisições
        // AJAX/fetch (as que aparecem no console do navegador). Evita que o JSON de
        // erro devolva trace/arquivo/linha quando APP_DEBUG=true. O log completo
        // continua sendo gravado em storage/logs normalmente via report().
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! ($request->expectsJson() || $request->ajax())) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            $message = $status >= 500
                ? 'Ocorreu um erro inesperado. Tente novamente em instantes.'
                : ($exception->getMessage() ?: 'Não foi possível processar a solicitação.');

            return response()->json([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'code' => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_ERROR',
                    'message' => $message,
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ], $status, [], JSON_UNESCAPED_UNICODE);
        });
    })->create();
