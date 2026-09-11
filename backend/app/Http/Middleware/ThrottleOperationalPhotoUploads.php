<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleOperationalPhotoUploads
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasFile('fotos') && ! $request->hasFile('novo_equipamento_fotos')) {
            return $next($request);
        }

        $identity = $request->user()?->getAuthIdentifier() ?? 'guest';
        $key = 'operational-photo-upload:'.hash('sha256', $identity.'|'.(string) $request->ip());
        $maxAttempts = 8;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);
            $response = ApiResponse::error(
                'Limite de envios de fotos atingido. Aguarde e tente novamente.',
                429,
                'PHOTO_RATE_LIMITED',
                null,
                ['retry_after' => $retryAfter],
                $request,
            );
            $response->headers->set('Retry-After', (string) $retryAfter);
            $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
            $response->headers->set('X-RateLimit-Remaining', '0');

            return $response;
        }

        RateLimiter::hit($key, 60);
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set(
            'X-RateLimit-Remaining',
            (string) max(0, $maxAttempts - RateLimiter::attempts($key)),
        );

        return $response;
    }
}
