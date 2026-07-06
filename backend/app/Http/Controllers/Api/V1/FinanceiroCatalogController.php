<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\UpsertComissaoTecnicoRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroCategoriaRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroDreGrupoRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroDreSubgrupoRequest;
use App\Models\ComissaoTecnico;
use App\Models\Configuration;
use App\Models\FinanceiroCategoria;
use App\Models\FinanceiroDreGrupo;
use App\Models\FinanceiroDreSubgrupo;
use App\Services\Financeiro\FinanceiroCartaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceiroCatalogController extends BaseApiController
{
    public function __construct(
        private readonly FinanceiroCartaoService $financeiroCartaoService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        return $this->success(
            [
                'categorias' => FinanceiroCategoria::query()
                    ->with(['dre_grupo', 'dre_subgrupo'])
                    ->orderBy('ordem_exibicao')
                    ->orderBy('nome')
                    ->get(),
                'dre_grupos' => FinanceiroDreGrupo::query()
                    ->orderBy('ordem_exibicao')
                    ->orderBy('nome')
                    ->get(),
                'dre_subgrupos' => FinanceiroDreSubgrupo::query()
                    ->with('grupo')
                    ->orderBy('ordem_exibicao')
                    ->orderBy('nome')
                    ->get(),
                'comissoes_tecnicos' => ComissaoTecnico::query()
                    ->with('tecnico')
                    ->orderBy('tecnico_id')
                    ->get(),
                'comissao_percentual_padrao' => (float) (Configuration::query()
                    ->where('chave', 'comissao_tecnico_percentual_padrao')
                    ->value('valor') ?? 0),
                'cartao' => $this->financeiroCartaoService->buildActiveDataset(),
            ],
            request: $request
        );
    }

    public function storeComissao(UpsertComissaoTecnicoRequest $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $comissao = ComissaoTecnico::create($request->validated());

        return $this->success(['comissao' => $comissao], 201, request: $request);
    }

    public function updateComissao(UpsertComissaoTecnicoRequest $request, ComissaoTecnico $comissao): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $comissao->update($request->validated());

        return $this->success(['comissao' => $comissao], request: $request);
    }

    public function destroyComissao(Request $request, ComissaoTecnico $comissao): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        $comissao->delete();

        return $this->success(null, request: $request);
    }

    public function updateComissaoPadrao(Request $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validate([
            'percentual_padrao' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        Configuration::query()->updateOrInsert(
            ['chave' => 'comissao_tecnico_percentual_padrao'],
            ['valor' => (string) $validated['percentual_padrao'], 'tipo' => 'numero', 'updated_at' => now(), 'created_at' => now()]
        );

        return $this->success(['comissao_percentual_padrao' => (float) $validated['percentual_padrao']], request: $request);
    }

    public function storeCategoria(UpsertFinanceiroCategoriaRequest $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $categoria = FinanceiroCategoria::create($request->validated());

        return $this->success(['categoria' => $categoria], 201, request: $request);
    }

    public function updateCategoria(UpsertFinanceiroCategoriaRequest $request, FinanceiroCategoria $categoria): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $categoria->update($request->validated());

        return $this->success(['categoria' => $categoria], request: $request);
    }

    public function destroyCategoria(Request $request, FinanceiroCategoria $categoria): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        $categoria->delete();

        return $this->success(null, request: $request);
    }

    public function storeGrupo(UpsertFinanceiroDreGrupoRequest $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $grupo = FinanceiroDreGrupo::create($request->validated());

        return $this->success(['dre_grupo' => $grupo], 201, request: $request);
    }

    public function updateGrupo(UpsertFinanceiroDreGrupoRequest $request, FinanceiroDreGrupo $grupo): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $grupo->update($request->validated());

        return $this->success(['dre_grupo' => $grupo], request: $request);
    }

    public function destroyGrupo(Request $request, FinanceiroDreGrupo $grupo): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        $grupo->delete();

        return $this->success(null, request: $request);
    }

    public function storeSubgrupo(UpsertFinanceiroDreSubgrupoRequest $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $subgrupo = FinanceiroDreSubgrupo::create($request->validated());

        return $this->success(['dre_subgrupo' => $subgrupo], 201, request: $request);
    }

    public function updateSubgrupo(UpsertFinanceiroDreSubgrupoRequest $request, FinanceiroDreSubgrupo $subgrupo): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $subgrupo->update($request->validated());

        return $this->success(['dre_subgrupo' => $subgrupo], request: $request);
    }

    public function destroySubgrupo(Request $request, FinanceiroDreSubgrupo $subgrupo): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        $subgrupo->delete();

        return $this->success(null, request: $request);
    }
}
