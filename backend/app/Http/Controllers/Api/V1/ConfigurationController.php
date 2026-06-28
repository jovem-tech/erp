<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\UpdateIntegrationsRequest;
use App\Services\Integrations\EmailIntegrationSettingsService;
use App\Services\Integrations\GoogleIntegrationSettingsService;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Integrations\PaymentIntegrationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationController extends BaseApiController
{
    public function __construct(
        private readonly IntegrationSettingsService $integrationSettingsService,
        private readonly PaymentIntegrationSettingsService $paymentIntegrationSettingsService,
        private readonly EmailIntegrationSettingsService $emailIntegrationSettingsService,
        private readonly GoogleIntegrationSettingsService $googleIntegrationSettingsService
    ) {
    }

    public function integrations(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:visualizar');

        return $this->success(
            [
                'integration' => $this->integrationPayload(),
            ],
            request: $request
        );
    }

    public function updateIntegrations(UpdateIntegrationsRequest $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        $validated = $request->validated();

        $this->integrationSettingsService->save($validated);
        $this->paymentIntegrationSettingsService->save($validated);
        $this->emailIntegrationSettingsService->save($validated);
        $this->googleIntegrationSettingsService->save($validated);

        return $this->success(
            [
                'integration' => $this->integrationPayload(),
            ],
            request: $request
        );
    }

    public function testPaymentConnection(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success(
            [
                'result' => $this->paymentIntegrationSettingsService->testConnection(
                    $request->all(),
                    (string) $request->input('provider', '')
                ),
            ],
            request: $request
        );
    }

    public function sendEmailTest(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success(
            [
                'result' => $this->emailIntegrationSettingsService->sendTest($request->all()),
            ],
            request: $request
        );
    }

    public function testConnection(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success(
            [
                'result' => $this->integrationSettingsService->testConnection($request->all()),
            ],
            request: $request
        );
    }

    public function sendTestMessage(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success(
            [
                'result' => $this->integrationSettingsService->sendTestMessage($request->all()),
            ],
            request: $request
        );
    }

    public function selfCheckInbound(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success(
            [
                'result' => $this->integrationSettingsService->selfCheckInbound($request->all()),
            ],
            request: $request
        );
    }

    public function gatewayStatus(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:visualizar');

        return $this->success(
            [
                'gateway' => $this->integrationSettingsService->gatewayStatus($request->all(), (string) $request->query('provider', '')),
            ],
            request: $request
        );
    }

    public function gatewayQr(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:visualizar');

        return $this->success(
            [
                'gateway' => $this->integrationSettingsService->gatewayQr($request->all(), (string) $request->query('provider', '')),
            ],
            request: $request
        );
    }

    public function gatewayRestart(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success(
            [
                'gateway' => $this->integrationSettingsService->gatewayRestart(
                    $request->all(),
                    (string) ($request->input('provider') ?? $request->query('provider', '')),
                    filter_var($request->input('clean', false), FILTER_VALIDATE_BOOL)
                ),
            ],
            request: $request
        );
    }

    public function gatewayLogout(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success(
            [
                'gateway' => $this->integrationSettingsService->gatewayLogout(
                    $request->all(),
                    (string) ($request->input('provider') ?? $request->query('provider', ''))
                ),
            ],
            request: $request
        );
    }

    public function gatewayStart(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success(
            [
                'gateway' => $this->integrationSettingsService->gatewayStart(
                    $request->all(),
                    (string) ($request->input('provider') ?? $request->query('provider', ''))
                ),
            ],
            request: $request
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationPayload(): array
    {
        return array_merge(
            $this->integrationSettingsService->payload(),
            [
                'payments' => $this->paymentIntegrationSettingsService->payload(),
                'email' => $this->emailIntegrationSettingsService->payload(),
                'google' => $this->googleIntegrationSettingsService->payload(),
            ]
        );
    }
}
