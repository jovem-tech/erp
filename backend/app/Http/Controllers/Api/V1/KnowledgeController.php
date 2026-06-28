<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EquipmentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeController extends BaseApiController
{
    public function equipmentTypes(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $types = EquipmentType::query()
            ->where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome'])
            ->map(static fn (EquipmentType $type): array => [
                'id' => (int) $type->id,
                'nome' => (string) $type->nome,
            ])
            ->values()
            ->all();

        return $this->success(['equipment_types' => $types], request: $request);
    }
}
