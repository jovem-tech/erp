<?php

namespace App\Services;

class GroupService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $response = $this->apiClient->get('/groups');

        return $response['data']['groups'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/groups', $payload);

        return $response['data']['group'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/groups/' . $id, $payload);

        return $response['data']['group'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/groups/' . $id);

        return $response['data'] ?? [];
    }

    /**
     * @return array{group: array<string, mixed>, permissions: array<string, array<int, string>>}
     */
    public function permissions(int $id): array
    {
        $response = $this->apiClient->get('/groups/' . $id . '/permissions');

        return [
            'group' => $response['data']['group'] ?? [],
            'permissions' => $response['data']['permissions'] ?? [],
        ];
    }

    /**
     * @param array<string, array<int, string>> $permissions
     * @return array<string, mixed>
     */
    public function updatePermissions(int $id, array $permissions): array
    {
        $response = $this->apiClient->put('/groups/' . $id . '/permissions', [
            'permissions' => $permissions,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function modulesCatalog(): array
    {
        $response = $this->apiClient->get('/modules');

        return $response['data']['modules'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function permissionsCatalog(): array
    {
        $response = $this->apiClient->get('/permissions');

        return $response['data']['permissions'] ?? [];
    }
}
