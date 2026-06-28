<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ChecklistItem;
use App\Models\ChecklistModelo;
use App\Models\ChecklistTipo;
use App\Models\EquipmentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecklistModeloController extends BaseApiController
{
    public function index(Request $request, string $tipo): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $equipmentTypes = EquipmentType::query()->where('ativo', true)->orderBy('nome')->get(['id', 'nome']);

        $modelos = ChecklistModelo::query()
            ->where('checklist_tipo_id', $checklistTipo->id)
            ->withCount('itens')
            ->get()
            ->keyBy('tipo_equipamento_id');

        $combinedList = $equipmentTypes->map(static function (EquipmentType $equipmentType) use ($modelos): array {
            $modelo = $modelos->get((int) $equipmentType->id);

            return [
                'tipo_equipamento_id' => (int) $equipmentType->id,
                'tipo_equipamento_nome' => (string) ($equipmentType->nome ?? ''),
                'modelo_id' => $modelo?->id,
                'configurado' => $modelo !== null,
                'itens_count' => (int) ($modelo?->itens_count ?? 0),
                'ativo' => (bool) ($modelo?->ativo ?? false),
            ];
        })->values()->all();

        return $this->success([
            'checklist_tipo' => [
                'codigo' => $checklistTipo->codigo,
                'nome' => $checklistTipo->nome,
            ],
            'equipment_types' => $combinedList,
        ], request: $request);
    }

    public function showOrCreate(Request $request, string $tipo, int $tipoEquipamento): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $equipmentType = EquipmentType::find($tipoEquipamento);

        if (! $equipmentType instanceof EquipmentType) {
            return $this->error(
                'Tipo de equipamento nao encontrado.',
                404,
                'EQUIPMENT_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $modelo = ChecklistModelo::where('checklist_tipo_id', $checklistTipo->id)
            ->where('tipo_equipamento_id', $tipoEquipamento)
            ->with('itens')
            ->first();

        if (! $modelo instanceof ChecklistModelo) {
            return $this->success([
                'exists' => false,
                'checklist_tipo' => [
                    'codigo' => $checklistTipo->codigo,
                    'nome' => $checklistTipo->nome,
                ],
                'tipo_equipamento' => [
                    'id' => (int) $equipmentType->id,
                    'nome' => (string) ($equipmentType->nome ?? ''),
                ],
            ], request: $request);
        }

        return $this->success([
            'exists' => true,
            'checklist_tipo' => [
                'codigo' => $checklistTipo->codigo,
                'nome' => $checklistTipo->nome,
            ],
            'tipo_equipamento' => [
                'id' => (int) $equipmentType->id,
                'nome' => (string) ($equipmentType->nome ?? ''),
            ],
            'modelo' => $this->mapModeloDetail($modelo),
        ], request: $request);
    }

    public function storeModelo(Request $request, string $tipo, int $tipoEquipamento): JsonResponse
    {
        $this->authorize('conhecimento:criar');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $equipmentType = EquipmentType::find($tipoEquipamento);

        if (! $equipmentType instanceof EquipmentType) {
            return $this->error(
                'Tipo de equipamento nao encontrado.',
                404,
                'EQUIPMENT_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'nome' => ['nullable', 'string', 'max:160'],
            'descricao' => ['nullable', 'string'],
            'ordem' => ['nullable', 'integer'],
        ]);

        $nome = trim((string) ($validated['nome'] ?? ''));

        if ($nome === '') {
            $nome = $checklistTipo->nome . ' - ' . $equipmentType->nome;
        }

        $descricao = $validated['descricao'] ?? null;
        $descricao = is_string($descricao) ? trim($descricao) : $descricao;

        $modelo = ChecklistModelo::firstOrCreate(
            [
                'checklist_tipo_id' => $checklistTipo->id,
                'tipo_equipamento_id' => $tipoEquipamento,
            ],
            [
                'nome' => $nome,
                'descricao' => $descricao === '' ? null : $descricao,
                'ordem' => $validated['ordem'] ?? 0,
                'ativo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $this->success(
            ['modelo' => $this->mapModeloDetail($modelo->load('itens'))],
            201,
            request: $request
        );
    }

    public function updateModelo(Request $request, string $tipo, int $modelo): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $modeloModel = ChecklistModelo::where('id', $modelo)
            ->where('checklist_tipo_id', $checklistTipo->id)
            ->first();

        if (! $modeloModel instanceof ChecklistModelo) {
            return $this->error(
                'Modelo de checklist nao encontrado.',
                404,
                'CHECKLIST_MODEL_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:160'],
            'descricao' => ['nullable', 'string'],
            'ordem' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $modeloModel->nome = trim((string) $validated['nome']);
        $modeloModel->descricao = isset($validated['descricao']) ? trim((string) $validated['descricao']) : null;

        if ($modeloModel->descricao === '') {
            $modeloModel->descricao = null;
        }

        if (array_key_exists('ordem', $validated) && $validated['ordem'] !== null) {
            $modeloModel->ordem = (int) $validated['ordem'];
        }

        if ($request->has('ativo')) {
            $modeloModel->ativo = $request->boolean('ativo');
        }

        $modeloModel->save();

        return $this->success(
            ['modelo' => $this->mapModeloDetail($modeloModel->fresh(['itens', 'tipoEquipamento']) ?? $modeloModel)],
            request: $request
        );
    }

    public function destroyModelo(Request $request, string $tipo, int $modelo): JsonResponse
    {
        $this->authorize('conhecimento:excluir');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $modeloModel = ChecklistModelo::where('id', $modelo)
            ->where('checklist_tipo_id', $checklistTipo->id)
            ->first();

        if (! $modeloModel instanceof ChecklistModelo) {
            return $this->error(
                'Modelo de checklist nao encontrado.',
                404,
                'CHECKLIST_MODEL_NOT_FOUND',
                null,
                request: $request
            );
        }

        $modeloModel->delete();

        return $this->success([
            'deleted' => true,
            'modelo_id' => $modelo,
        ], request: $request);
    }

    public function storeItem(Request $request, string $tipo, int $modelo): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $modeloModel = ChecklistModelo::where('id', $modelo)
            ->where('checklist_tipo_id', $checklistTipo->id)
            ->first();

        if (! $modeloModel instanceof ChecklistModelo) {
            return $this->error(
                'Modelo de checklist nao encontrado.',
                404,
                'CHECKLIST_MODEL_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'descricao' => ['required', 'string', 'max:255'],
        ]);

        $nextOrdem = ((int) ChecklistItem::where('checklist_modelo_id', $modelo)->max('ordem')) + 1;

        ChecklistItem::query()->create([
            'checklist_modelo_id' => $modeloModel->id,
            'descricao' => trim((string) $validated['descricao']),
            'ordem' => $nextOrdem,
            'ativo' => true,
        ]);

        return $this->success(
            ['modelo' => $this->mapModeloDetail($modeloModel->fresh(['itens', 'tipoEquipamento']) ?? $modeloModel)],
            request: $request
        );
    }

    public function updateItem(Request $request, string $tipo, int $modelo, int $item): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $modeloModel = ChecklistModelo::where('id', $modelo)
            ->where('checklist_tipo_id', $checklistTipo->id)
            ->first();

        if (! $modeloModel instanceof ChecklistModelo) {
            return $this->error(
                'Modelo de checklist nao encontrado.',
                404,
                'CHECKLIST_MODEL_NOT_FOUND',
                null,
                request: $request
            );
        }

        $itemModel = ChecklistItem::where('id', $item)
            ->where('checklist_modelo_id', $modelo)
            ->first();

        if (! $itemModel instanceof ChecklistItem) {
            return $this->error(
                'Item de checklist nao encontrado.',
                404,
                'CHECKLIST_ITEM_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'descricao' => ['required', 'string', 'max:255'],
        ]);

        $itemModel->descricao = trim((string) $validated['descricao']);
        $itemModel->save();

        return $this->success(
            ['modelo' => $this->mapModeloDetail($modeloModel->fresh(['itens', 'tipoEquipamento']) ?? $modeloModel)],
            request: $request
        );
    }

    public function destroyItem(Request $request, string $tipo, int $modelo, int $item): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $modeloModel = ChecklistModelo::where('id', $modelo)
            ->where('checklist_tipo_id', $checklistTipo->id)
            ->first();

        if (! $modeloModel instanceof ChecklistModelo) {
            return $this->error(
                'Modelo de checklist nao encontrado.',
                404,
                'CHECKLIST_MODEL_NOT_FOUND',
                null,
                request: $request
            );
        }

        $itemModel = ChecklistItem::where('id', $item)
            ->where('checklist_modelo_id', $modelo)
            ->first();

        if (! $itemModel instanceof ChecklistItem) {
            return $this->error(
                'Item de checklist nao encontrado.',
                404,
                'CHECKLIST_ITEM_NOT_FOUND',
                null,
                request: $request
            );
        }

        $itemModel->delete();

        return $this->success(
            ['modelo' => $this->mapModeloDetail($modeloModel->fresh(['itens', 'tipoEquipamento']) ?? $modeloModel)],
            request: $request
        );
    }

    public function moveItem(Request $request, string $tipo, int $modelo, int $item): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $modeloModel = ChecklistModelo::where('id', $modelo)
            ->where('checklist_tipo_id', $checklistTipo->id)
            ->first();

        if (! $modeloModel instanceof ChecklistModelo) {
            return $this->error(
                'Modelo de checklist nao encontrado.',
                404,
                'CHECKLIST_MODEL_NOT_FOUND',
                null,
                request: $request
            );
        }

        $itemModel = ChecklistItem::where('id', $item)
            ->where('checklist_modelo_id', $modelo)
            ->first();

        if (! $itemModel instanceof ChecklistItem) {
            return $this->error(
                'Item de checklist nao encontrado.',
                404,
                'CHECKLIST_ITEM_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:up,down'],
        ]);

        DB::transaction(function () use ($modelo, $itemModel, $validated): void {
            $siblings = ChecklistItem::query()
                ->where('checklist_modelo_id', $modelo)
                ->orderBy('ordem')
                ->get();

            $currentIndex = $siblings->search(
                fn (ChecklistItem $sibling): bool => $sibling->id === $itemModel->id
            );

            if ($currentIndex === false) {
                return;
            }

            $adjacentIndex = $validated['direction'] === 'up' ? $currentIndex - 1 : $currentIndex + 1;

            if ($adjacentIndex < 0 || $adjacentIndex >= $siblings->count()) {
                return;
            }

            $adjacent = $siblings->get($adjacentIndex);
            $current = $siblings->get($currentIndex);

            $currentOrdem = $current->ordem;
            $adjacentOrdem = $adjacent->ordem;

            $current->update(['ordem' => $adjacentOrdem]);
            $adjacent->update(['ordem' => $currentOrdem]);
        });

        return $this->success(
            ['modelo' => $this->mapModeloDetail($modeloModel->fresh(['itens', 'tipoEquipamento']) ?? $modeloModel)],
            request: $request
        );
    }

    public function toggleItemActive(Request $request, string $tipo, int $modelo, int $item): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $checklistTipo = $this->resolveChecklistTipo($tipo);

        if (! $checklistTipo instanceof ChecklistTipo) {
            return $this->error(
                'Tipo de checklist invalido.',
                404,
                'CHECKLIST_TYPE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $modeloModel = ChecklistModelo::where('id', $modelo)
            ->where('checklist_tipo_id', $checklistTipo->id)
            ->first();

        if (! $modeloModel instanceof ChecklistModelo) {
            return $this->error(
                'Modelo de checklist nao encontrado.',
                404,
                'CHECKLIST_MODEL_NOT_FOUND',
                null,
                request: $request
            );
        }

        $itemModel = ChecklistItem::where('id', $item)
            ->where('checklist_modelo_id', $modelo)
            ->first();

        if (! $itemModel instanceof ChecklistItem) {
            return $this->error(
                'Item de checklist nao encontrado.',
                404,
                'CHECKLIST_ITEM_NOT_FOUND',
                null,
                request: $request
            );
        }

        $itemModel->forceFill([
            'ativo' => ! (bool) $itemModel->ativo,
            'updated_at' => now(),
        ])->save();

        return $this->success(
            ['modelo' => $this->mapModeloDetail($modeloModel->fresh(['itens', 'tipoEquipamento']) ?? $modeloModel)],
            request: $request
        );
    }

    private function resolveChecklistTipo(string $tipo): ?ChecklistTipo
    {
        return ChecklistTipo::where('codigo', $tipo)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapModeloDetail(ChecklistModelo $modelo): array
    {
        return [
            'id' => (int) $modelo->id,
            'checklist_tipo_id' => (int) $modelo->checklist_tipo_id,
            'tipo_equipamento_id' => (int) $modelo->tipo_equipamento_id,
            'tipo_equipamento_nome' => (string) ($modelo->tipoEquipamento?->nome ?? ''),
            'nome' => (string) ($modelo->nome ?? ''),
            'descricao' => $modelo->descricao,
            'ordem' => (int) ($modelo->ordem ?? 0),
            'ativo' => (bool) ($modelo->ativo ?? false),
            'created_at' => $modelo->created_at?->toDateTimeString(),
            'updated_at' => $modelo->updated_at?->toDateTimeString(),
            'itens' => $modelo->itens->map(static fn (ChecklistItem $item): array => [
                'id' => (int) $item->id,
                'descricao' => (string) ($item->descricao ?? ''),
                'ordem' => (int) ($item->ordem ?? 0),
                'ativo' => (bool) ($item->ativo ?? false),
            ])->values()->all(),
        ];
    }
}
