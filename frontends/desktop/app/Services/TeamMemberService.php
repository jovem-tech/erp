<?php

namespace App\Services;

class TeamMemberService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>, available_users: array<int, array<string, mixed>>}
     */
    public function paginate(array $filters = []): array
    {
        $response = $this->apiClient->get('/team-members', $filters);

        return [
            'items' => $response['data']['team_members'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
            'available_users' => $response['data']['available_users'] ?? [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function assignableTechnicians(): array
    {
        $response = $this->apiClient->get('/team-members', [
            'per_page' => 100,
            'active' => 1,
            'role' => 'tecnico',
            'assignable_orders' => 1,
        ]);

        return $response['data']['team_members'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/team-members', $payload);

        return $response['data']['team_member'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/team-members/' . $id, $payload);

        return $response['data']['team_member'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateActive(int $id, bool $active): array
    {
        $response = $this->apiClient->patch('/team-members/' . $id . '/active', [
            'active' => $active,
        ]);

        return $response['data']['team_member'] ?? [];
    }
}
