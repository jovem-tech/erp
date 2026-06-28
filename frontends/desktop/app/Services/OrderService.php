<?php

namespace App\Services;

class OrderService
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
        $response = $this->apiClient->get('/orders', $filters);

        return [
            'items' => $response['data']['orders'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/orders/' . $id);

        return $response['data']['order'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/orders', $payload);

        return $response['data']['order'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/orders/' . $id, $payload);

        return $response['data']['order'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function closureMetadata(int $id): array
    {
        $response = $this->apiClient->get('/orders/' . $id . '/closure');

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function close(int $id, array $payload): array
    {
        $response = $this->apiClient->post('/orders/' . $id . '/closure', $payload);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(int $id, string $status, ?string $observacao = null): array
    {
        $payload = ['status' => $status];
        if ($observacao !== null && $observacao !== '') {
            $payload['observacao'] = $observacao;
        }

        $response = $this->apiClient->patch('/orders/' . $id . '/status', $payload);

        return $response['data']['order'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadPhoto(int $orderId, int $photoId): array
    {
        return $this->apiClient->download('/orders/' . $orderId . '/photos/' . $photoId);
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadDocument(int $orderId, int $documentId): array
    {
        return $this->apiClient->download('/orders/' . $orderId . '/documents/' . $documentId);
    }
}
