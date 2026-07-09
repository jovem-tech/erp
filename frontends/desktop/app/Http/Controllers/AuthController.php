<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiRequestException;
use App\Services\ApiClient;
use App\Services\CompanyProfileService;
use App\Support\DesktopNavigation;
use App\Support\DesktopSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends DesktopController
{
    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly CompanyProfileService $companyProfileService
    ) {
    }

    public function create(): View|RedirectResponse
    {
        if (DesktopSession::hasToken()) {
            return redirect()->route(DesktopNavigation::firstAllowedRouteName());
        }

        return view('auth.login', [
            'branding' => $this->companyProfileService->branding(),
            'systemVersion' => $this->systemVersion(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [], [
            'email' => 'e-mail',
            'password' => 'senha',
        ]);

        try {
            $payload = $this->apiClient->login(
                $validated['email'],
                $validated['password'],
                'desktop-laravel'
            );
        } catch (ApiRequestException $exception) {
            $message = $exception->statusCode() >= 500
                ? 'Nao foi possivel entrar no momento. Tente novamente em instantes.'
                : $exception->getMessage();

            $redirect = redirect()
                ->route('login')
                ->withInput($request->except('password'))
                ->with('error', $message);

            if ($exception->statusCode() < 500 && $exception->details() !== null) {
                $redirect->with('api_error_details', $exception->details());
            }

            return $redirect;
        }

        $request->session()->regenerate();
        DesktopSession::store(
            (string) ($payload['data']['access_token'] ?? ''),
            $payload['data']['user'] ?? []
        );

        return redirect()
            ->intended(route(DesktopNavigation::firstAllowedRouteName()))
            ->with('success', 'Login realizado com sucesso.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        return $this->performLogout($request, false);
    }

    public function destroyAndForget(Request $request): RedirectResponse
    {
        return $this->performLogout($request, true);
    }

    private function performLogout(Request $request, bool $forgetRememberedState): RedirectResponse
    {
        if (DesktopSession::hasToken()) {
            try {
                $this->apiClient->logout();
            } catch (ApiAuthenticationException) {
                // Token expirado no backend; a sessao local ainda sera descartada.
            }
        }

        DesktopSession::forget();

        if ($forgetRememberedState) {
            $request->session()->forget([
                'desktop_ui.search_scope',
                'desktop_ui.search_query',
            ]);
        }

        $request->session()->regenerateToken();

        $message = $forgetRememberedState
            ? 'Sessão encerrada e login esquecido com sucesso.'
            : 'Sessão encerrada com sucesso.';

        return redirect()
            ->route('login')
            ->with('success', $message);
    }

    private function systemVersion(): string
    {
        $versionFile = dirname(base_path(), 2) . DIRECTORY_SEPARATOR . 'VERSION';
        if (! is_file($versionFile)) {
            return '';
        }

        return trim((string) file_get_contents($versionFile));
    }
}
