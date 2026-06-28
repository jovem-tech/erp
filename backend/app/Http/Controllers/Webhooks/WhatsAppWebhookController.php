<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Channels\Whatsapp\IncomingMessageService;
use App\Services\Integrations\IntegrationSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly IntegrationSettingsService $integrationSettingsService,
        private readonly IncomingMessageService $incomingMessageService
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $expectedToken = trim($this->integrationSettingsService->webhookToken());
        $providedToken = $this->resolveProvidedToken($request);

        if ($expectedToken !== '' && ! hash_equals($expectedToken, $providedToken)) {
            return ApiResponse::error(
                'Token do webhook inválido.',
                403,
                'WHATSAPP_WEBHOOK_FORBIDDEN',
                null,
                [],
                $request
            );
        }

        $isSelfCheck = filter_var($request->header('X-Webhook-Self-Check', false), FILTER_VALIDATE_BOOL)
            || filter_var($request->input('self_check', false), FILTER_VALIDATE_BOOL);

        logger()->info('[API V1][WHATSAPP] Webhook recebido', [
            'self_check' => $isSelfCheck,
            'source' => $request->input('source'),
            'ip' => $request->ip(),
            'keys' => array_values(array_filter(array_keys($request->all()), static fn (string $key): bool => ! in_array($key, ['message', 'payload', 'media'], true))),
        ]);

        if (! $isSelfCheck) {
            $this->processIncomingMessage($request);
        }

        return ApiResponse::success([
            'received' => true,
            'self_check' => $isSelfCheck,
            'payload' => Arr::except($request->all(), ['message', 'payload', 'media']),
        ], request: $request);
    }

    /**
     * Repassa o payload para a Central de Atendimento (specs/010-inbox-whatsapp-tempo-real).
     * Falhas aqui MUST NOT quebrar o ack do webhook (a Evolution API reenviaria em loop) —
     * apenas registradas em log para investigacao.
     */
    private function processIncomingMessage(Request $request): void
    {
        try {
            $this->incomingMessageService->handle($request->all());
        } catch (Throwable $exception) {
            logger()->warning('[API V1][WHATSAPP] Falha ao processar mensagem na Central de Atendimento', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveProvidedToken(Request $request): string
    {
        $candidates = [
            $request->header('X-Webhook-Token'),
            $request->header('X-Api-Token'),
            $request->header('X-Api-Key'),
            $request->header('apikey'),
            $request->bearerToken(),
        ];

        foreach ($candidates as $candidate) {
            $token = trim((string) $candidate);

            if ($token === '') {
                continue;
            }

            if (str_starts_with(mb_strtolower($token), 'bearer ')) {
                $token = trim(substr($token, 7));
            }

            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }
}
