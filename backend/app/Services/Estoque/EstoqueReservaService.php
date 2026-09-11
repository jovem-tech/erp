<?php

namespace App\Services\Estoque;

use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\EstoqueReserva;
use App\Models\Peca;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reserva de peca de estoque presa a um orcamento (specs/040).
 *
 * PROBLEMA QUE RESOLVE: `orcamento_itens` referencia peca por
 * `tipo_item='peca'` + `referencia_id`, sem FK e sem nenhuma validacao de saldo.
 * Dois orcamentos podiam prometer a mesma peca unica ao cliente e ninguem
 * descobria ate o tecnico abrir a gaveta.
 *
 * ---------------------------------------------------------------------------
 * POR QUE RECONCILIACAO, E NAO reservar()/liberar() ESPALHADOS
 * ---------------------------------------------------------------------------
 * Um orcamento muda de estado em OITO pontos diferentes (dispatchForApproval,
 * finalizeApproval, finalizeRejection, finalizeCancellation, markExpired,
 * linkBudgetToOrder, spawnRevision, applyApprovedRevision) e
 * `BudgetWorkflowService::syncItems()` APAGA E REINSERE todos os itens a cada
 * save — o id de `orcamento_itens` nao sobrevive a uma edicao.
 *
 * Chamar reservar()/liberar() em cada um desses pontos garante que um deles
 * fique para tras na proxima feature. `sincronizar()` calcula o estado DESEJADO
 * a partir de (status, itens) e aplica o delta: e idempotente, pode ser chamado
 * duas vezes no mesmo request sem efeito colateral, e um ponto de transicao novo
 * so precisa chama-lo.
 *
 * ---------------------------------------------------------------------------
 * INVARIANTES (as mesmas do motor unico, EstoqueMovimentacaoService)
 * ---------------------------------------------------------------------------
 *  1. Sempre em transacao, participando da que ja estiver aberta.
 *  2. `lockForUpdate()` em `pecas` ORDENADO POR ID, SEMPRE ANTES de tocar
 *     `estoque_reservas`. Reserva e movimentacao competem pelas mesmas linhas de
 *     `pecas`; travar na mesma ordem e o que evita deadlock entre os dois.
 *  3. `pecas.quantidade_reservada` e reescrito por SOMA ABSOLUTA dentro do lock,
 *     nunca por delta. Ver recalcular() para o porque — e uma excecao consciente
 *     a regra "nunca read-modify-write" do motor.
 *  4. Reservar ACIMA do saldo e permitido: e exatamente isso que produz a
 *     marcacao "A encomendar". Reserva sem estoque e a promessa registrada; a
 *     compra e que fecha a conta.
 */
class EstoqueReservaService
{
    /**
     * Status de orcamento que seguram peca.
     *
     * A regra do dono e "reserva nasce quando o orcamento e ENVIADO ao cliente".
     * `pendente_envio` fica de fora de proposito: e o estado de FALHA de disparo
     * — o orcamento nao chegou ao cliente, entao nao ha promessa a honrar.
     * `reenviar_orcamento` fica dentro: o cliente pediu mudanca, a negociacao
     * continua viva e soltar a peca no meio dela seria perde-la.
     *
     * @var array<int, string>
     */
    public const STATUS_RESERVANTES = [
        Budget::STATUS_SENT,
        Budget::STATUS_WAITING_REPLY,
        Budget::STATUS_WAITING_PACKAGE,
        Budget::STATUS_PACKAGE_APPROVED,
        Budget::STATUS_PENDING,
        Budget::STATUS_RESEND,
        Budget::STATUS_APPROVED,
        Budget::STATUS_PENDING_OS,
        Budget::STATUS_CONVERTED,
    ];

    /**
     * Reconcilia as reservas de um orcamento com o que ele hoje promete.
     *
     * @return array{reservadas: int, liberadas: int, pecas: array<int, float>}
     */
    public function sincronizar(Budget $budget, ?int $actorId = null): array
    {
        $orcamentoId = (int) ($budget->id ?? 0);

        if ($orcamentoId <= 0) {
            return ['reservadas' => 0, 'liberadas' => 0, 'pecas' => []];
        }

        $desejado = $this->reserva($budget) ? $this->demandaDoOrcamento($orcamentoId) : [];

        return DB::transaction(function () use ($budget, $orcamentoId, $desejado, $actorId): array {
            $existentes = EstoqueReserva::query()
                ->where('orcamento_id', $orcamentoId)
                ->get()
                ->keyBy(static fn (EstoqueReserva $reserva): int => (int) $reserva->peca_id);

            $pecaIds = array_values(array_unique(array_merge(
                array_keys($desejado),
                $existentes->keys()->map(static fn ($id): int => (int) $id)->all()
            )));
            sort($pecaIds);

            if ($pecaIds === []) {
                return ['reservadas' => 0, 'liberadas' => 0, 'pecas' => []];
            }

            // Invariante 2: pecas primeiro, ordenado por id.
            $pecas = $this->travarPecas($pecaIds);

            $agora = now();
            $expiraEm = $budget->publicLinkDeadline();
            $osId = (int) ($budget->os_id ?? 0);
            $equipamentoId = (int) ($budget->equipamento_id ?? 0);

            $reservadas = 0;
            $liberadas = 0;

            foreach ($desejado as $pecaId => $quantidade) {
                // `referencia_id` nao tem FK: item pode apontar para peca
                // excluida. Ignorar em silencio e o certo — travar o envio do
                // orcamento por causa de um id orfao seria pior.
                if (! $pecas->has($pecaId)) {
                    continue;
                }

                $linha = $existentes->get($pecaId);

                // Liberacao manual so vale enquanto a promessa nao mudar. Se o
                // operador editou a quantidade do item, ha intencao nova e a
                // reserva volta; senao o botao "liberar" seria mentira, porque o
                // proximo reenvio do orcamento a desfaria.
                if ($linha instanceof EstoqueReserva
                    && (bool) $linha->liberacao_manual
                    && (string) $linha->status === EstoqueReserva::STATUS_LIBERADA
                    && abs((float) $linha->quantidade - $quantidade) < 0.00005
                ) {
                    continue;
                }

                $consumida = $linha instanceof EstoqueReserva
                    ? (float) ($linha->quantidade_consumida ?? 0)
                    : 0.0;

                EstoqueReserva::query()->updateOrCreate(
                    ['orcamento_id' => $orcamentoId, 'peca_id' => $pecaId],
                    [
                        'os_id' => $osId > 0 ? $osId : null,
                        // O vinculo que o dono pediu: a peca fica presa ao
                        // aparelho daquele orcamento.
                        'equipamento_id' => $equipamentoId > 0 ? $equipamentoId : null,
                        'quantidade' => $quantidade,
                        // NAO zera: editar o orcamento depois de uma baixa
                        // parcial nao pode ressuscitar a peca que ja saiu.
                        'quantidade_consumida' => $consumida,
                        'status' => $consumida >= $quantidade
                            ? EstoqueReserva::STATUS_CONSUMIDA
                            : EstoqueReserva::STATUS_ATIVA,
                        'origem' => EstoqueReserva::ORIGEM_ORCAMENTO,
                        // Mesma regra unica que o link publico (410) e o PDF ja
                        // leem, para as tres nunca discordarem entre si.
                        'expira_em' => $expiraEm,
                        'liberacao_manual' => false,
                        'liberada_em' => null,
                        'liberada_por' => null,
                        'motivo_liberacao' => null,
                    ]
                );

                $reservadas++;
            }

            foreach ($existentes as $pecaId => $linha) {
                if (isset($desejado[(int) $pecaId])) {
                    continue;
                }

                // Só linha ATIVA e liberada. `consumida` e historia: a peca ja
                // saiu fisicamente, e reescrever para `liberada` apagaria o
                // registro de que houve consumo.
                if ((string) $linha->status !== EstoqueReserva::STATUS_ATIVA) {
                    continue;
                }

                $linha->forceFill([
                    'status' => EstoqueReserva::STATUS_LIBERADA,
                    'liberacao_manual' => false,
                    'liberada_em' => $agora,
                    'liberada_por' => $actorId,
                    'motivo_liberacao' => $this->motivoLiberacao($budget),
                ])->save();

                $liberadas++;
            }

            $this->recalcular($pecaIds);

            return [
                'reservadas' => $reservadas,
                'liberadas' => $liberadas,
                'pecas' => $desejado,
            ];
        });
    }

    /**
     * Solta na mao a reserva de uma peca, sem mexer no orcamento.
     *
     * Caso real: a peca prometida num orcamento parado virou urgencia noutro
     * aparelho. `liberacao_manual` marca a linha para a reconciliacao nao
     * desfazer a decisao no proximo reenvio.
     */
    public function liberarManual(int $orcamentoId, int $pecaId, ?int $actorId, string $motivo = ''): bool
    {
        if ($orcamentoId <= 0 || $pecaId <= 0) {
            return false;
        }

        return (bool) DB::transaction(function () use ($orcamentoId, $pecaId, $actorId, $motivo): bool {
            $this->travarPecas([$pecaId]);

            $reserva = EstoqueReserva::query()
                ->where('orcamento_id', $orcamentoId)
                ->where('peca_id', $pecaId)
                ->where('status', EstoqueReserva::STATUS_ATIVA)
                ->first();

            if (! $reserva instanceof EstoqueReserva) {
                return false;
            }

            $reserva->forceFill([
                'status' => EstoqueReserva::STATUS_LIBERADA,
                'liberacao_manual' => true,
                'liberada_em' => now(),
                'liberada_por' => $actorId,
                'motivo_liberacao' => trim($motivo) !== ''
                    ? mb_substr(trim($motivo), 0, 255)
                    : 'Liberada manualmente pelo operador.',
            ])->save();

            $this->recalcular([$pecaId]);

            return true;
        });
    }

    /**
     * Abate da reserva o que acabou de sair de verdade na baixa da OS.
     *
     * DEVE ser chamado DENTRO da transacao de OsAplicacaoPecaService::aplicar(),
     * depois de registrarLote() — assim o lock das linhas de `pecas` ainda esta
     * de pe e o recalculo do cache e consistente.
     *
     * Baixa parcial deixa a reserva ativa pelo remanescente: metade da peca
     * aplicada, metade ainda prometida. Nao conta duas vezes porque o que soma
     * disponibilidade e `quantidade - quantidade_consumida`.
     *
     * @param array<int, array<string, mixed>> $linhas peca_id + quantidade
     */
    public function consumir(int $orcamentoId, array $linhas): void
    {
        if ($orcamentoId <= 0) {
            return;
        }

        $demanda = [];

        foreach ($linhas as $linha) {
            $pecaId = (int) ($linha['peca_id'] ?? 0);
            $quantidade = round((float) ($linha['quantidade'] ?? 0), 4);

            if ($pecaId <= 0 || $quantidade <= 0) {
                continue;
            }

            $demanda[$pecaId] = round(($demanda[$pecaId] ?? 0) + $quantidade, 4);
        }

        if ($demanda === []) {
            return;
        }

        $reservas = EstoqueReserva::query()
            ->where('orcamento_id', $orcamentoId)
            ->whereIn('peca_id', array_keys($demanda))
            ->where('status', EstoqueReserva::STATUS_ATIVA)
            ->orderBy('peca_id')
            ->get();

        $tocadas = [];

        foreach ($reservas as $reserva) {
            $pecaId = (int) $reserva->peca_id;
            $consumir = min($demanda[$pecaId] ?? 0.0, $reserva->remanescente());

            if ($consumir <= 0) {
                continue;
            }

            $consumida = round((float) ($reserva->quantidade_consumida ?? 0) + $consumir, 4);
            $quantidade = (float) ($reserva->quantidade ?? 0);

            $reserva->forceFill([
                'quantidade_consumida' => $consumida,
                'status' => $consumida >= $quantidade
                    ? EstoqueReserva::STATUS_CONSUMIDA
                    : EstoqueReserva::STATUS_ATIVA,
            ])->save();

            $tocadas[] = $pecaId;
        }

        if ($tocadas !== []) {
            $this->recalcular($tocadas);
        }
    }

    /**
     * Saldo, reservado e disponivel por peca.
     *
     * `$orcamentoId` soma de volta a reserva DO PROPRIO orcamento. Sem isso a
     * reserva bloquearia justamente a baixa que ela protegia: o tecnico abriria
     * a OS do orcamento que reservou a peca e levaria "sem saldo" por causa da
     * propria reserva.
     *
     * @param array<int, int> $pecaIds
     * @return array<int, array{saldo: float, reservado: float, reservado_proprio: float, disponivel: float}>
     */
    public function disponibilidade(array $pecaIds, ?int $orcamentoId = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pecaIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $pecas = Peca::query()
            ->whereIn('id', $ids)
            ->get(['id', 'quantidade_atual', 'quantidade_reservada'])
            ->keyBy('id');

        $proprias = $orcamentoId !== null && $orcamentoId > 0
            ? $this->reservadoDoOrcamento($orcamentoId, $ids)
            : [];

        $resultado = [];

        foreach ($ids as $id) {
            $peca = $pecas->get($id);

            if (! $peca instanceof Peca) {
                continue;
            }

            $saldo = round((float) ($peca->quantidade_atual ?? 0), 4);
            $reservado = round((float) ($peca->quantidade_reservada ?? 0), 4);
            $proprio = round((float) ($proprias[$id] ?? 0), 4);

            $resultado[$id] = [
                'saldo' => $saldo,
                'reservado' => $reservado,
                'reservado_proprio' => $proprio,
                'disponivel' => round($saldo - $reservado + $proprio, 4),
            ];
        }

        return $resultado;
    }

    /**
     * Reescreve `pecas.quantidade_reservada` pela soma real de `estoque_reservas`.
     *
     * ESTA E UMA EXCECAO CONSCIENTE a regra "saldo so por expressao atomica" do
     * EstoqueMovimentacaoService, e precisa continuar sendo: e um
     * read-modify-write, e e seguro PORQUE roda sob o `lockForUpdate()` da mesma
     * linha de `pecas` que travarPecas() ja pegou.
     *
     * A troca vale a pena — soma absoluta e auto-reparavel: qualquer deriva
     * (linha inserida na mao, migracao, bug corrigido depois) some na proxima
     * sincronizacao. Delta acumularia o erro para sempre e nao teria conserto
     * sem um comando de manutencao.
     *
     * NAO "corrija" isto para DB::raw('quantidade_reservada + ...').
     *
     * @param array<int, int> $pecaIds
     */
    public function recalcular(array $pecaIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pecaIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        sort($ids);

        $somas = EstoqueReserva::query()
            ->whereIn('peca_id', $ids)
            ->where('status', EstoqueReserva::STATUS_ATIVA)
            ->selectRaw('peca_id, COALESCE(SUM(quantidade - quantidade_consumida), 0) as total')
            ->groupBy('peca_id')
            ->pluck('total', 'peca_id');

        $agora = now();

        foreach ($ids as $id) {
            $total = max(0.0, round((float) ($somas[$id] ?? 0), 4));

            Peca::query()->whereKey($id)->update([
                // String formatada, nao float: o valor vai como bind, mas
                // formatar explicitamente elimina qualquer duvida de locale e
                // de notacao cientifica em valor pequeno — a mesma armadilha
                // documentada em EstoqueMovimentacaoService::quantidadeSql().
                'quantidade_reservada' => number_format($total, 4, '.', ''),
                'updated_at' => $agora,
            ]);
        }
    }

    /**
     * Este orcamento, no status em que esta, segura peca?
     */
    public function reserva(Budget $budget): bool
    {
        return in_array((string) ($budget->status ?? ''), self::STATUS_RESERVANTES, true);
    }

    /**
     * Quanto o orcamento promete de cada peca hoje.
     *
     * Agrega por `referencia_id`: duas linhas da mesma peca no mesmo orcamento
     * viram UMA reserva — mesma regra do agregarPorPeca() do motor de
     * movimentacao, e o que a UNIQUE(orcamento_id, peca_id) exige.
     *
     * @return array<int, float>
     */
    private function demandaDoOrcamento(int $orcamentoId): array
    {
        $demanda = [];

        BudgetItem::query()
            ->where('orcamento_id', $orcamentoId)
            ->where('tipo_item', 'peca')
            ->whereNotNull('referencia_id')
            ->get(['referencia_id', 'quantidade'])
            ->each(static function (BudgetItem $item) use (&$demanda): void {
                $pecaId = (int) ($item->referencia_id ?? 0);
                $quantidade = round((float) ($item->quantidade ?? 0), 4);

                if ($pecaId <= 0 || $quantidade <= 0) {
                    return;
                }

                $demanda[$pecaId] = round(($demanda[$pecaId] ?? 0) + $quantidade, 4);
            });

        ksort($demanda);

        return $demanda;
    }

    /**
     * Remanescente reservado por um orcamento especifico, por peca.
     *
     * Publico porque o motor de movimentacao precisa dele para somar de volta a
     * reserva DO PROPRIO orcamento na conferencia de falta — sem isso a reserva
     * bloquearia justamente a baixa que ela protegia.
     *
     * @param array<int, int> $pecaIds
     * @return array<int, float>
     */
    public function reservadoDoOrcamento(int $orcamentoId, array $pecaIds): array
    {
        return EstoqueReserva::query()
            ->where('orcamento_id', $orcamentoId)
            ->whereIn('peca_id', $pecaIds)
            ->where('status', EstoqueReserva::STATUS_ATIVA)
            ->selectRaw('peca_id, COALESCE(SUM(quantidade - quantidade_consumida), 0) as total')
            ->groupBy('peca_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(int) $row->peca_id => (float) $row->total])
            ->all();
    }

    /**
     * @param array<int, int> $pecaIds
     * @return Collection<int, Peca>
     */
    private function travarPecas(array $pecaIds): Collection
    {
        // orderBy('id') e OBRIGATORIO: garante ordem deterministica de lock, a
        // mesma que EstoqueMovimentacaoService::registrarLote() usa. Os dois
        // caminhos competem pelas mesmas linhas.
        return Peca::query()
            ->whereIn('id', $pecaIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function motivoLiberacao(Budget $budget): string
    {
        $status = (string) ($budget->status ?? '');

        return match ($status) {
            Budget::STATUS_REJECTED => 'Orçamento rejeitado pelo cliente.',
            Budget::STATUS_CANCELLED => 'Orçamento cancelado.',
            Budget::STATUS_EXPIRED => 'Prazo do orçamento encerrado sem resposta.',
            Budget::STATUS_DRAFT, Budget::STATUS_PENDING_SEND => 'Orçamento voltou a rascunho/pendente de envio.',
            default => 'Peça deixou de constar no orçamento.',
        };
    }
}
