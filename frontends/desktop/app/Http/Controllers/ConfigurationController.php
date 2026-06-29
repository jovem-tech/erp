<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ConfigurationController extends DesktopController
{
    public function __construct(
        private readonly ConfigurationService $configurationService
    ) {
    }

    public function integrations(): View
    {
        return view('configurations.integrations', [
            'pageTitle' => 'Configurações',
            'integration' => $this->configurationService->integrations(),
        ]);
    }

    public function system(): View
    {
        return view('configurations.system', [
            'pageTitle' => 'Configurações do Sistema',
        ]);
    }

    public function help(): View
    {
        return view('configurations.help', [
            'pageTitle' => 'Ajuda das integrações',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $payload = $this->integrationPayload($request)
            + $this->paymentPayload($request)
            + $this->emailPayload($request)
            + $this->googlePayload($request);

        try {
            $this->configurationService->updateIntegrations($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()
                ->route('configurations.integrations.index')
                ->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos das integrações.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível salvar as integrações agora. Tente novamente.');
        }

        return redirect()
            ->route('configurations.integrations.index')
            ->with('success', 'Integrações salvas com sucesso.');
    }

    public function testConnection(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->testConnection($this->integrationPayload($request));
        });
    }

    public function sendTestMessage(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->sendTestMessage($this->integrationPayload($request) + [
                'mensagem' => trim((string) $request->input('mensagem', '')),
                'telefone' => trim((string) $request->input('telefone', '')),
            ]);
        });
    }

    public function selfCheckInbound(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->selfCheckInbound($this->integrationPayload($request));
        });
    }

    public function testPaymentConnection(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->testPaymentConnection($this->paymentPayload($request) + [
                'provider' => trim((string) $request->input('provider', '')),
            ]);
        });
    }

    public function sendEmailTest(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->sendEmailTest($this->emailPayload($request) + [
                'email' => trim((string) $request->input('email', '')),
            ]);
        });
    }

    public function gatewayStatus(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayStatus($this->integrationPayload($request) + [
                'provider' => (string) $request->query('provider', $request->input('provider', '')),
            ]);
        });
    }

    public function gatewayQr(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayQr($this->integrationPayload($request) + [
                'provider' => (string) $request->query('provider', $request->input('provider', '')),
            ]);
        });
    }

    public function gatewayRestart(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayRestart($this->integrationPayload($request) + [
                'provider' => (string) $request->input('provider', $request->query('provider', '')),
                'clean' => $request->boolean('clean'),
            ]);
        });
    }

    public function gatewayLogout(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayLogout($this->integrationPayload($request) + [
                'provider' => (string) $request->input('provider', $request->query('provider', '')),
            ]);
        });
    }

    public function gatewayStart(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayStart($this->integrationPayload($request) + [
                'provider' => (string) $request->input('provider', $request->query('provider', '')),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationPayload(Request $request): array
    {
        return [
            'whatsapp_enabled' => $request->boolean('whatsapp_enabled'),
            'whatsapp_direct_provider' => trim((string) $request->input('whatsapp_direct_provider', 'api_whats_local')),
            'whatsapp_bulk_provider' => trim((string) $request->input('whatsapp_bulk_provider', 'meta_oficial')),
            'whatsapp_test_phone' => trim((string) $request->input('whatsapp_test_phone', '')),
            'whatsapp_menuia_url' => trim((string) $request->input('whatsapp_menuia_url', '')),
            'whatsapp_menuia_appkey' => trim((string) $request->input('whatsapp_menuia_appkey', '')),
            'whatsapp_menuia_authkey' => trim((string) $request->input('whatsapp_menuia_authkey', '')),
            'whatsapp_webhook_token' => trim((string) $request->input('whatsapp_webhook_token', '')),
            'whatsapp_evolution_url' => trim((string) $request->input('whatsapp_evolution_url', '')),
            'whatsapp_evolution_apikey' => trim((string) $request->input('whatsapp_evolution_apikey', '')),
            'whatsapp_evolution_instance' => trim((string) $request->input('whatsapp_evolution_instance', '')),
            'whatsapp_evolution_timeout' => (int) $request->input('whatsapp_evolution_timeout', 20),
            'whatsapp_evolution_sync_avatar' => $request->boolean('whatsapp_evolution_sync_avatar'),
            'whatsapp_local_node_url' => trim((string) $request->input('whatsapp_local_node_url', '')),
            'whatsapp_local_node_token' => trim((string) $request->input('whatsapp_local_node_token', '')),
            'whatsapp_local_node_origin' => trim((string) $request->input('whatsapp_local_node_origin', '')),
            'whatsapp_local_node_timeout' => (int) $request->input('whatsapp_local_node_timeout', 20),
            'whatsapp_linux_node_url' => trim((string) $request->input('whatsapp_linux_node_url', '')),
            'whatsapp_linux_node_token' => trim((string) $request->input('whatsapp_linux_node_token', '')),
            'whatsapp_linux_node_origin' => trim((string) $request->input('whatsapp_linux_node_origin', '')),
            'whatsapp_linux_node_timeout' => (int) $request->input('whatsapp_linux_node_timeout', 20),
            'whatsapp_webhook_url' => trim((string) $request->input('whatsapp_webhook_url', '')),
            'whatsapp_webhook_method' => trim((string) $request->input('whatsapp_webhook_method', 'POST')),
            'whatsapp_webhook_headers' => (string) $request->input('whatsapp_webhook_headers', '{}'),
            'whatsapp_webhook_payload' => (string) $request->input('whatsapp_webhook_payload', '{"to":"{{phone}}","message":"{{message}}"}'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Request $request): array
    {
        return [
            'pagamentos_mercadopago_enabled' => $request->boolean('pagamentos_mercadopago_enabled'),
            'pagamentos_mercadopago_access_token' => trim((string) $request->input('pagamentos_mercadopago_access_token', '')),
            'pagamentos_mercadopago_public_key' => trim((string) $request->input('pagamentos_mercadopago_public_key', '')),
            'pagamentos_asaas_enabled' => $request->boolean('pagamentos_asaas_enabled'),
            'pagamentos_asaas_base_url' => trim((string) $request->input('pagamentos_asaas_base_url', '')),
            'pagamentos_asaas_api_key' => trim((string) $request->input('pagamentos_asaas_api_key', '')),
            'pagamentos_asaas_billing_type_default' => trim((string) $request->input('pagamentos_asaas_billing_type_default', 'PIX')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emailPayload(Request $request): array
    {
        return [
            'smtp_host' => trim((string) $request->input('smtp_host', '')),
            'smtp_port' => (int) $request->input('smtp_port', 587),
            'smtp_crypto' => trim((string) $request->input('smtp_crypto', 'auto')),
            'smtp_timeout' => (int) $request->input('smtp_timeout', 20),
            'smtp_user' => trim((string) $request->input('smtp_user', '')),
            'smtp_pass' => trim((string) $request->input('smtp_pass', '')),
            'smtp_from_email' => trim((string) $request->input('smtp_from_email', '')),
            'smtp_from_name' => trim((string) $request->input('smtp_from_name', '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function googlePayload(Request $request): array
    {
        return [
            'portal_google_client_id' => trim((string) $request->input('portal_google_client_id', '')),
            'portal_google_client_secret' => trim((string) $request->input('portal_google_client_secret', '')),
        ];
    }

    /**
     * @param callable(): array<string, mixed> $callback
     */
    private function jsonAction(callable $callback): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'result' => $callback(),
            ]);
        } catch (ApiAuthenticationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'redirect' => route('login'),
            ], 401);
        } catch (ApiAuthorizationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'redirect' => route('configurations.integrations.index'),
            ], 403);
        } catch (ApiRequestException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'details' => $exception->details() ?? [],
            ], $exception->statusCode() > 0 ? $exception->statusCode() : 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível concluir a ação agora.',
            ], 500);
        }
    }

    /**
     * @param array<string, mixed>|null $details
     * @return array<string, array<int, string>>
     */
    private function formatApiErrors(ApiRequestException $exception): array
    {
        $details = $exception->details();

        if (! is_array($details)) {
            return [];
        }

        $errors = [];

        foreach ($details as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $errors[$field] = array_values(array_filter($messages, 'is_string'));
        }

        return $errors;
    }
}
