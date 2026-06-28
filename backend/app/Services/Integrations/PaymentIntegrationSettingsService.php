<?php

namespace App\Services\Integrations;

use App\Models\Configuration;
use Illuminate\Support\Facades\Http;
use Throwable;

class PaymentIntegrationSettingsService
{
    private const MERCADO_PAGO_BASE_URL = 'https://api.mercadopago.com';

    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'pagamentos_mercadopago_enabled' => '0',
        'pagamentos_mercadopago_access_token' => '',
        'pagamentos_mercadopago_public_key' => '',
        'pagamentos_asaas_enabled' => '0',
        'pagamentos_asaas_base_url' => 'https://api-sandbox.asaas.com/v3',
        'pagamentos_asaas_api_key' => '',
        'pagamentos_asaas_billing_type_default' => 'PIX',
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $settings = $this->loadSettings();

        return [
            'settings' => $settings,
            'summary' => $this->buildSummary($settings),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $current = $this->loadSettings();
        $normalized = $this->normalizePayload($payload, $current);

        foreach ($normalized as $key => $value) {
            $this->upsert((string) $key, (string) $value, $this->fieldType((string) $key));
        }

        return $this->payload();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function testConnection(array $payload, string $provider): array
    {
        $settings = $this->mergeSettings($payload);
        $provider = $this->normalizeProvider($provider);

        if ($provider === 'mercado_pago') {
            return $this->testMercadoPago($settings);
        }

        if ($provider === 'asaas') {
            return $this->testAsaas($settings);
        }

        return $this->failureResponse('Selecione o gateway de pagamento antes de testar.');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function testMercadoPago(array $settings): array
    {
        $accessToken = trim((string) ($settings['pagamentos_mercadopago_access_token'] ?? ''));
        $publicKey = trim((string) ($settings['pagamentos_mercadopago_public_key'] ?? ''));

        if ($accessToken === '' || $publicKey === '') {
            return $this->failureResponse('Informe a Public Key e o Access Token do Mercado Pago antes de testar.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withToken($accessToken)
                ->get(self::MERCADO_PAGO_BASE_URL . '/users/me');

            if (! $response->successful()) {
                return $this->failureResponse(
                    'Falha ao validar o Mercado Pago: ' . $this->extractErrorMessage($response->json(), $response->status()),
                    ['provider' => 'mercado_pago', 'status_code' => $response->status()]
                );
            }

            $body = $response->json() ?? [];

            return [
                'ok' => true,
                'provider' => 'mercado_pago',
                'status_code' => $response->status(),
                'message' => 'Conexão com o Mercado Pago validada com sucesso.',
                'details' => [
                    'id' => $body['id'] ?? null,
                    'nickname' => $body['nickname'] ?? null,
                    'email' => $body['email'] ?? null,
                ],
            ];
        } catch (Throwable $throwable) {
            return $this->failureResponse('Falha ao validar o Mercado Pago: ' . $throwable->getMessage(), [
                'provider' => 'mercado_pago',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function testAsaas(array $settings): array
    {
        $baseUrl = trim((string) ($settings['pagamentos_asaas_base_url'] ?? ''));
        $apiKey = trim((string) ($settings['pagamentos_asaas_api_key'] ?? ''));

        if ($baseUrl === '' || $apiKey === '') {
            return $this->failureResponse('Informe a Base URL e a chave da API do Asaas antes de testar.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withHeaders(['access_token' => $apiKey])
                ->get(rtrim($baseUrl, '/') . '/myAccount');

            if (! $response->successful()) {
                return $this->failureResponse(
                    'Falha ao validar o Asaas: ' . $this->extractErrorMessage($response->json(), $response->status()),
                    ['provider' => 'asaas', 'status_code' => $response->status()]
                );
            }

            $body = $response->json() ?? [];

            return [
                'ok' => true,
                'provider' => 'asaas',
                'status_code' => $response->status(),
                'message' => 'Conexão com o Asaas validada com sucesso.',
                'details' => [
                    'name' => $body['name'] ?? null,
                    'email' => $body['email'] ?? null,
                    'walletId' => $body['walletId'] ?? null,
                ],
            ];
        } catch (Throwable $throwable) {
            return $this->failureResponse('Falha ao validar o Asaas: ' . $throwable->getMessage(), [
                'provider' => 'asaas',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function mergeSettings(array $payload = []): array
    {
        $current = $this->loadSettings();

        if ($payload === []) {
            return $current;
        }

        return array_merge($current, $this->normalizePayload($payload, $current));
    }

    /**
     * @return array<string, string>
     */
    private function loadSettings(): array
    {
        $stored = Configuration::query()
            ->whereIn('chave', array_keys(self::DEFAULTS))
            ->pluck('valor', 'chave')
            ->all();

        return array_merge(self::DEFAULTS, array_map(
            static fn ($value): string => trim((string) $value),
            is_array($stored) ? $stored : []
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $current
     * @return array<string, string>
     */
    private function normalizePayload(array $payload, array $current): array
    {
        $normalized = [];

        foreach (self::DEFAULTS as $key => $defaultValue) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if ($this->isBooleanKey($key)) {
                $normalized[$key] = filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0';
                continue;
            }

            if ($key === 'pagamentos_asaas_base_url') {
                $normalized[$key] = $this->normalizeUrl((string) $value, (string) $defaultValue);
                continue;
            }

            if ($key === 'pagamentos_asaas_billing_type_default') {
                $billingType = strtoupper(trim((string) $value));
                $normalized[$key] = in_array($billingType, ['PIX', 'BOLETO', 'CREDIT_CARD'], true) ? $billingType : 'PIX';
                continue;
            }

            $normalized[$key] = is_scalar($value) ? trim((string) $value) : (string) $defaultValue;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildSummary(array $settings): array
    {
        $mercadoPagoEnabled = $this->isTruthy((string) ($settings['pagamentos_mercadopago_enabled'] ?? '0'));
        $mercadoPagoReady = $mercadoPagoEnabled
            && trim((string) ($settings['pagamentos_mercadopago_access_token'] ?? '')) !== ''
            && trim((string) ($settings['pagamentos_mercadopago_public_key'] ?? '')) !== '';

        $asaasEnabled = $this->isTruthy((string) ($settings['pagamentos_asaas_enabled'] ?? '0'));
        $asaasReady = $asaasEnabled
            && trim((string) ($settings['pagamentos_asaas_base_url'] ?? '')) !== ''
            && trim((string) ($settings['pagamentos_asaas_api_key'] ?? '')) !== '';

        return [
            'mercado_pago' => [
                'enabled' => $mercadoPagoEnabled,
                'ready' => $mercadoPagoReady,
                'status' => $mercadoPagoReady ? 'success' : 'secondary',
                'status_label' => $mercadoPagoReady ? 'Pronto para uso' : 'Aguardando configuração',
            ],
            'asaas' => [
                'enabled' => $asaasEnabled,
                'ready' => $asaasReady,
                'status' => $asaasReady ? 'success' : 'secondary',
                'status_label' => $asaasReady ? 'Pronto para uso' : 'Aguardando configuração',
            ],
        ];
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return in_array($provider, ['mercado_pago', 'asaas'], true) ? $provider : '';
    }

    private function normalizeUrl(string $value, string $default = ''): string
    {
        $normalized = trim(rtrim($value, '/'));

        return $normalized !== '' ? $normalized : $default;
    }

    private function isBooleanKey(string $key): bool
    {
        return in_array($key, [
            'pagamentos_mercadopago_enabled',
            'pagamentos_asaas_enabled',
        ], true);
    }

    private function fieldType(string $key): string
    {
        return $this->isBooleanKey($key) ? 'booleano' : 'texto';
    }

    private function isTruthy(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'sim', 'on'], true);
    }

    private function upsert(string $key, string $value, string $type): void
    {
        Configuration::query()->updateOrInsert(
            ['chave' => $key],
            [
                'valor' => $value,
                'tipo' => $type,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function extractErrorMessage(mixed $payload, int $statusCode): string
    {
        if (is_array($payload)) {
            $message = trim((string) data_get($payload, 'message', ''));
            if ($message !== '') {
                return $message;
            }

            $error = trim((string) data_get($payload, 'error', ''));
            if ($error !== '') {
                return $error;
            }
        }

        return 'Falha na resposta do provedor (HTTP ' . $statusCode . ').';
    }

    private function failureResponse(string $message, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'status_code' => 422,
            'message' => $message,
            'details' => [],
        ], $extra);
    }
}
