<?php

namespace App\Http\Middleware;

use App\Models\UserPreference;
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

        // Carrega preferência de tema persistida na primeira request autenticada da sessão
        if (! session()->has('desktop_theme')) {
            $userId = (int) (DesktopSession::user()['id'] ?? 0);
            if ($userId > 0) {
                $theme = UserPreference::where('api_user_id', $userId)->value('desktop_theme');
                session()->put('desktop_theme', $theme ?? 'default');
            }
        }

        return $next($request);
    }
}
