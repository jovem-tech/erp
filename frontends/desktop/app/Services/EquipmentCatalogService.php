<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Tela "Equipamentos" em Cadastros (specs/044-catalogo-equipamentos-csv):
 * wrapper fino sobre a API central para o catalogo de tipos/marcas/modelos.
 * Nenhuma logica de negocio aqui — so' molda o payload/resposta, igual aos
 * demais *Service deste frontend (ver ServicoService/StockService).
 */
class EquipmentCatalogService
{
    public function __construct(private readonly ApiClient $apiClient)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function paginateModels(array $filters = []): array
    {
        $response = $this->apiClient->get('/equipments/catalog/models', $filters);

        return [
            'items' => $response['data']['modelos'] ?? [],
            'pagination' => $response['meta']['pagination'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function brands(array $filters = []): array
    {
        $response = $this->apiClient->get('/equipments/catalog/brands', $filters);

        return $response['data']['marcas'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function types(): array
    {
        $response = $this->apiClient->get('/equipments/catalog/types');

        return $response['data']['tipos'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveModel(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        unset($payload['id']);

        $response = $id > 0
            ? $this->apiClient->patch('/equipments/catalog/models/'.$id, $payload)
            : $this->apiClient->post('/equipments/catalog/models', $payload);

        return $response['data']['modelo'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveBrand(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        unset($payload['id']);

        $response = $id > 0
            ? $this->apiClient->patch('/equipments/catalog/brands/'.$id, $payload)
            : $this->apiClient->post('/equipments/catalog/brands', $payload);

        return $response['data']['marca'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveType(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        unset($payload['id']);

        $response = $id > 0
            ? $this->apiClient->patch('/equipments/catalog/types/'.$id, $payload)
            : $this->apiClient->post('/equipments/catalog/types', $payload);

        return $response['data']['tipo'] ?? [];
    }

    public function toggleModel(int $id, bool $active): void
    {
        $this->apiClient->patch('/equipments/catalog/models/'.$id, ['ativo' => $active]);
    }

    public function toggleBrand(int $id, bool $active): void
    {
        $this->apiClient->patch('/equipments/catalog/brands/'.$id, ['ativo' => $active]);
    }

    public function toggleType(int $id, bool $active): void
    {
        $this->apiClient->patch('/equipments/catalog/types/'.$id, ['ativo' => $active]);
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function exportCsv(): array
    {
        return $this->apiClient->download('/equipments/catalog/exportar-csv');
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadCsvTemplate(): array
    {
        return $this->apiClient->download('/equipments/catalog/modelo-importacao.csv');
    }

    /**
     * @return array<string, mixed>
     */
    public function importCsv(UploadedFile $file): array
    {
        $response = $this->apiClient->postMultipart('/equipments/catalog/importar-lote', [], [
            'arquivo' => [$file],
        ]);

        return $response['data']['resultado'] ?? [];
    }
}
