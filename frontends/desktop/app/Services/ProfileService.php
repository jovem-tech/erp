<?php

namespace App\Services;

class ProfileService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $response = $this->apiClient->me();

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateProfile(array $payload): array
    {
        $response = $this->apiClient->patch('/auth/me', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updatePassword(array $payload): array
    {
        $response = $this->apiClient->put('/auth/password', $payload);

        return $response['data'] ?? [];
    }
}
