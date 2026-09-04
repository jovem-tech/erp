<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EquipmentType;
use App\Models\EstoqueCategoria;
use App\Models\EstoqueSubcategoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD da taxonomia de estoque (Grupo → Categoria → Subcategoria).
 *
 * Grupo é o catálogo "Tipo de equipamento" (`equipamentos_tipos`), já
 * compartilhado por OS/serviços/equipamentos de cliente — não existia
 * controller nenhum para ele antes desta tela (só um endpoint de leitura em
 * KnowledgeController). Desativar um Grupo aqui também o remove das outras
 * telas do sistema que o consultam, não só do estoque.
 *
 * Nunca exclui de verdade — mesma convenção de Peca/Servico no resto do
 * sistema: toda "exclusão" aqui é `ativo = false`. Além de manter o padrão,
 * evita colidir com o `restrictOnDelete()` das FKs em
 * pecas/estoque_categorias/estoque_subcategorias — uma exclusão real de um nó
 * em uso quebraria com um erro de banco cru em vez de uma mensagem tratada.
 */
class EstoqueCatalogController extends BaseApiController
{
    public function indexGrupos(Request $request): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        return $this->success([
            'grupos' => EquipmentType::query()->orderBy('nome')->get(['id', 'nome', 'ativo']),
        ], request: $request);
    }

    public function storeGrupo(Request $request): JsonResponse
    {
        $this->authorize('estoque:editar');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:120', Rule::unique('equipamentos_tipos', 'nome')],
        ]);

        $grupo = EquipmentType::query()->create([
            'nome' => trim((string) $validated['nome']),
            'ativo' => true,
        ]);

        return $this->success(['grupo' => $grupo], 201, request: $request);
    }

    public function updateGrupo(Request $request, EquipmentType $grupo): JsonResponse
    {
        $this->authorize('estoque:editar');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:120', Rule::unique('equipamentos_tipos', 'nome')->ignore($grupo->id)],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $grupo->update([
            'nome' => trim((string) $validated['nome']),
            'ativo' => array_key_exists('ativo', $validated) ? (bool) $validated['ativo'] : (bool) $grupo->ativo,
        ]);

        return $this->success(['grupo' => $grupo->refresh()], request: $request);
    }

    public function destroyGrupo(Request $request, EquipmentType $grupo): JsonResponse
    {
        $this->authorize('estoque:excluir');

        $grupo->update(['ativo' => false]);

        return $this->success(null, request: $request);
    }

    public function indexCategorias(Request $request): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        $tipoEquipamentoId = (int) $request->query('tipo_equipamento_id', 0);

        return $this->success([
            'categorias' => EstoqueCategoria::query()
                ->with('tipoEquipamento:id,nome')
                ->when($tipoEquipamentoId > 0, fn ($query) => $query->where('tipo_equipamento_id', $tipoEquipamentoId))
                ->orderBy('ordem')
                ->orderBy('nome')
                ->get(),
        ], request: $request);
    }

    public function storeCategoria(Request $request): JsonResponse
    {
        $this->authorize('estoque:editar');

        $validated = $request->validate([
            'tipo_equipamento_id' => ['required', 'integer', 'exists:equipamentos_tipos,id'],
            'nome' => [
                'required',
                'string',
                'max:120',
                Rule::unique('estoque_categorias', 'nome')
                    ->where(fn ($query) => $query->where('tipo_equipamento_id', $request->input('tipo_equipamento_id'))),
            ],
            'ordem' => ['nullable', 'integer'],
        ]);

        $categoria = EstoqueCategoria::query()->create([
            'tipo_equipamento_id' => (int) $validated['tipo_equipamento_id'],
            'nome' => trim((string) $validated['nome']),
            'ordem' => (int) ($validated['ordem'] ?? 0),
            'ativo' => true,
        ]);

        return $this->success(['categoria' => $categoria->load('tipoEquipamento:id,nome')], 201, request: $request);
    }

    public function updateCategoria(Request $request, EstoqueCategoria $categoria): JsonResponse
    {
        $this->authorize('estoque:editar');

        $validated = $request->validate([
            'tipo_equipamento_id' => ['required', 'integer', 'exists:equipamentos_tipos,id'],
            'nome' => [
                'required',
                'string',
                'max:120',
                Rule::unique('estoque_categorias', 'nome')
                    ->where(fn ($query) => $query->where('tipo_equipamento_id', $request->input('tipo_equipamento_id')))
                    ->ignore($categoria->id),
            ],
            'ordem' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $categoria->update([
            'tipo_equipamento_id' => (int) $validated['tipo_equipamento_id'],
            'nome' => trim((string) $validated['nome']),
            'ordem' => (int) ($validated['ordem'] ?? $categoria->ordem),
            'ativo' => array_key_exists('ativo', $validated) ? (bool) $validated['ativo'] : (bool) $categoria->ativo,
        ]);

        return $this->success(['categoria' => $categoria->refresh()->load('tipoEquipamento:id,nome')], request: $request);
    }

    public function destroyCategoria(Request $request, EstoqueCategoria $categoria): JsonResponse
    {
        $this->authorize('estoque:excluir');

        $categoria->update(['ativo' => false]);

        return $this->success(null, request: $request);
    }

    public function indexSubcategorias(Request $request): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        $categoriaId = (int) $request->query('categoria_id', 0);

        return $this->success([
            'subcategorias' => EstoqueSubcategoria::query()
                ->with('categoria:id,nome,tipo_equipamento_id')
                ->when($categoriaId > 0, fn ($query) => $query->where('categoria_id', $categoriaId))
                ->orderBy('ordem')
                ->orderBy('nome')
                ->get(),
        ], request: $request);
    }

    public function storeSubcategoria(Request $request): JsonResponse
    {
        $this->authorize('estoque:editar');

        $validated = $request->validate([
            'categoria_id' => ['required', 'integer', 'exists:estoque_categorias,id'],
            'nome' => [
                'required',
                'string',
                'max:120',
                Rule::unique('estoque_subcategorias', 'nome')
                    ->where(fn ($query) => $query->where('categoria_id', $request->input('categoria_id'))),
            ],
            'ordem' => ['nullable', 'integer'],
        ]);

        $subcategoria = EstoqueSubcategoria::query()->create([
            'categoria_id' => (int) $validated['categoria_id'],
            'nome' => trim((string) $validated['nome']),
            'ordem' => (int) ($validated['ordem'] ?? 0),
            'ativo' => true,
        ]);

        return $this->success(['subcategoria' => $subcategoria->load('categoria:id,nome,tipo_equipamento_id')], 201, request: $request);
    }

    public function updateSubcategoria(Request $request, EstoqueSubcategoria $subcategoria): JsonResponse
    {
        $this->authorize('estoque:editar');

        $validated = $request->validate([
            'categoria_id' => ['required', 'integer', 'exists:estoque_categorias,id'],
            'nome' => [
                'required',
                'string',
                'max:120',
                Rule::unique('estoque_subcategorias', 'nome')
                    ->where(fn ($query) => $query->where('categoria_id', $request->input('categoria_id')))
                    ->ignore($subcategoria->id),
            ],
            'ordem' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $subcategoria->update([
            'categoria_id' => (int) $validated['categoria_id'],
            'nome' => trim((string) $validated['nome']),
            'ordem' => (int) ($validated['ordem'] ?? $subcategoria->ordem),
            'ativo' => array_key_exists('ativo', $validated) ? (bool) $validated['ativo'] : (bool) $subcategoria->ativo,
        ]);

        return $this->success(['subcategoria' => $subcategoria->refresh()->load('categoria:id,nome,tipo_equipamento_id')], request: $request);
    }

    public function destroySubcategoria(Request $request, EstoqueSubcategoria $subcategoria): JsonResponse
    {
        $this->authorize('estoque:excluir');

        $subcategoria->update(['ativo' => false]);

        return $this->success(null, request: $request);
    }
}
