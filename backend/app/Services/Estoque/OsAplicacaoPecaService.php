<?php

namespace App\Services\Estoque;

use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Movimentacao;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Peca;
use App\Services\Orders\OrderEventService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aplicacao de peca numa Ordem de Servico.
 *
 * E o elo que faltava para o CMV existir. Ate aqui, NENHUM caminho do sistema
 * criava movimentacao de estoque a partir de uma OS: o consumo era 100% manual,
 * ninguem lancava, e o resultado era CMV R$ 0,00 em 2.187 OS entregues e pagas.
 * A margem de contribuicao e o DRE gerencial estavam prontos e famintos.
 *
 * Nao cria tabela intermediaria: a movimentacao JA E o registro de aplicacao
 * (`os_id` + `saida`). `os_itens` tem colunas para isso desde o legado e
 * ninguem as escreve desde 30/04/2026 — ressuscita-la seria um segundo lugar
 * para a mesma verdade.
 */
class OsAplicacaoPecaService
{
    public function __construct(
        private readonly EstoqueMovimentacaoService $estoqueMovimentacaoService,
        private readonly OrderEventService $orderEventService,
        // specs/040: a baixa consome a reserva que o orcamento aprovado criou.
        private readonly EstoqueReservaService $estoqueReservaService,
    ) {
    }

    /**
     * O que o modal precisa: pecas do orcamento aprovado, o que ja baixou e o
     * que falta.
     *
     * Pre-preencher e o que decide a adocao. Se o tecnico tiver que digitar
     * tudo de novo, ele nao usa, e o CMV continua zero.
     *
     * @return array<string, mixed>
     */
    public function contexto(int $osId): array
    {
        $orcamento = $this->orcamentoAprovado($osId);
        $jaBaixado = $this->quantidadesJaBaixadas($osId);

        $itens = [];

        if ($orcamento !== null) {
            $linhas = BudgetItem::query()
                ->where('orcamento_id', $orcamento->id)
                ->where('tipo_item', 'peca')
                ->whereNotNull('referencia_id')
                ->get();

            $pecaIds = $linhas->pluck('referencia_id')->filter()->unique()->map(static fn ($id): int => (int) $id)->all();

            $pecas = Peca::query()
                ->whereIn('id', $pecaIds)
                ->get()
                ->keyBy('id');

            // specs/040: o modal precisa separar "reservado para mim" de
            // "reservado para outro orcamento". O primeiro nao atrapalha esta
            // baixa; o segundo e o que pode faltar na gaveta.
            $disponibilidade = $this->estoqueReservaService->disponibilidade($pecaIds, (int) $orcamento->id);

            foreach ($linhas as $linha) {
                $pecaId = (int) $linha->referencia_id;
                $peca = $pecas->get($pecaId);

                if (! $peca instanceof Peca) {
                    continue;
                }

                $orcada = round((float) ($linha->quantidade ?? 0), 4);
                $baixada = round((float) ($jaBaixado[$pecaId] ?? 0), 4);

                $saldos = $disponibilidade[$pecaId] ?? [
                    'saldo' => 0.0,
                    'reservado' => 0.0,
                    'reservado_proprio' => 0.0,
                    'disponivel' => 0.0,
                ];

                $itens[] = [
                    'peca_id' => $pecaId,
                    'codigo' => (string) ($peca->codigo ?? ''),
                    'nome' => (string) ($peca->nome ?? ''),
                    'unidade' => (string) ($peca->unidade ?? 'UN'),
                    'saldo_estoque' => round((float) ($peca->quantidade_atual ?? 0), 4),
                    // Disponivel JA com a reserva deste orcamento somada de
                    // volta: e contra este numero que a baixa e conferida.
                    'saldo_disponivel' => (float) $saldos['disponivel'],
                    'reservado_para_esta_os' => (float) $saldos['reservado_proprio'],
                    'reservado_por_terceiros' => round(
                        (float) $saldos['reservado'] - (float) $saldos['reservado_proprio'],
                        4
                    ),
                    'quantidade_orcada' => $orcada,
                    'quantidade_baixada' => $baixada,
                    // O que o modal ja vem preenchido: o que falta aplicar.
                    'quantidade_sugerida' => max(0, round($orcada - $baixada, 4)),
                ];
            }
        }

        return [
            'orcamento_id' => $orcamento?->id,
            'orcamento_numero' => (string) ($orcamento->numero ?? ''),
            'itens' => $itens,
            'total_ja_baixado' => count($jaBaixado),
        ];
    }

    /**
     * Aplica as pecas informadas, gerando as saidas de estoque da OS.
     *
     * @param array<int, array<string, mixed>> $itens
     * @return array{aplicadas: int, divergente: bool, faltas: array<int, array<string, mixed>>}
     *
     * @throws SaldoInsuficienteException
     */
    public function aplicar(Order $order, array $itens, ?int $actorId, bool $permitirNegativo = false): array
    {
        $linhas = [];

        foreach ($itens as $item) {
            $pecaId = (int) ($item['peca_id'] ?? 0);
            $quantidade = round((float) ($item['quantidade'] ?? 0), 4);

            if ($pecaId <= 0 || $quantidade <= 0) {
                continue;
            }

            $linhas[] = [
                'peca_id' => $pecaId,
                'quantidade' => $quantidade,
                'os_id' => (int) $order->id,
                'motivo' => 'Aplicada na OS '.$order->numero_os,
            ];
        }

        if ($linhas === []) {
            return ['aplicadas' => 0, 'divergente' => false, 'faltas' => []];
        }

        // A reserva desta OS nasceu do orcamento aprovado dela. Informa-la ao
        // motor faz a propria reserva voltar para o disponivel — senao a peca
        // que o orcamento guardou bloquearia a baixa que ela protegia.
        $orcamentoId = (int) ($this->orcamentoAprovado((int) $order->id)?->id ?? 0);

        return DB::transaction(function () use ($order, $linhas, $actorId, $permitirNegativo, $orcamentoId): array {
            $resultado = $this->estoqueMovimentacaoService->registrarLote(
                $linhas,
                [
                    'tipo' => EstoqueMovimentacaoService::TIPO_SAIDA,
                    'motivo' => 'Aplicada na OS '.$order->numero_os,
                    'responsavel_id' => $actorId,
                    'orcamento_id' => $orcamentoId,
                ],
                $permitirNegativo
            );

            // Depois de registrarLote(), com o lock das linhas de `pecas` ainda
            // de pe: o que saiu de verdade deixa de estar prometido.
            if ($orcamentoId > 0) {
                $this->estoqueReservaService->consumir($orcamentoId, $linhas);
            }

            // A timeline da OS e onde isso tem de aparecer: quem aplicou, o que
            // aplicou e quando. Sem evento, a peca some do estoque e a OS nao
            // conta a historia.
            $this->orderEventService->record(
                (int) $order->id,
                OrderEvent::CATEGORIA_REGISTRO,
                OrderEvent::TIPO_DADOS_TECNICOS_ATUALIZADOS,
                'Peças aplicadas na OS',
                sprintf('%d item(ns) baixado(s) do estoque.', count($linhas)),
                ['itens' => $linhas, 'divergente' => $resultado['divergente']],
                $actorId,
                OrderEvent::ORIGEM_USUARIO
            );

            return [
                'aplicadas' => count($linhas),
                'divergente' => (bool) $resultado['divergente'],
                'faltas' => $resultado['faltas'],
            ];
        });
    }

    private function orcamentoAprovado(int $osId): ?Budget
    {
        return Budget::query()
            ->where('os_id', $osId)
            ->where(static function (Builder $query): void {
                $query
                    ->whereIn('status', [Budget::STATUS_APPROVED, Budget::STATUS_CONVERTED])
                    ->orWhereNotNull('aprovado_em');
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<int, float>
     */
    private function quantidadesJaBaixadas(int $osId): array
    {
        return Movimentacao::query()
            ->where('os_id', $osId)
            ->where('tipo', EstoqueMovimentacaoService::TIPO_SAIDA)
            ->selectRaw('peca_id, COALESCE(SUM(quantidade), 0) as total')
            ->groupBy('peca_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(int) $row->peca_id => (float) $row->total])
            ->all();
    }
}
