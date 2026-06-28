<?php

namespace App\Http\Middleware;

use App\Services\ApiClient;
use App\Support\DesktopSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBackendToken
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! DesktopSession::hasToken()) {
            DesktopSession::forget();

            return redirect()->route('login');
        }

        if (DesktopSession::shouldSyncProfile()) {
            $payload = $this->apiClient->me();
            DesktopSession::refreshUser($payload['data'] ?? []);
        }

        return $next($request);
    }
}
