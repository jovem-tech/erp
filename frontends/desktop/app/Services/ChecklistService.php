<?php

namespace App\Services;

class ChecklistService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /** @return array{checklist_tipo: array<string, mixed>, equipment_types: array<int, array<string, mixed>>} */
    public function listModelos(string $tipo): array
    {
        $response = $this->apiClient->get('/knowledge/checklists/' . $tipo);

        return [
            'checklist_tipo' => $response['data']['checklist_tipo'] ?? [],
            'equipment_types' => $response['data']['equipment_types'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function findModelo(string $tipo, int $tipoEquipamento): array
    {
        $response = $this->apiClient->get('/knowledge/checklists/' . $tipo . '/modelos/' . $tipoEquipamento);

        return $response['data'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createModelo(string $tipo, int $tipoEquipamento, array $payload): array
    {
        $response = $this->apiClient->post('/knowledge/checklists/' . $tipo . '/modelos/' . $tipoEquipamento, $payload);

        return $response['data']['modelo'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateModelo(string $tipo, int $modelo, array $payload): array
    {
        $response = $this->apiClient->patch('/knowledge/checklists/' . $tipo . '/modelos/' . $modelo, $payload);

        return $response['data']['modelo'] ?? [];
    }

    public function destroyModelo(string $tipo, int $modelo): array
    {
        return $this->apiClient->delete('/knowledge/checklists/' . $tipo . '/modelos/' . $modelo);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function addItem(string $tipo, int $modelo, array $payload): array
    {
        $response = $this->apiClient->post('/knowledge/checklists/' . $tipo . '/modelos/' . $modelo . '/itens', $payload);

        return $response['data']['modelo'] ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateItem(string $tipo, int $modelo, int $item, array $payload): array
    {
        $response = $this->apiClient->patch('/knowledge/checklists/' . $tipo . '/modelos/' . $modelo . '/itens/' . $item, $payload);

        return $response['data']['modelo'] ?? [];
    }

    /** @return array<string, mixed> */
    public function destroyItem(string $tipo, int $modelo, int $item): array
    {
        $response = $this->apiClient->delete('/knowledge/checklists/' . $tipo . '/modelos/' . $modelo . '/itens/' . $item);

        return $response['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function moveItem(string $tipo, int $modelo, int $item, string $direction): array
    {
        $response = $this->apiClient->patch('/knowledge/checklists/' . $tipo . '/modelos/' . $modelo . '/itens/' . $item . '/mover', [
            'direction' => $direction,
        ]);

        return $response['data']['modelo'] ?? [];
    }

    /** @return array<string, mixed> */
    public function toggleItemActive(string $tipo, int $modelo, int $item): array
    {
        $response = $this->apiClient->patch('/knowledge/checklists/' . $tipo . '/modelos/' . $modelo . '/itens/' . $item . '/ativo');

        return $response['data']['modelo'] ?? [];
    }
}
