<?php

namespace App\Services;

class SupplierService
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
        $response = $this->apiClient->get('/suppliers', $filters);

        return [
            'items' => $response['data']['suppliers'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/suppliers/' . $id);

        return $response['data']['supplier'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/suppliers', $payload);

        return $response['data']['supplier'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/suppliers/' . $id, $payload);

        return $response['data']['supplier'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function close(int $id): array
    {
        $response = $this->apiClient->patch('/suppliers/' . $id . '/encerrar');

        return $response['data']['supplier'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/suppliers/' . $id);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function lookupCnpj(string $cnpj): array
    {
        $response = $this->apiClient->get('/suppliers/consultar-cnpj', [
            'cnpj' => $cnpj,
        ]);

        return $response['data']['lookup'] ?? [];
    }
}
