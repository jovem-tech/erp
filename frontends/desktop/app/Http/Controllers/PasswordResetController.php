<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Support\DesktopSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PasswordResetController extends DesktopController
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    public function create(Request $request): View
    {
        return view('auth.forgot-password', [
            'pageTitle' => 'Recuperar senha',
            'email' => old('email', (string) $request->string('email')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ], [], [
            'email' => 'e-mail',
        ]);

        $this->apiClient->requestPasswordResetLink($validated['email']);

        return redirect()
            ->route('login')
            ->with('success', 'Se o e-mail estiver cadastrado, enviaremos um link para redefinir sua senha.');
    }

    public function edit(Request $request, string $token): View|RedirectResponse
    {
        $email = (string) $request->query('email', '');

        if ($email === '') {
            return redirect()
                ->route('password.request')
                ->with('warning', 'Informe o e-mail recebido no link para continuar.');
        }

        return view('auth.reset-password', [
            'pageTitle' => 'Redefinir senha',
            'token' => $token,
            'email' => $email,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [], [
            'token' => 'token',
            'email' => 'e-mail',
            'password' => 'nova senha',
        ]);

        $this->apiClient->resetPassword([
            'token' => $validated['token'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'password_confirmation' => $validated['password_confirmation'] ?? $validated['password'],
        ]);

        DesktopSession::forget();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('success', 'Senha redefinida com sucesso. Faça login novamente para continuar.');
    }
}
