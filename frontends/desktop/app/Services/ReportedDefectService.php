<?php

namespace App\Services;

class ReportedDefectService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function paginate(array $filters = []): array
    {
        $response = $this->apiClient->get('/knowledge/reported-defects', $filters);

        return [
            'items' => $response['data']['defeitos_relatados'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/knowledge/reported-defects/' . $id);

        return $response['data']['defeito_relatado'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/knowledge/reported-defects', $payload);

        return $response['data']['defeito_relatado'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/knowledge/reported-defects/' . $id, $payload);

        return $response['data']['defeito_relatado'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleActive(int $id): array
    {
        $response = $this->apiClient->patch('/knowledge/reported-defects/' . $id . '/ativo');

        return $response['data']['defeito_relatado'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/knowledge/reported-defects/' . $id);

        return $response['data'] ?? [];
    }
}
