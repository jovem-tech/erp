<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiRequestException;
use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class PublicDocumentSignatureController extends DesktopController
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    public function show(string $token): View
    {
        try {
            $response = $this->apiClient->guestGet('/public/document-signatures/' . $token);

            return view('signatures.public', [
                'pageTitle' => 'Assinar documento',
                'token' => $token,
                'signatureRequest' => $response['data'] ?? [],
                'signed' => false,
            ]);
        } catch (Throwable $exception) {
            $message = $exception instanceof ApiRequestException
                ? $exception->getMessage()
                : 'Não foi possível abrir este link de assinatura.';

            return view('signatures.public', [
                'pageTitle' => 'Link indisponível',
                'token' => $token,
                'signatureRequest' => [],
                'signed' => false,
                'publicError' => $message,
            ]);
        }
    }

    public function store(Request $request, string $token): View
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'signature_data' => ['required', 'string', 'max:3000000'],
            'consent' => ['accepted'],
        ], [], [
            'name' => 'nome',
            'signature_data' => 'assinatura',
            'consent' => 'consentimento',
        ]);

        try {
            $response = $this->apiClient->guestPost('/public/document-signatures/' . $token, [
                'name' => (string) $validated['name'],
                'signature_data' => (string) $validated['signature_data'],
                'consent' => true,
            ]);

            return view('signatures.public', [
                'pageTitle' => 'Documento assinado',
                'token' => $token,
                'signatureRequest' => $response['data'] ?? [],
                'signed' => true,
            ]);
        } catch (Throwable $exception) {
            return view('signatures.public', [
                'pageTitle' => 'Assinar documento',
                'token' => $token,
                'signatureRequest' => [],
                'signed' => false,
                'publicError' => $exception instanceof ApiRequestException
                    ? $exception->getMessage()
                    : 'Não foi possível registrar a assinatura agora.',
            ]);
        }
    }
}
