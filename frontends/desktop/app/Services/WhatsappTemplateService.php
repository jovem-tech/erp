<?php

namespace App\Services;

class WhatsappTemplateService
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
        $response = $this->apiClient->get('/knowledge/whatsapp-templates', $filters);

        return [
            'items' => $response['data']['whatsapp_templates'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/knowledge/whatsapp-templates/' . $id);

        return $response['data']['whatsapp_template'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/knowledge/whatsapp-templates', $payload);

        return $response['data']['whatsapp_template'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/knowledge/whatsapp-templates/' . $id, $payload);

        return $response['data']['whatsapp_template'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleActive(int $id): array
    {
        $response = $this->apiClient->patch('/knowledge/whatsapp-templates/' . $id . '/ativo');

        return $response['data']['whatsapp_template'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/knowledge/whatsapp-templates/' . $id);

        return $response['data'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function placeholders(): array
    {
        $response = $this->apiClient->get('/knowledge/whatsapp-templates/placeholders');

        return $response['data']['placeholders'] ?? [];
    }
}
