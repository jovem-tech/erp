<?php

namespace App\Http\Controllers;

use App\Support\DesktopSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BroadcastAuthController extends DesktopController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'channel_name' => ['required', 'string', 'max:255', 'regex:/^private-[A-Za-z0-9_.-]+$/'],
        ]);

        $token = DesktopSession::token();
        if ($token === null) {
            return response()->json(['message' => 'Sessao expirada.'], 401);
        }

        $baseUrl = rtrim((string) config('services.erp_api.base_url'), '/');
        $backendRoot = preg_replace('#/api/v[0-9]+$#', '', $baseUrl) ?: $baseUrl;

        $response = Http::acceptJson()
            ->asForm()
            ->timeout((int) config('services.erp_api.timeout', 15))
            ->withToken($token)
            ->post($backendRoot . '/broadcasting/auth', $validated);

        $payload = $response->json();
        if (! is_array($payload)) {
            return response()->json(['message' => 'Falha ao autenticar canal em tempo real.'], 502);
        }

        return response()->json($payload, $response->status());
    }
}
