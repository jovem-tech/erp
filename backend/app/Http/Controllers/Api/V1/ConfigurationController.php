<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\UpdateCompanyProfileRequest;
use App\Http\Requests\Api\V1\UpdateIntegrationsRequest;
use App\Services\Company\CompanyProfileService;
use App\Services\Integrations\EmailIntegrationSettingsService;
use App\Services\Integrations\GoogleIntegrationSettingsService;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Integrations\PaymentIntegrationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class ConfigurationController extends BaseApiController
{
    public function __construct(
        private readonly IntegrationSettingsService $integrationSettingsService,
        private readonly PaymentIntegrationSettingsService $paymentIntegrationSettingsService,
        private readonly EmailIntegrationSettingsService $emailIntegrationSettingsService,
        private readonly GoogleIntegrationSettingsService $googleIntegrationSettingsService,
        private readonly CompanyProfileService $companyProfileService
    ) {
    }

    public function companyProfile(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:visualizar');

        return $this->success(
            $this->companyProfileService->payload(),
            request: $request
        );
    }

    public function publicCompanyBranding(Request $request): JsonResponse
    {
        return $this->success(
            $this->companyProfileService->publicBranding(),
            request: $request
        );
    }

    public function updateCompanyProfile(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        $result = $this->companyProfileService->save($request->safe()->except([
            'empresa_logo',
            'login_background_image',
        ]));

        $logo = $request->file('empresa_logo');
        $loginBackground = $request->file('login_background_image');
        $mediaChanged = false;

        if ($logo instanceof UploadedFile && $logo->isValid()) {
            $this->companyProfileService->storeLogo($logo);
            $mediaChanged = true;
        }

        if ($loginBackground instanceof UploadedFile && $loginBackground->isValid()) {
            $this->companyProfileService->storeLoginBackground($loginBackground);
            $mediaChanged = true;
        }

        if ($mediaChanged) {
            $result = $this->companyProfileService->payload();
        }

        return $this->success($result, request: $request);
    }

    public function companyLogo(Request $request): Response|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $file = $this->companyProfileService->resolveLogoFile();
        if ($file === null) {
            return $this->error(
                'Logo da empresa nao configurada.',
                404,
                'COMPANY_LOGO_NOT_FOUND',
                null,
                request: $request
            );
        }

        return response()->file($file['absolute_path'], array_merge([
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
        ], $this->brandingCacheHeaders($file['absolute_path'], public: false)));
    }

    public function publicCompanyLogo(Request $request): Response|JsonResponse
    {
        $file = $this->companyProfileService->resolveLogoFile();
        if ($file === null) {
            return $this->error(
                'Logo da empresa nao configurada.',
                404,
                'COMPANY_LOGO_NOT_FOUND',
                null,
                request: $request
            );
        }

        return response()->file($file['absolute_path'], array_merge([
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
        ], $this->brandingCacheHeaders($file['absolute_path'], public: true)));
    }

    public function publicLoginBackground(Request $request): Response|JsonResponse
    {
        $file = $this->companyProfileService->resolveLoginBackgroundFile();
        if ($file === null) {
            return $this->error(
                'Imagem de fundo do login nao configurada.',
                404,
                'LOGIN_BACKGROUND_NOT_FOUND',
                null,
                request: $request
            );
        }

        return response()->file($file['absolute_path'], array_merge([
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
        ], $this->brandingCacheHeaders($file['absolute_path'], public: true)));
    }

    /**
     * Sem isso, a resposta herda o "Cache-Control: no-cache, private" padrao
     * do Symfony (Response::prepare, disparado pela sessao do Laravel), entao
     * logo/fundo de login sao rebaixados a cada navegacao dentro do sistema.
     * max-age moderado (nao "immutable") porque a URL nao muda ao trocar a
     * imagem — o admin que atualizar a marca pode precisar de um refresh.
     *
     * @return array<string, string>
     */
    private function brandingCacheHeaders(string $absolutePath, bool $public): array
    {
        return [
            'Cache-Control' => ($public ? 'public' : 'private') . ', max-age=86400, must-revalidate',
            'Last-Modified' => gmdate('D, d M Y H:i:s', filemtime($absolutePath) ?: time()) . ' GMT',
        ];
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
                'gateway' => $this->integrationSettingsService->gatewayStatus($request->all(), (string) $request->input('provider', '')),
            ],
            request: $request
        );
    }

    public function gatewayQr(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:visualizar');

        return $this->success(
            [
                'gateway' => $this->integrationSettingsService->gatewayQr($request->all(), (string) $request->input('provider', '')),
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
                    (string) $request->input('provider', ''),
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
                    (string) $request->input('provider', '')
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
                    (string) $request->input('provider', '')
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
