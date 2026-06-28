<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Middleware para forçar HTTPS em produção.
 *
 * Registrado globalmente em bootstrap/app.php via $middleware->append(...).
 * Não tem efeito em ambiente local/testing (ver guarda abaixo).
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Não forçar HTTPS em desenvolvimento
        if (app()->environment('local', 'testing')) {
            return $next($request);
        }

        // Em produção, sempre usar HTTPS
        if (! $request->secure() && config('app.env') === 'production') {
            return redirect(
                str_replace('http://', 'https://', $request->url()),
                301
            );
        }

        // Adicionar headers de segurança
        $response = $next($request);

        // Forçar HTTPS por 1 ano (31536000 segundos)
        $response->header(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains; preload'
        );

        return $response;
    }
}
