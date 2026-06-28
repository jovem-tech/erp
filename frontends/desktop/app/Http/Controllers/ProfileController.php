<?php

namespace App\Http\Controllers;

use App\Services\ProfileService;
use App\Support\DesktopSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends DesktopController
{
    public function __construct(
        private readonly ProfileService $profileService
    ) {
    }

    public function show(): View
    {
        return view('profile.show', [
            'pageTitle' => 'Meu Perfil',
            'profile' => DesktopSession::user(),
        ]);
    }

    public function edit(): View
    {
        return view('profile.edit', [
            'pageTitle' => 'Configurações do perfil',
            'profile' => DesktopSession::user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:100'],
        ], [], [
            'nome' => 'nome',
        ]);

        $payload = $this->profileService->updateProfile([
            'nome' => $validated['nome'],
        ]);

        DesktopSession::refreshUser($payload);

        return redirect()
            ->route('profile.show')
            ->with('success', 'Perfil atualizado com sucesso.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'min:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [], [
            'current_password' => 'senha atual',
            'password' => 'nova senha',
        ]);

        $this->profileService->updatePassword([
            'current_password' => $validated['current_password'],
            'password' => $validated['password'],
            'password_confirmation' => $validated['password_confirmation'] ?? $validated['password'],
        ]);

        DesktopSession::forget();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('success', 'Senha alterada com sucesso. Faça login novamente para continuar.');
    }
}
