<?php

namespace App\Services;

class UserService
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
        $response = $this->apiClient->get('/users', $filters);

        return [
            'items' => $response['data']['users'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/users', $payload);

        return $response['data']['user'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/users/' . $id, $payload);

        return $response['data']['user'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateActive(int $id, bool $active): array
    {
        $response = $this->apiClient->post('/users/' . $id . '/active', [
            'active' => $active,
        ]);

        return $response['data']['user'] ?? [];
    }
}
