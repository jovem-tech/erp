<?php

namespace App\Services;

class KnowledgeLookupService
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function equipmentTypes(): array
    {
        $response = $this->apiClient->get('/knowledge/equipment-types');

        return $response['data']['equipment_types'] ?? [];
    }
}
