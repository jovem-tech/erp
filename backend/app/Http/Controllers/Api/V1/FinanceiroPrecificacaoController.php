<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Financeiro\PrecificacaoService;
use App\Support\RegimeTributario;
use App\Support\VisibilidadeCusto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            // Decide se o imposto é custo VARIÁVEL (Simples: proporcional ao
            // faturamento, desconta da margem de cada OS) ou FIXO (MEI: DAS de
            // valor fixo mensal, pertence ao ponto de equilíbrio).
            RegimeTributario::CHAVE => ['nullable', 'string', Rule::in(RegimeTributario::codigos())],
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

    /**
     * Simulacao de preco de peca.
     *
     * Serve dois publicos desde specs/037: a tela de precificacao (que sempre
     * teve `financeiro:visualizar`) e o CADASTRO DE PECA, onde quem digita e
     * frequentemente um estoquista sem permissao financeira nenhuma.
     *
     * Exigir `financeiro:visualizar` aqui deixaria o preco sugerido inacessivel
     * justamente para quem cadastra. A resposta e redigida por visibilidade —
     * o estoquista recebe o valor sugerido e o semaforo, nao a composicao de
     * custo.
     */
    public function simulatePeca(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        if (! $user->can('financeiro:visualizar')
            && ! $user->can('precificacao:visualizar')
            && ! $user->can('estoque:criar')
            && ! $user->can('estoque:editar')) {
            return $this->error(
                'Você não tem permissão para simular preços.',
                403,
                'PRECIFICACAO_NAO_AUTORIZADO',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'peca_id' => ['nullable', 'integer', 'min:1'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
            'categoria' => ['nullable', 'string', 'max:120'],
        ]);

        $simulacao = $this->precificacaoService->simulatePeca($validated);

        return $this->success([
            'simulation' => $this->redigirSimulacao($simulacao, $user),
        ], request: $request);
    }

    /**
     * Remove a composicao de custo de quem nao pode ve-la.
     *
     * A chave nao pode existir no JSON: esconder na view deixa o numero no
     * devtools. Mesma regra de PrecoQuote::toArray(), aplicada aqui porque a
     * simulacao devolve o array cru do motor.
     *
     * @param array<string, mixed> $simulacao
     * @return array<string, mixed>
     */
    private function redigirSimulacao(array $simulacao, mixed $user): array
    {
        $visibilidade = VisibilidadeCusto::paraUsuario($user);
        $simulacao['visibilidade_custo'] = $visibilidade;

        if ($visibilidade === VisibilidadeCusto::COMPLETO) {
            return $simulacao;
        }

        foreach (VisibilidadeCusto::CAMPOS_SENSIVEIS as $campo) {
            unset($simulacao[$campo]);
        }

        // `percentual_encargos` nao esta na lista generica porque em outros
        // contextos e so um parametro; aqui ele revela a composicao do custo.
        unset($simulacao['percentual_encargos'], $simulacao['preco_base'], $simulacao['categoria_override']);

        return $simulacao;
    }

    /**
     * Simulacao de preco de servico.
     *
     * Mesma razao de simulatePeca(): quem cadastra servico frequentemente nao
     * tem permissao financeira, e exigi-la deixaria a sugestao inacessivel
     * justamente para quem digita o preco. Resposta redigida por visibilidade.
     */
    public function simulateServico(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        if (! $user->can('financeiro:visualizar')
            && ! $user->can('precificacao:visualizar')
            && ! $user->can('servicos:criar')
            && ! $user->can('servicos:editar')) {
            return $this->error(
                'Você não tem permissão para simular preços.',
                403,
                'PRECIFICACAO_NAO_AUTORIZADO',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'servico_id' => ['nullable', 'integer', 'min:1'],
            'tempo_padrao_horas' => ['nullable', 'numeric', 'min:0'],
            'custo_direto_padrao' => ['nullable', 'numeric', 'min:0'],
            'valor_cadastro' => ['nullable', 'numeric', 'min:0'],
            'tipo_equipamento' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->success([
            'simulation' => $this->redigirSimulacao(
                $this->precificacaoService->simulateServico($validated),
                $user
            ),
        ], request: $request);
    }
}
