<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\UpsertComissaoTecnicoRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroCategoriaRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroDreGrupoRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroDreSubgrupoRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroFormaPagamentoRequest;
use App\Models\ComissaoTecnico;
use App\Models\Configuration;
use App\Models\FinanceiroCategoria;
use App\Models\FinanceiroDreGrupo;
use App\Models\FinanceiroDreSubgrupo;
use App\Models\FinanceiroFormaPagamento;
use App\Services\Financeiro\FinanceiroCartaoService;
use App\Services\Financeiro\FinanceiroContaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceiroCatalogController extends BaseApiController
{
    public function __construct(
        private readonly FinanceiroCartaoService $financeiroCartaoService,
        private readonly FinanceiroContaService $financeiroContaService
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
                'formas_pagamento' => FinanceiroFormaPagamento::query()->ordenado()->get(),
                'cartao' => $this->financeiroCartaoService->buildActiveDataset(),
                'contas_financeiras' => $this->financeiroContaService->options(),
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

    public function storeFormaPagamento(UpsertFinanceiroFormaPagamentoRequest $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validated();

        $forma = FinanceiroFormaPagamento::create([
            'codigo' => $this->generateFormaPagamentoCode((string) $validated['nome']),
            'nome' => trim((string) $validated['nome']),
            'is_cartao' => (bool) ($validated['is_cartao'] ?? false),
            // Formas criadas pelo usuário nunca são de sistema e não entram na
            // coluna-resumo, que é um ENUM fixo no banco legado.
            'sistema' => false,
            'resumo_enum' => false,
            'ordem_exibicao' => (int) ($validated['ordem_exibicao'] ?? 900),
            'ativo' => (bool) ($validated['ativo'] ?? true),
        ]);

        return $this->success(['forma_pagamento' => $forma], 201, request: $request);
    }

    public function updateFormaPagamento(
        UpsertFinanceiroFormaPagamentoRequest $request,
        FinanceiroFormaPagamento $formaPagamento
    ): JsonResponse {
        $this->authorize('financeiro:editar');

        $validated = $request->validated();
        $payload = [];

        if (array_key_exists('nome', $validated)) {
            $payload['nome'] = trim((string) $validated['nome']);
        }
        if (array_key_exists('ordem_exibicao', $validated)) {
            $payload['ordem_exibicao'] = (int) $validated['ordem_exibicao'];
        }
        if (array_key_exists('ativo', $validated)) {
            $payload['ativo'] = (bool) $validated['ativo'];
        }

        // O código nunca muda depois de criado (registros históricos apontam
        // para ele) e o tipo cartão das formas de sistema é intocável, porque
        // toda a lógica de operadora/bandeira/taxa depende dele.
        if (! $formaPagamento->sistema && array_key_exists('is_cartao', $validated)) {
            $payload['is_cartao'] = (bool) $validated['is_cartao'];
        }

        $formaPagamento->update($payload);

        return $this->success(['forma_pagamento' => $formaPagamento->refresh()], request: $request);
    }

    public function destroyFormaPagamento(Request $request, FinanceiroFormaPagamento $formaPagamento): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        if ($formaPagamento->sistema) {
            return $this->error(
                'Formas de pagamento do sistema não podem ser excluídas. Desative-a se não quiser mais usá-la.',
                422,
                'FORMA_PAGAMENTO_PROTEGIDA',
                request: $request
            );
        }

        if ($this->formaPagamentoEmUso((string) $formaPagamento->codigo)) {
            return $this->error(
                'Esta forma de pagamento já foi usada em lançamentos e não pode ser excluída. Desative-a para tirá-la das listas.',
                422,
                'FORMA_PAGAMENTO_EM_USO',
                request: $request
            );
        }

        $formaPagamento->delete();

        return $this->success(null, request: $request);
    }

    /**
     * Gera um código estável (slug) a partir do nome, garantindo unicidade.
     * O código é o valor realmente gravado nos lançamentos/recebimentos.
     */
    private function generateFormaPagamentoCode(string $nome): string
    {
        $base = Str::of($nome)->slug('_')->limit(36, '')->toString();
        if ($base === '') {
            $base = 'forma';
        }

        $codigo = $base;
        $suffix = 2;

        while (FinanceiroFormaPagamento::query()->where('codigo', $codigo)->exists()) {
            $codigo = $base . '_' . $suffix;
            $suffix++;
        }

        return $codigo;
    }

    private function formaPagamentoEmUso(string $codigo): bool
    {
        return DB::table('financeiro_movimentos')->where('forma_pagamento', $codigo)->exists()
            || DB::table('financeiro_conta_defaults')->where('forma_pagamento', $codigo)->exists()
            || DB::table('financeiro')->where('forma_pagamento', $codigo)->exists();
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
