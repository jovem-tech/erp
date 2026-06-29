<?php

namespace App\Services;

class FinanceiroPrecificacaoService
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
        $response = $this->apiClient->get('/financeiro/precificacao');

        return $response['data']['precificacao'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $response = $this->apiClient->put('/financeiro/precificacao', $payload);

        return $response['data']['precificacao'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function simulatePeca(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/precificacao/simular-peca', $payload);

        return $response['data']['simulation'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function simulateServico(array $payload): array
    {
        $response = $this->apiClient->post('/financeiro/precificacao/simular-servico', $payload);

        return $response['data']['simulation'] ?? [];
    }
}
