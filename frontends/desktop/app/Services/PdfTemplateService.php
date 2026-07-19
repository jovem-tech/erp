<?php

namespace App\Services;

class PdfTemplateService
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
        $response = $this->apiClient->get('/knowledge/pdf-templates', $filters);

        return [
            'items' => $response['data']['os_pdf_templates'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $response = $this->apiClient->get('/knowledge/pdf-templates/' . $id);

        return $response['data']['os_pdf_template'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $response = $this->apiClient->post('/knowledge/pdf-templates', $payload);

        return $response['data']['os_pdf_template'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $response = $this->apiClient->patch('/knowledge/pdf-templates/' . $id, $payload);

        return $response['data']['os_pdf_template'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleActive(int $id): array
    {
        $response = $this->apiClient->patch('/knowledge/pdf-templates/' . $id . '/ativo');

        return $response['data']['os_pdf_template'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function destroy(int $id): array
    {
        $response = $this->apiClient->delete('/knowledge/pdf-templates/' . $id);

        return $response['data'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function placeholders(): array
    {
        $response = $this->apiClient->get('/knowledge/pdf-templates/placeholders');

        return $response['data']['placeholders'] ?? [];
    }

    /**
     * Prévia do layout deste modelo legado (motor central de PDF).
     *
     * @param array<string, mixed> $query formato (a4|80mm), entidade_id (OS real, opcional)
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function preview(int $id, array $query = []): array
    {
        return $this->apiClient->download('/knowledge/pdf-templates/' . $id . '/preview', $query);
    }
}
