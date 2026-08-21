<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança da API e das páginas HTML públicas (orçamento,
 * documento compartilhado, telas de erro).
 *
 * Essas páginas não carregam NADA de fora — nem script, nem estilo, nem fonte,
 * nem imagem — e todo o conteúdo dinâmico sai escapado por `{{ }}`. Por isso a
 * política aqui é bem mais fechada que a do desktop; só precisa de
 * 'unsafe-inline' por causa de um <script> e alguns <style> embutidos nas
 * próprias views.
 *
 * Nunca sobrescreve um cabeçalho já definido: os endpoints que entregam arquivo
 * (file-manager, anexo de chat, branding) declaram uma política ainda mais
 * restrita, com `sandbox`, e ela tem de prevalecer.
 */
class SecurityHeaders
{
    private const POLICY = "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Vale para JSON também: impede o navegador de reinterpretar uma
        // resposta da API como outro tipo de conteúdo.
        if (! $response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        $isHtml = $contentType === '' || str_contains($contentType, 'text/html');

        if ($isHtml && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::POLICY);
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        return $response;
    }
}
