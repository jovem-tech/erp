<?php

namespace App\Http\Controllers;

use App\Services\ProfileService;
use App\Services\OrderService;
use App\Support\DesktopSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ProfileController extends DesktopController
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly OrderService $orderService
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
        $signature = [];
        $pendingSignatures = [];
        try {
            $signature = $this->profileService->signatureStatus();
            $pendingSignatures = $this->orderService->pendingDocumentSignatures();
        } catch (\Throwable $exception) {
            report($exception);
        }

        return view('profile.edit', [
            'pageTitle' => 'Configurações do perfil',
            'profile' => DesktopSession::user(),
            'signature' => $signature,
            'pendingSignatures' => $pendingSignatures,
        ]);
    }

    public function updateSignature(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'signature_origin' => ['required', 'string', 'in:upload,desenho'],
            'current_password' => ['required', 'string', 'max:200'],
            'signature_file' => ['nullable', 'required_if:signature_origin,upload', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'signature_data' => ['nullable', 'required_if:signature_origin,desenho', 'string', 'max:3000000'],
        ], [], [
            'signature_origin' => 'origem da assinatura',
            'signature_file' => 'arquivo da assinatura',
            'signature_data' => 'assinatura desenhada',
            'current_password' => 'senha atual',
        ]);

        $this->profileService->saveSignature([
            'origin' => (string) $validated['signature_origin'],
            'current_password' => (string) $validated['current_password'],
            'signature_data' => (string) ($validated['signature_data'] ?? ''),
        ], $request->file('signature_file'));

        $sessionUser = DesktopSession::user();
        $sessionUser['assinatura_cadastrada'] = true;
        DesktopSession::refreshUser($sessionUser);

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Assinatura cadastrada e vinculada à sua identidade.');
    }

    public function signatureImage(): Response
    {
        $file = $this->profileService->signatureImage();

        return response($file['body'], $file['status'])->withHeaders($file['headers']);
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
