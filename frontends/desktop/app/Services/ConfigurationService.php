<?php

namespace App\Services;

class ConfigurationService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function integrations(): array
    {
        $response = $this->apiClient->get('/configuracoes/integracoes');

        return $this->arrayValue($response['data']['integration'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateIntegrations(array $payload): array
    {
        $response = $this->apiClient->put('/configuracoes/integracoes', $payload);

        return $this->arrayValue($response['data']['integration'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function testConnection(array $payload): array
    {
        $response = $this->apiClient->post('/configuracoes/integracoes/testar-conexao', $payload);

        return $this->arrayValue($response['data']['result'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendTestMessage(array $payload): array
    {
        $response = $this->apiClient->post('/configuracoes/integracoes/enviar-teste', $payload);

        return $this->arrayValue($response['data']['result'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function selfCheckInbound(array $payload): array
    {
        $response = $this->apiClient->post('/configuracoes/integracoes/self-check-inbound', $payload);

        return $this->arrayValue($response['data']['result'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function testPaymentConnection(array $payload): array
    {
        $response = $this->apiClient->post('/configuracoes/integracoes/pagamentos/testar-conexao', $payload);

        return $this->arrayValue($response['data']['result'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendEmailTest(array $payload): array
    {
        $response = $this->apiClient->post('/configuracoes/integracoes/email/enviar-teste', $payload);

        return $this->arrayValue($response['data']['result'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayStatus(array $payload): array
    {
        $response = $this->apiClient->get('/configuracoes/integracoes/gateway/status', $payload);

        return $this->arrayValue($response['data']['gateway'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayQr(array $payload): array
    {
        $response = $this->apiClient->get('/configuracoes/integracoes/gateway/qr', $payload);

        return $this->arrayValue($response['data']['gateway'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayRestart(array $payload): array
    {
        $response = $this->apiClient->post('/configuracoes/integracoes/gateway/restart', $payload);

        return $this->arrayValue($response['data']['gateway'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayLogout(array $payload): array
    {
        $response = $this->apiClient->post('/configuracoes/integracoes/gateway/logout', $payload);

        return $this->arrayValue($response['data']['gateway'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayStart(array $payload): array
    {
        $response = $this->apiClient->post('/configuracoes/integracoes/gateway/start', $payload);

        return $this->arrayValue($response['data']['gateway'] ?? []);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
