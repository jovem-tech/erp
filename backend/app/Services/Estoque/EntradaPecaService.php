<?php

namespace App\Services\Estoque;

use App\Models\Financeiro;
use App\Models\Movimentacao;
use App\Models\Peca;
use Illuminate\Support\Facades\DB;

/**
 * Entrada de peca no estoque a partir de um documento de compra (specs/039).
 *
 * Hoje o documento e o lancamento financeiro; amanha pode ser uma nota de
 * compra. Por isso o nome e generico: quando o modulo de Compras nascer, e este
 * servico que ele reusa, nao um segundo caminho de escrita.
 *
 * Antes desta entrega NENHUM caminho do sistema criava movimentacao de entrada:
 * o unico lugar que somava saldo era o CRUD de peca, gravando `quantidade_atual`
 * direto (os tres "furos" da 036). A saida ja tinha dono desde a 038; a entrada
 * nao tinha nenhum.
 *
 * Invariantes:
 *  1. Toda escrita de saldo passa por EstoqueMovimentacaoService — este servico
 *     nunca toca `quantidade_atual` por conta propria.
 *  2. Roda sempre dentro da transacao de quem chamou (FinanceiroService::create()
 *     e ::cancel() ja abrem a sua), para lancamento e estoque serem atomicos.
 *  3. `preco_custo` so e escrito quando a compra informou custo. Linha sem custo
 *     nao zera o cadastro.
 */
class EntradaPecaService
{
    public function __construct(
        private readonly EstoqueMovimentacaoService $estoqueMovimentacaoService
    ) {}

    /**
     * Da entrada nas pecas compradas por um lancamento.
     *
     * @param  array<int, array<string, mixed>>  $itens  peca_id, quantidade, custo_unitario?, preco_venda?
     * @return array{entradas: int, total: float}
     */
    public function registrarDeLancamento(Financeiro $financeiro, array $itens, ?int $actorId): array
    {
        $linhas = $this->montarLinhas($financeiro, $itens);

        if ($linhas === []) {
            return ['entradas' => 0, 'total' => 0.0];
        }

        return DB::transaction(function () use ($financeiro, $linhas, $actorId): array {
            $resultado = $this->estoqueMovimentacaoService->registrarLote(
                $linhas,
                [
                    'tipo' => EstoqueMovimentacaoService::TIPO_ENTRADA,
                    'motivo' => $this->motivoEntrada($financeiro),
                    'responsavel_id' => $actorId,
                ]
            );

            // Depois de registrarLote(), nao antes: la as pecas foram travadas
            // com lockForUpdate() e o lock vale ate o fim desta transacao. Quem
            // atualizar o cadastro fora dessa janela corre com outra compra da
            // mesma peca.
            $this->atualizarCadastro($linhas);

            return [
                'entradas' => count($linhas),
                'total' => (float) $resultado['total'],
            ];
        });
    }

    /**
     * Estorna as entradas que um lancamento gerou (cancelamento do titulo).
     *
     * Decisao diferente da 038 de proposito: la a peca foi fisicamente aplicada
     * num aparelho e desfazer seria mentira. Aqui o caso que motiva o
     * cancelamento e o EQUIVOCO — lancamento de peca errado, ou peca lancada que
     * nunca chegou —, e deixar o saldo inflado seria a mentira.
     *
     * Nao ha risco de estorno duplo: FinanceiroService::cancel() recusa titulo ja
     * cancelado antes de chegar aqui.
     *
     * `preco_custo` NAO volta ao valor anterior: nao existe historico dele, e
     * adivinhar seria pior que nao mexer. Quem cancela precisa saber disso — a
     * mensagem de sucesso diz.
     *
     * @return array{estornadas: int, faltas: array<int, array<string, mixed>>}
     *
     * @throws SaldoInsuficienteException  quando a peca ja saiu e $permitirNegativo e false
     */
    public function estornarDeLancamento(Financeiro $financeiro, ?int $actorId, bool $permitirNegativo = false): array
    {
        $entradas = Movimentacao::query()
            ->where('financeiro_id', (int) $financeiro->id)
            ->where('tipo', EstoqueMovimentacaoService::TIPO_ENTRADA)
            ->get();

        if ($entradas->isEmpty()) {
            return ['estornadas' => 0, 'faltas' => []];
        }

        $motivo = sprintf('Estorno de entrada — cancelamento do lançamento #%d', (int) $financeiro->id);

        $linhas = $entradas->map(static fn (Movimentacao $entrada): array => [
            'peca_id' => (int) $entrada->peca_id,
            'quantidade' => (float) $entrada->quantidade,
            'financeiro_id' => (int) $entrada->financeiro_id,
            'custo_unitario' => $entrada->custo_unitario !== null ? (float) $entrada->custo_unitario : null,
            'motivo' => $motivo,
        ])->all();

        $resultado = $this->estoqueMovimentacaoService->registrarLote(
            $linhas,
            [
                'tipo' => EstoqueMovimentacaoService::TIPO_SAIDA,
                'motivo' => $motivo,
                'responsavel_id' => $actorId,
            ],
            $permitirNegativo
        );

        return [
            'estornadas' => count($linhas),
            'faltas' => $resultado['faltas'],
        ];
    }

    /**
     * Quantas entradas um lancamento gerou. Usado para bloquear exclusao (409) e
     * para avisar no cancelamento.
     */
    public function contarEntradas(int $financeiroId): int
    {
        return Movimentacao::query()
            ->where('financeiro_id', $financeiroId)
            ->where('tipo', EstoqueMovimentacaoService::TIPO_ENTRADA)
            ->count();
    }

    /**
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<int, array<string, mixed>>
     */
    private function montarLinhas(Financeiro $financeiro, array $itens): array
    {
        $motivo = $this->motivoEntrada($financeiro);
        $linhas = [];

        foreach ($itens as $item) {
            $pecaId = (int) ($item['peca_id'] ?? 0);
            $quantidade = round((float) ($item['quantidade'] ?? 0), 4);

            if ($pecaId <= 0 || $quantidade <= 0) {
                continue;
            }

            $custo = $this->valorOuNulo($item['custo_unitario'] ?? null);

            $linhas[] = [
                'peca_id' => $pecaId,
                'quantidade' => $quantidade,
                'financeiro_id' => (int) $financeiro->id,
                'custo_unitario' => $custo,
                // O preco de venda nao vai para a movimentacao: nao e fato do
                // razao, e decisao comercial. Viaja so ate atualizarCadastro().
                'preco_venda' => $this->valorOuNulo($item['preco_venda'] ?? null),
                'motivo' => $motivo,
            ];
        }

        return $linhas;
    }

    /**
     * Atualiza `preco_custo` (e `preco_venda`, se o operador aplicou a sugestao).
     *
     * `preco_custo` e sobrescrito pelo custo da compra porque e fato novo trazido
     * pela nota — e assim que preco de fornecedor sobe. Nao conflita com a 036:
     * o que ela proibe e sobrescrever com a MEDIA MOVEL calculada; aqui e o
     * ultimo custo de compra, digitado por um humano. Quando
     * `pecas.custo_ultima_entrada` existir (036 Bloco B), esta escrita deve
     * alimenta-la tambem.
     *
     * `preco_venda` so e tocado quando veio no item, o que so acontece se o
     * operador clicou "Aplicar" na sugestao. Reprecificar em silencio uma peca
     * que o dono precificou a mao seria passar por cima dele.
     *
     * `update()` direto, nunca `save()`: read-modify-write em cadastro sob lock
     * e o padrao que a 038 removeu do sistema.
     *
     * @param  array<int, array<string, mixed>>  $linhas
     */
    private function atualizarCadastro(array $linhas): void
    {
        $agora = now();

        // Ultima linha vence quando a mesma peca aparece duas vezes na nota: e a
        // ordem em que o operador digitou, e a segunda linha e a correcao da
        // primeira com mais frequencia do que o contrario.
        $cadastro = [];

        foreach ($linhas as $linha) {
            $pecaId = (int) $linha['peca_id'];
            $atual = $cadastro[$pecaId] ?? [];

            if ($linha['custo_unitario'] !== null) {
                $atual['preco_custo'] = $linha['custo_unitario'];
            }

            if ($linha['preco_venda'] !== null) {
                $atual['preco_venda'] = $linha['preco_venda'];
            }

            if ($atual !== []) {
                $cadastro[$pecaId] = $atual;
            }
        }

        foreach ($cadastro as $pecaId => $campos) {
            Peca::query()
                ->whereKey($pecaId)
                ->update($campos + ['updated_at' => $agora]);
        }
    }

    private function motivoEntrada(Financeiro $financeiro): string
    {
        return sprintf('Entrada por compra — lançamento #%d', (int) $financeiro->id);
    }

    /**
     * Distingue "nao informado" de "zero informado".
     *
     * Custo ausente nao pode virar `preco_custo = 0`: "nao sei o custo" e uma
     * afirmacao diferente de "esta peca e gratis", e zerar o cadastro estragaria
     * a precificacao da peca inteira.
     */
    private function valorOuNulo(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $numero = round((float) $valor, 4);

        return $numero > 0 ? $numero : null;
    }
}
