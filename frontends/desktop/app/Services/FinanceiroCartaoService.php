<?php

namespace App\Services;

class FinanceiroCartaoService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dataset(): array
    {
        $response = $this->apiClient->get('/financeiro/cartoes');

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function simulate(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/cartoes/simular', $payload);

        return $response['data']['simulation'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveOperadora(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/cartoes/operadoras', $payload);

        return $response['data']['operadora'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateOperadora(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/financeiro/cartoes/operadoras/' . $id, $payload);

        return $response['data']['operadora'] ?? [];
    }

    public function deleteOperadora(int $id): array
    {
        $response = $this->apiClient->delete('/financeiro/cartoes/operadoras/' . $id);

        return $response['data']['operadora'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveBandeira(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/cartoes/bandeiras', $payload);

        return $response['data']['bandeira'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateBandeira(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/financeiro/cartoes/bandeiras/' . $id, $payload);

        return $response['data']['bandeira'] ?? [];
    }

    public function deleteBandeira(int $id): array
    {
        $response = $this->apiClient->delete('/financeiro/cartoes/bandeiras/' . $id);

        return $response['data']['bandeira'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveTaxa(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/cartoes/taxas', $payload);

        return $response['data']['taxa'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateTaxa(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/financeiro/cartoes/taxas/' . $id, $payload);

        return $response['data']['taxa'] ?? [];
    }

    public function deleteTaxa(int $id): array
    {
        $response = $this->apiClient->delete('/financeiro/cartoes/taxas/' . $id);

        return $response['data']['taxa'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveGatewayTaxa(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/cartoes/taxas-online', $payload);

        return $response['data']['gateway_taxa'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateGatewayTaxa(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/financeiro/cartoes/taxas-online/' . $id, $payload);

        return $response['data']['gateway_taxa'] ?? [];
    }

    public function deleteGatewayTaxa(int $id): array
    {
        $response = $this->apiClient->delete('/financeiro/cartoes/taxas-online/' . $id);

        return $response['data']['gateway_taxa'] ?? [];
    }
}
