<?php

namespace App\Services;

class DefectService
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
        $response = $this->apiClient->get('/knowledge/defects', $filters);

        return [
            'items' => $response['data']['equipamentos_defeitos'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/knowledge/defects/' . $id);

        return $response['data']['equipamento_defeito'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/knowledge/defects', $payload);

        return $response['data']['equipamento_defeito'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/knowledge/defects/' . $id, $payload);

        return $response['data']['equipamento_defeito'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleActive(int $id): array
    {
        $response = $this->apiClient->patch('/knowledge/defects/' . $id . '/ativo');

        return $response['data']['equipamento_defeito'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/knowledge/defects/' . $id);

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addProcedure(int $defeito, array $payload): array
    {
        $response = $this->apiClient->post('/knowledge/defects/' . $defeito . '/procedures', $payload);

        return $response['data']['equipamento_defeito'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateProcedure(int $defeito, int $procedimento, array $payload): array
    {
        $response = $this->apiClient->patch('/knowledge/defects/' . $defeito . '/procedures/' . $procedimento, $payload);

        return $response['data']['equipamento_defeito'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroyProcedure(int $defeito, int $procedimento): array
    {
        $response = $this->apiClient->delete('/knowledge/defects/' . $defeito . '/procedures/' . $procedimento);

        return $response['data']['equipamento_defeito'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function moveProcedure(int $defeito, int $procedimento, string $direction): array
    {
        $response = $this->apiClient->patch('/knowledge/defects/' . $defeito . '/procedures/' . $procedimento . '/move', [
            'direction' => $direction,
        ]);

        return $response['data']['equipamento_defeito'] ?? [];
    }
}
