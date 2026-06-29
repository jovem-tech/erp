<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Financeiro\PrecificacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceiroPrecificacaoController extends BaseApiController
{
    public function __construct(
        private readonly PrecificacaoService $precificacaoService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        return $this->success([
            'precificacao' => $this->precificacaoService->payload(),
        ], request: $request);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validate([
            'precificacao_peca_base' => ['nullable', 'string', 'in:custo,venda'],
            'precificacao_peca_encargos_percentual' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'precificacao_peca_margem_percentual' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'precificacao_peca_respeitar_preco_venda' => ['nullable', 'boolean'],
            'precificacao_peca_usa_componentes' => ['nullable', 'boolean'],
            'precificacao_servico_custo_hora_produtiva' => ['nullable', 'numeric', 'min:0'],
            'precificacao_servico_margem_percentual' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'precificacao_servico_taxa_recebimento_percentual' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'precificacao_servico_imposto_percentual' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'precificacao_servico_tempo_padrao_horas' => ['nullable', 'numeric', 'min:0.1', 'max:200'],
            'precificacao_servico_usa_componentes' => ['nullable', 'boolean'],
            'precificacao_servico_aplicar_catalogo' => ['nullable', 'boolean'],
            'precificacao_servico_aplicar_piso' => ['nullable', 'boolean'],
        ]);

        return $this->success([
            'precificacao' => $this->precificacaoService->save($validated + $request->all()),
        ], request: $request);
    }

    public function simulatePeca(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $validated = $request->validate([
            'peca_id' => ['nullable', 'integer', 'min:1'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
            'categoria' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->success([
            'simulation' => $this->precificacaoService->simulatePeca($validated),
        ], request: $request);
    }

    public function simulateServico(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $validated = $request->validate([
            'servico_id' => ['nullable', 'integer', 'min:1'],
            'tempo_padrao_horas' => ['nullable', 'numeric', 'min:0'],
            'custo_direto_padrao' => ['nullable', 'numeric', 'min:0'],
            'valor_cadastro' => ['nullable', 'numeric', 'min:0'],
            'tipo_equipamento' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->success([
            'simulation' => $this->precificacaoService->simulateServico($validated),
        ], request: $request);
    }
}
