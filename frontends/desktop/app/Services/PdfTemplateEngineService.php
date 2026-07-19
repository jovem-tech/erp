<?php

namespace App\Services;

/**
 * Proxy da API do motor central de templates PDF (knowledge/pdf-engine).
 */
class PdfTemplateEngineService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function types(): array
    {
        $response = $this->apiClient->get('/knowledge/pdf-engine/types');

        return $response['data']['tipos'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function typeMetadata(string $codigo): array
    {
        $response = $this->apiClient->get('/knowledge/pdf-engine/types/' . rawurlencode($codigo) . '/variables');

        return $response['data']['tipo'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function template(int $templateId): array
    {
        $response = $this->apiClient->get('/knowledge/pdf-engine/templates/' . $templateId);

        return $response['data']['template'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $nome, ?string $descricao, string $tipoBaseCodigo): array
    {
        $response = $this->apiClient->post('/knowledge/pdf-engine/templates', [
            'nome' => $nome,
            'descricao' => $descricao,
            'tipo_base_codigo' => $tipoBaseCodigo,
        ]);

        return $response['data']['template'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cloneTemplate(int $templateId, string $nome, ?string $descricao): array
    {
        $response = $this->apiClient->post('/knowledge/pdf-engine/templates/' . $templateId . '/clone', [
            'nome' => $nome,
            'descricao' => $descricao,
        ]);

        return $response['data']['template'] ?? [];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function saveDraft(int $templateId, array $schema, ?string $updatedAt): array
    {
        $response = $this->apiClient->put('/knowledge/pdf-engine/templates/' . $templateId . '/draft', [
            'schema' => $schema,
            'updated_at' => $updatedAt,
        ]);

        return $response['data']['rascunho'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(int $templateId): array
    {
        $response = $this->apiClient->post('/knowledge/pdf-engine/templates/' . $templateId . '/publish');

        return $response['data']['versao_publicada'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(int $templateId, int $versao): array
    {
        $response = $this->apiClient->post('/knowledge/pdf-engine/templates/' . $templateId . '/versions/' . $versao . '/restore');

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function preview(int $templateId, array $payload): array
    {
        return $this->apiClient->postDownload('/knowledge/pdf-engine/templates/' . $templateId . '/preview', $payload);
    }
}
