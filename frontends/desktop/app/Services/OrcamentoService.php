<?php

namespace App\Services;

class OrcamentoService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>, summary: array<string, mixed>, status_options: array<int, array<string, mixed>>}
     */
    public function paginate(array $filters = []): array
    {
        $response = $this->apiClient->get('/orcamentos', $filters);

        return [
            'items' => $response['data']['budgets'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
            'summary' => $response['data']['summary'] ?? [],
            'status_options' => $response['data']['status_options'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/orcamentos/' . $id);

        return $response['data']['budget'] ?? [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function formData(array $filters = []): array
    {
        $response = $this->apiClient->get('/orcamentos/form-data', $filters);

        return $response['data']['form'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/orcamentos', $payload);

        return $response['data']['budget'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/orcamentos/' . $id, $payload);

        return $response['data']['budget'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/orcamentos/' . $id);

        return $response['data'] ?? [];
    }
}
