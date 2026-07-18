<?php

namespace App\Services\Financeiro;

use App\Models\Financeiro;
use App\Models\FinanceiroConta;
use App\Models\FinanceiroContaDefault;
use App\Models\FinanceiroContaMovimento;
use App\Models\FinanceiroMovimento;
use App\Models\FinanceiroMovimentoCartao;
use App\Models\FinanceiroTransferencia;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinanceiroContaService
{
    /** @return array<string, mixed> */
    public function options(): array
    {
        $contas = FinanceiroConta::query()
            ->with('defaults')
            ->active()
            ->orderByDesc('considera_disponivel')
            ->orderBy('nome')
            ->get()
            ->map(fn (FinanceiroConta $conta): array => $this->serializeAccount($conta))
            ->values()
            ->all();

        $defaults = FinanceiroContaDefault::query()
            ->whereHas('conta', fn ($query) => $query->active())
            ->pluck('conta_financeira_id', 'forma_pagamento')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return [
            'contas' => $contas,
            'contas_padrao' => $defaults,
            'tipos' => FinanceiroConta::typeOptions(),
        ];
    }

    /** @return array<string, mixed> */
    public function dashboard(?string $month = null): array
    {
        $reference = $this->parseMonth($month);
        $start = $reference->startOfMonth();
        $end = $reference->endOfMonth();
        $today = CarbonImmutable::today();
        $asOf = $end->greaterThan($today) ? $today : $end;

        $accounts = FinanceiroConta::query()
            ->with('defaults')
            ->orderByDesc('ativo')
            ->orderByDesc('considera_disponivel')
            ->orderBy('nome')
            ->get();

        $current = $this->flowTotals(null, $asOf);
        $opening = $this->flowTotals(null, $start->subDay());
        $monthly = $this->flowTotals($start, $asOf);
        $pending = $this->pendingTotals($asOf);

        $serialized = $accounts->map(function (FinanceiroConta $account) use ($current, $opening, $monthly, $pending): array {
            $id = (int) $account->id;
            $balance = $this->net($current[$id] ?? []);
            $openingBalance = $this->net($opening[$id] ?? []);
            $monthTotals = $monthly[$id] ?? ['entradas' => 0.0, 'saidas' => 0.0];
            $pendingTotal = round((float) ($pending[$id]['valor'] ?? 0), 2);

            return array_merge($this->serializeAccount($account), [
                'saldo_disponivel' => $balance,
                'cartao_pendente' => $pendingTotal,
                'posicao_total' => round($balance + $pendingTotal, 2),
                'mes' => [
                    'saldo_inicial' => $openingBalance,
                    'entradas' => round((float) ($monthTotals['entradas'] ?? 0), 2),
                    'saidas' => round((float) ($monthTotals['saidas'] ?? 0), 2),
                    'saldo_final' => $balance,
                ],
            ]);
        })->values();

        $active = $serialized->where('ativo', true);
        $available = round((float) $active->where('considera_disponivel', true)->sum('saldo_disponivel'), 2);
        $totalAccounts = round((float) $active->sum('saldo_disponivel'), 2);
        $reserved = round((float) $active->where('considera_disponivel', false)->sum('saldo_disponivel'), 2);
        $pendingCards = round((float) $active->sum('cartao_pendente'), 2);

        return [
            'referencia' => $reference->format('Y-m'),
            'ate' => $asOf->toDateString(),
            'resumo' => [
                'disponivel_operacional' => $available,
                'total_em_contas' => $totalAccounts,
                'reservado' => $reserved,
                'cartao_a_receber' => $pendingCards,
                'posicao_total' => round($totalAccounts + $pendingCards, 2),
            ],
            'contas' => $serialized->all(),
            'cartoes_pendentes' => $this->pendingCards(),
            'sem_conta' => $this->unclassifiedSummary($accounts),
            'transferencias_recentes' => FinanceiroTransferencia::query()
                ->with(['origem:id,nome', 'destino:id,nome'])
                ->latest('data_transferencia')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (FinanceiroTransferencia $transfer): array => $this->serializeTransfer($transfer))
                ->all(),
            'opcoes' => $this->options(),
        ];
    }

    /** @return array<string, mixed> */
    public function consolidatedReport(?string $month = null): array
    {
        $reference = $this->parseMonth($month);
        $start = $reference->startOfMonth();
        $end = $reference->endOfMonth();
        $today = CarbonImmutable::today();
        $asOf = $end->greaterThan($today) ? $today : $end;

        $accounts = FinanceiroConta::query()
            ->with('defaults')
            ->whereDate('data_inicio_controle', '<=', $asOf->toDateString())
            ->orderByDesc('considera_disponivel')
            ->orderBy('nome')
            ->get();

        $opening = $this->flowTotals(null, $start->subDay());
        $period = $this->categorizedFlowTotals($start, $asOf);
        $pending = $this->pendingTotals($asOf);

        $serialized = $accounts->map(function (FinanceiroConta $account) use ($opening, $period, $pending): array {
            $id = (int) $account->id;
            $categories = array_merge($this->emptyFlowCategories(), $period[$id] ?? []);
            $openingBalance = $this->net($opening[$id] ?? []);
            $registeredOpening = round($categories['saldo_inicial_entrada'] - $categories['saldo_inicial_saida'], 2);
            $operationalVariation = round($categories['operacional_entrada'] - $categories['operacional_saida'], 2);
            $adjustmentVariation = round($categories['ajuste_entrada'] - $categories['ajuste_saida'], 2);
            $transferVariation = round($categories['transferencia_entrada'] - $categories['transferencia_saida'], 2);
            $beforeTransfers = round($openingBalance + $registeredOpening + $operationalVariation + $adjustmentVariation, 2);
            $closingBalance = round($beforeTransfers + $transferVariation, 2);
            $pendingTotal = round((float) ($pending[$id]['valor'] ?? 0), 2);

            return array_merge($this->serializeAccount($account), [
                'saldo_anterior' => $openingBalance,
                'saldos_iniciais_periodo' => $registeredOpening,
                'entradas_operacionais' => round($categories['operacional_entrada'], 2),
                'saidas_operacionais' => round($categories['operacional_saida'], 2),
                'ajustes_entrada' => round($categories['ajuste_entrada'], 2),
                'ajustes_saida' => round($categories['ajuste_saida'], 2),
                'saldo_antes_transferencias' => $beforeTransfers,
                'transferencias_entrada' => round($categories['transferencia_entrada'], 2),
                'transferencias_saida' => round($categories['transferencia_saida'], 2),
                'saldo_final' => $closingBalance,
                'cartao_a_receber' => $pendingTotal,
                'posicao_total' => round($closingBalance + $pendingTotal, 2),
            ]);
        })->values();

        $sum = static fn (string $key): float => round((float) $serialized->sum($key), 2);
        $totalAccounts = $sum('saldo_final');
        $pendingCards = $sum('cartao_a_receber');
        $available = round((float) $serialized->where('considera_disponivel', true)->sum('saldo_final'), 2);
        $reserved = round((float) $serialized->where('considera_disponivel', false)->sum('saldo_final'), 2);
        $transferIn = $sum('transferencias_entrada');
        $transferOut = $sum('transferencias_saida');

        return [
            'referencia' => $reference->format('Y-m'),
            'data_inicio' => $start->toDateString(),
            'data_fim' => $asOf->toDateString(),
            'resumo' => [
                'saldo_anterior' => $sum('saldo_anterior'),
                'saldos_iniciais_periodo' => $sum('saldos_iniciais_periodo'),
                'entradas_operacionais' => $sum('entradas_operacionais'),
                'saidas_operacionais' => $sum('saidas_operacionais'),
                'ajustes_entrada' => $sum('ajustes_entrada'),
                'ajustes_saida' => $sum('ajustes_saida'),
                'saldo_antes_transferencias' => $sum('saldo_antes_transferencias'),
                'transferencias_entrada' => $transferIn,
                'transferencias_saida' => $transferOut,
                'conferencia_transferencias' => round($transferIn - $transferOut, 2),
                'saldo_final' => $totalAccounts,
                'disponivel_operacional' => $available,
                'reservado' => $reserved,
                'cartao_a_receber' => $pendingCards,
                'posicao_total' => round($totalAccounts + $pendingCards, 2),
            ],
            'contas' => $serialized->all(),
            'sem_conta' => $this->unclassifiedSummary($accounts, $start, $asOf),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createAccount(array $payload, ?int $actorId): FinanceiroConta
    {
        return DB::transaction(function () use ($payload, $actorId): FinanceiroConta {
            $account = FinanceiroConta::query()->create([
                'nome' => trim((string) $payload['nome']),
                'tipo' => $payload['tipo'],
                'instituicao' => $this->nullableString($payload['instituicao'] ?? null),
                'data_inicio_controle' => $payload['data_inicio_controle'],
                'considera_disponivel' => (bool) ($payload['considera_disponivel'] ?? true),
                'ativo' => (bool) ($payload['ativo'] ?? true),
                'cor' => $payload['cor'] ?? '#3868B0',
                'observacoes' => $this->nullableString($payload['observacoes'] ?? null),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $opening = round((float) ($payload['saldo_inicial'] ?? 0), 2);
            if (abs($opening) >= 0.01) {
                FinanceiroContaMovimento::query()->create([
                    'conta_financeira_id' => $account->id,
                    'tipo' => FinanceiroContaMovimento::TIPO_SALDO_INICIAL,
                    'natureza' => $opening >= 0
                        ? FinanceiroContaMovimento::NATUREZA_ENTRADA
                        : FinanceiroContaMovimento::NATUREZA_SAIDA,
                    'status' => FinanceiroContaMovimento::STATUS_REALIZADO,
                    'data_movimento' => $account->data_inicio_controle->toDateString(),
                    'valor' => abs($opening),
                    'descricao' => 'Saldo inicial da implantação da conta',
                    'created_by' => $actorId,
                ]);
            }

            $this->syncDefaults($account, $payload['formas_padrao'] ?? []);

            return $account->fresh(['defaults']);
        });
    }

    /** @param array<string, mixed> $payload */
    public function updateAccount(FinanceiroConta $account, array $payload, ?int $actorId): FinanceiroConta
    {
        return DB::transaction(function () use ($account, $payload, $actorId): FinanceiroConta {
            $account = FinanceiroConta::query()->lockForUpdate()->findOrFail($account->id);

            if (array_key_exists('data_inicio_controle', $payload)
                && CarbonImmutable::parse($payload['data_inicio_controle'])->toDateString() !== $account->data_inicio_controle->toDateString()
                && $this->accountHasMovements($account)) {
                throw new RuntimeException('A data de início não pode ser alterada depois que a conta possui movimentos.');
            }

            if (array_key_exists('ativo', $payload) && ! (bool) $payload['ativo'] && (bool) $account->ativo) {
                $balance = $this->accountBalance((int) $account->id);
                $pending = $this->pendingTotals()[(int) $account->id]['valor'] ?? 0;
                if (abs($balance) >= 0.01 || abs((float) $pending) >= 0.01) {
                    throw new RuntimeException('Transfira ou ajuste todo o saldo e confirme os cartões pendentes antes de desativar a conta.');
                }
            }

            $allowed = [
                'nome', 'tipo', 'instituicao', 'data_inicio_controle', 'considera_disponivel',
                'ativo', 'cor', 'observacoes',
            ];
            $updates = array_intersect_key($payload, array_flip($allowed));
            foreach (['instituicao', 'observacoes'] as $nullable) {
                if (array_key_exists($nullable, $updates)) {
                    $updates[$nullable] = $this->nullableString($updates[$nullable]);
                }
            }
            $updates['updated_by'] = $actorId;
            $account->update($updates);

            if (array_key_exists('formas_padrao', $payload)) {
                $this->syncDefaults($account, $payload['formas_padrao'] ?? []);
            }
            if (! $account->ativo) {
                $account->defaults()->delete();
            }

            return $account->fresh(['defaults']);
        });
    }

    /**
     * Resolve a conta patrimonial da baixa sem quebrar instalações que ainda
     * não ativaram o módulo. Depois da primeira conta ativa, toda baixa que
     * impacta caixa precisa ser explícita ou possuir um padrão por forma.
     */
    public function resolveAccountId(?int $accountId, ?string $paymentMethod, string $movementDate): ?int
    {
        if (! FinanceiroConta::query()->active()->exists()) {
            return null;
        }

        if (! $accountId && $paymentMethod) {
            $accountId = (int) (FinanceiroContaDefault::query()
                ->where('forma_pagamento', $paymentMethod)
                ->value('conta_financeira_id') ?? 0);
        }

        if (! $accountId) {
            throw new RuntimeException('Selecione a conta financeira que receberá ou pagará este valor.');
        }

        $account = FinanceiroConta::query()->active()->lockForUpdate()->find($accountId);
        if (! $account) {
            throw new RuntimeException('A conta financeira selecionada não existe ou está inativa.');
        }
        if (CarbonImmutable::parse($movementDate)->lt(CarbonImmutable::parse($account->data_inicio_controle))) {
            throw new RuntimeException('A data da baixa é anterior ao início do controle da conta selecionada.');
        }

        return (int) $account->id;
    }

    /** @param array<string, mixed> $payload */
    public function createAdjustment(FinanceiroConta $account, array $payload, ?int $actorId): FinanceiroContaMovimento
    {
        return DB::transaction(function () use ($account, $payload, $actorId): FinanceiroContaMovimento {
            $account = FinanceiroConta::query()->lockForUpdate()->findOrFail($account->id);
            $this->assertActiveAndDate($account, (string) $payload['data_movimento']);

            $value = round((float) $payload['valor'], 2);
            if ($payload['natureza'] === FinanceiroContaMovimento::NATUREZA_SAIDA
                && $value > $this->accountBalance((int) $account->id) + 0.001) {
                throw new RuntimeException('O ajuste de saída não pode deixar a conta com saldo negativo.');
            }

            return FinanceiroContaMovimento::query()->create([
                'conta_financeira_id' => $account->id,
                'tipo' => FinanceiroContaMovimento::TIPO_AJUSTE,
                'natureza' => $payload['natureza'],
                'status' => FinanceiroContaMovimento::STATUS_REALIZADO,
                'data_movimento' => $payload['data_movimento'],
                'valor' => $value,
                'descricao' => trim((string) $payload['descricao']),
                'documento_ref' => $this->nullableString($payload['documento_ref'] ?? null),
                'created_by' => $actorId,
            ]);
        });
    }

    /** @param array<string, mixed> $payload */
    public function createTransfer(array $payload, ?int $actorId): FinanceiroTransferencia
    {
        return DB::transaction(function () use ($payload, $actorId): FinanceiroTransferencia {
            $ids = [(int) $payload['conta_origem_id'], (int) $payload['conta_destino_id']];
            /** @var EloquentCollection<int, FinanceiroConta> $accounts */
            $accounts = FinanceiroConta::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $origin = $accounts->get($ids[0]);
            $destination = $accounts->get($ids[1]);
            if (! $origin || ! $destination) {
                throw new RuntimeException('Conta de origem ou destino não encontrada.');
            }

            $date = (string) $payload['data_transferencia'];
            $this->assertActiveAndDate($origin, $date);
            $this->assertActiveAndDate($destination, $date);
            $value = round((float) $payload['valor'], 2);
            if ($value > $this->accountBalance((int) $origin->id) + 0.001) {
                throw new RuntimeException('Saldo insuficiente na conta de origem para concluir a transferência.');
            }

            $transfer = FinanceiroTransferencia::query()->create([
                'conta_origem_id' => $origin->id,
                'conta_destino_id' => $destination->id,
                'data_transferencia' => $date,
                'valor' => $value,
                'descricao' => trim((string) $payload['descricao']),
                'documento_ref' => $this->nullableString($payload['documento_ref'] ?? null),
                'status' => FinanceiroTransferencia::STATUS_REALIZADA,
                'created_by' => $actorId,
            ]);

            foreach ([
                [$origin, FinanceiroContaMovimento::NATUREZA_SAIDA],
                [$destination, FinanceiroContaMovimento::NATUREZA_ENTRADA],
            ] as [$account, $nature]) {
                FinanceiroContaMovimento::query()->create([
                    'conta_financeira_id' => $account->id,
                    'transferencia_id' => $transfer->id,
                    'tipo' => FinanceiroContaMovimento::TIPO_TRANSFERENCIA,
                    'natureza' => $nature,
                    'status' => FinanceiroContaMovimento::STATUS_REALIZADO,
                    'data_movimento' => $date,
                    'valor' => $value,
                    'descricao' => $transfer->descricao,
                    'documento_ref' => $transfer->documento_ref,
                    'created_by' => $actorId,
                ]);
            }

            return $transfer->fresh(['origem:id,nome', 'destino:id,nome']);
        });
    }

    public function cancelTransfer(FinanceiroTransferencia $transfer, string $reason, ?int $actorId): FinanceiroTransferencia
    {
        return DB::transaction(function () use ($transfer, $reason, $actorId): FinanceiroTransferencia {
            $transfer = FinanceiroTransferencia::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status === FinanceiroTransferencia::STATUS_CANCELADA) {
                throw new RuntimeException('Esta transferência já está cancelada.');
            }

            $accounts = FinanceiroConta::query()
                ->whereIn('id', [$transfer->conta_origem_id, $transfer->conta_destino_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $destinationBalance = $this->accountBalance((int) $transfer->conta_destino_id);
            if ((float) $transfer->valor > $destinationBalance + 0.001) {
                throw new RuntimeException('A conta de destino não possui saldo suficiente para estornar esta transferência.');
            }

            $now = now();
            $transfer->update([
                'status' => FinanceiroTransferencia::STATUS_CANCELADA,
                'cancelado_por' => $actorId,
                'cancelado_em' => $now,
                'motivo_cancelamento' => trim($reason),
            ]);
            $transfer->movimentos()->update([
                'status' => FinanceiroContaMovimento::STATUS_CANCELADO,
                'cancelado_por' => $actorId,
                'cancelado_em' => $now,
                'motivo_cancelamento' => trim($reason),
            ]);

            return $transfer->fresh(['origem:id,nome', 'destino:id,nome']);
        });
    }

    public function confirmCardCredit(FinanceiroMovimentoCartao $card, string $creditDate, ?int $actorId): FinanceiroMovimentoCartao
    {
        return DB::transaction(function () use ($card, $creditDate, $actorId): FinanceiroMovimentoCartao {
            $accountId = FinanceiroMovimentoCartao::query()
                ->join('financeiro_movimentos as fm', 'fm.id', '=', 'financeiro_movimentos_cartao.movimento_id')
                ->where('financeiro_movimentos_cartao.id', $card->id)
                ->value('fm.conta_financeira_id');
            if (! $accountId) {
                throw new RuntimeException('Vincule o recebimento a uma conta financeira antes de confirmar o crédito.');
            }

            $account = FinanceiroConta::query()->lockForUpdate()->findOrFail((int) $accountId);
            $card = FinanceiroMovimentoCartao::query()
                ->with(['movimento.financeiro', 'movimento.conta'])
                ->lockForUpdate()
                ->findOrFail($card->id);
            if ($card->data_credito_efetivo) {
                throw new RuntimeException('O crédito deste recebimento já foi confirmado.');
            }
            if (! $card->movimento?->conta_financeira_id) {
                throw new RuntimeException('Vincule o recebimento a uma conta financeira antes de confirmar o crédito.');
            }
            if ((int) $card->movimento->conta_financeira_id !== (int) $account->id) {
                throw new RuntimeException('A conta financeira do recebimento foi alterada durante a confirmação. Tente novamente.');
            }
            if ($card->movimento->financeiro?->tipo !== Financeiro::TIPO_RECEBER
                || $card->movimento->financeiro?->status === Financeiro::STATUS_CANCELADO) {
                throw new RuntimeException('Somente recebimentos de cartão ativos podem ter o crédito confirmado.');
            }

            $date = CarbonImmutable::parse($creditDate);
            if ($date->lt(CarbonImmutable::parse($card->movimento->data_movimento))) {
                throw new RuntimeException('A data do crédito não pode ser anterior à venda.');
            }
            $this->assertActiveAndDate($account, $date->toDateString());

            $card->update([
                'data_credito_efetivo' => $date->toDateString(),
                'credito_confirmado_por' => $actorId,
                'credito_confirmado_em' => now(),
            ]);

            return $card->fresh(['movimento.financeiro', 'movimento.conta', 'operadora']);
        });
    }

    /** @return array<string, mixed> */
    public function statement(FinanceiroConta $account, array $filters): array
    {
        $start = CarbonImmutable::parse($filters['data_inicio'] ?? CarbonImmutable::today()->startOfMonth());
        $end = CarbonImmutable::parse($filters['data_fim'] ?? CarbonImmutable::today());
        if ($end->lt($start) || $start->diffInDays($end) > 366) {
            throw new RuntimeException('O período do extrato deve ter no máximo 366 dias.');
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 30)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $opening = $this->net($this->flowTotals(null, $start->subDay())[(int) $account->id] ?? []);

        $manual = DB::table('financeiro_conta_movimentos as pcm')
            ->where('pcm.conta_financeira_id', $account->id)
            ->whereDate('pcm.data_movimento', '>=', $start->toDateString())
            ->whereDate('pcm.data_movimento', '<=', $end->toDateString())
            ->selectRaw("pcm.id as source_id, 'patrimonial' as source_type, pcm.data_movimento as data, pcm.natureza, pcm.valor, pcm.descricao, pcm.documento_ref, pcm.status, pcm.tipo as subtipo, pcm.created_at");

        $financial = DB::table('financeiro_movimentos as fm')
            ->join('financeiro as f', 'f.id', '=', 'fm.financeiro_id')
            ->leftJoin('financeiro_movimentos_cartao as fc', 'fc.movimento_id', '=', 'fm.id')
            ->where('fm.conta_financeira_id', $account->id)
            ->where('f.impacta_fluxo_caixa', true)
            ->whereRaw("DATE(CASE WHEN f.tipo = 'receber' AND fc.id IS NOT NULL THEN COALESCE(fc.data_credito_efetivo, fc.data_prevista_recebimento, fm.data_movimento) ELSE fm.data_movimento END) BETWEEN ? AND ?", [$start->toDateString(), $end->toDateString()])
            ->selectRaw("fm.id as source_id, 'financeiro' as source_type, CASE WHEN f.tipo = 'receber' AND fc.id IS NOT NULL THEN COALESCE(fc.data_credito_efetivo, fc.data_prevista_recebimento, fm.data_movimento) ELSE fm.data_movimento END as data, CASE WHEN f.tipo = 'receber' THEN 'entrada' ELSE 'saida' END as natureza, CASE WHEN f.tipo = 'receber' AND fc.id IS NOT NULL THEN fc.valor_liquido ELSE fm.valor_movimento END as valor, f.descricao, fm.documento_ref, CASE WHEN f.status = 'cancelado' THEN 'cancelado' WHEN f.tipo = 'receber' AND fc.id IS NOT NULL AND fc.data_credito_efetivo IS NULL THEN 'previsto' ELSE 'realizado' END as status, CASE WHEN fc.id IS NOT NULL THEN 'cartao' ELSE COALESCE(fm.forma_pagamento, 'movimento') END as subtipo, fm.created_at");

        // Query Builder aplica aggregate apenas ao primeiro braço de um UNION
        // em alguns drivers. Contar cada fonte antes de uni-las mantém a
        // paginação correta em SQLite (testes) e MySQL/MariaDB (produção).
        $total = (clone $financial)->count() + (clone $manual)->count();
        $base = DB::query()->fromSub($financial->unionAll($manual), 'extrato');
        $items = $base->orderByDesc('data')->orderByDesc('created_at')->orderByDesc('source_id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($item): array => [
                'id' => (int) $item->source_id,
                'origem' => $item->source_type,
                'data' => CarbonImmutable::parse($item->data)->toDateString(),
                'natureza' => $item->natureza,
                'valor' => round((float) $item->valor, 2),
                'descricao' => $item->descricao,
                'documento_ref' => $item->documento_ref,
                'status' => $item->status,
                'subtipo' => $item->subtipo,
            ])->all();

        $period = $this->flowTotals($start, $end)[(int) $account->id] ?? ['entradas' => 0.0, 'saidas' => 0.0];

        return [
            'conta' => array_merge($this->serializeAccount($account), ['saldo_atual' => $this->accountBalance((int) $account->id)]),
            'periodo' => [
                'data_inicio' => $start->toDateString(),
                'data_fim' => $end->toDateString(),
                'saldo_inicial' => $opening,
                'entradas' => round((float) $period['entradas'], 2),
                'saidas' => round((float) $period['saidas'], 2),
                'saldo_final' => round($opening + (float) $period['entradas'] - (float) $period['saidas'], 2),
            ],
            'movimentos' => $items,
            'paginacao' => [
                'pagina_atual' => $page,
                'por_pagina' => $perPage,
                'total' => $total,
                'ultima_pagina' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return array<int, array{entradas: float, saidas: float}> */
    private function flowTotals(?CarbonImmutable $start, CarbonImmutable $end): array
    {
        return collect($this->categorizedFlowTotals($start, $end))
            ->map(function (array $categories): array {
                $categories = array_merge($this->emptyFlowCategories(), $categories);

                return [
                    'entradas' => round(
                        $categories['saldo_inicial_entrada']
                        + $categories['operacional_entrada']
                        + $categories['ajuste_entrada']
                        + $categories['transferencia_entrada'],
                        2
                    ),
                    'saidas' => round(
                        $categories['saldo_inicial_saida']
                        + $categories['operacional_saida']
                        + $categories['ajuste_saida']
                        + $categories['transferencia_saida'],
                        2
                    ),
                ];
            })
            ->all();
    }

    /** @return array<int, array<string, float>> */
    private function categorizedFlowTotals(?CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = [];
        $merge = function (array &$target, int $accountId, string $category, string $nature, float $value): void {
            $target[$accountId] ??= $this->emptyFlowCategories();
            $suffix = $nature === FinanceiroContaMovimento::NATUREZA_ENTRADA ? 'entrada' : 'saida';
            $key = $category.'_'.$suffix;
            $target[$accountId][$key] = round($target[$accountId][$key] + $value, 2);
        };

        $manual = DB::table('financeiro_conta_movimentos as pcm')
            ->join('financeiro_contas as c', 'c.id', '=', 'pcm.conta_financeira_id')
            ->where('pcm.status', FinanceiroContaMovimento::STATUS_REALIZADO)
            ->whereDate('pcm.data_movimento', '<=', $end->toDateString())
            ->whereColumn('pcm.data_movimento', '>=', 'c.data_inicio_controle');
        if ($start) {
            $manual->whereDate('pcm.data_movimento', '>=', $start->toDateString());
        }
        foreach ($manual->groupBy('pcm.conta_financeira_id', 'pcm.tipo', 'pcm.natureza')
            ->selectRaw('pcm.conta_financeira_id, pcm.tipo, pcm.natureza, SUM(pcm.valor) as total')->get() as $row) {
            $category = match ((string) $row->tipo) {
                FinanceiroContaMovimento::TIPO_SALDO_INICIAL => 'saldo_inicial',
                FinanceiroContaMovimento::TIPO_TRANSFERENCIA => 'transferencia',
                default => 'ajuste',
            };
            $merge($totals, (int) $row->conta_financeira_id, $category, (string) $row->natureza, (float) $row->total);
        }

        $immediate = DB::table('financeiro_movimentos as fm')
            ->join('financeiro as f', 'f.id', '=', 'fm.financeiro_id')
            ->join('financeiro_contas as c', 'c.id', '=', 'fm.conta_financeira_id')
            ->leftJoin('financeiro_movimentos_cartao as fc', 'fc.movimento_id', '=', 'fm.id')
            ->where('f.impacta_fluxo_caixa', true)
            ->where('f.status', '!=', Financeiro::STATUS_CANCELADO)
            ->where(fn ($query) => $query->whereNull('fc.id')->orWhere('f.tipo', Financeiro::TIPO_PAGAR))
            ->whereDate('fm.data_movimento', '<=', $end->toDateString())
            ->whereColumn('fm.data_movimento', '>=', 'c.data_inicio_controle');
        if ($start) {
            $immediate->whereDate('fm.data_movimento', '>=', $start->toDateString());
        }
        foreach ($immediate->groupBy('fm.conta_financeira_id', 'f.tipo')
            ->selectRaw('fm.conta_financeira_id, f.tipo, SUM(fm.valor_movimento) as total')->get() as $row) {
            $nature = $row->tipo === Financeiro::TIPO_RECEBER
                ? FinanceiroContaMovimento::NATUREZA_ENTRADA
                : FinanceiroContaMovimento::NATUREZA_SAIDA;
            $merge($totals, (int) $row->conta_financeira_id, 'operacional', $nature, (float) $row->total);
        }

        $cards = DB::table('financeiro_movimentos_cartao as fc')
            ->join('financeiro_movimentos as fm', 'fm.id', '=', 'fc.movimento_id')
            ->join('financeiro as f', 'f.id', '=', 'fm.financeiro_id')
            ->join('financeiro_contas as c', 'c.id', '=', 'fm.conta_financeira_id')
            ->where('f.tipo', Financeiro::TIPO_RECEBER)
            ->where('f.impacta_fluxo_caixa', true)
            ->where('f.status', '!=', Financeiro::STATUS_CANCELADO)
            ->whereNotNull('fc.data_credito_efetivo')
            ->whereDate('fc.data_credito_efetivo', '<=', $end->toDateString())
            ->whereColumn('fc.data_credito_efetivo', '>=', 'c.data_inicio_controle');
        if ($start) {
            $cards->whereDate('fc.data_credito_efetivo', '>=', $start->toDateString());
        }
        foreach ($cards->groupBy('fm.conta_financeira_id')
            ->selectRaw('fm.conta_financeira_id, SUM(fc.valor_liquido) as total')->get() as $row) {
            $merge(
                $totals,
                (int) $row->conta_financeira_id,
                'operacional',
                FinanceiroContaMovimento::NATUREZA_ENTRADA,
                (float) $row->total
            );
        }

        return $totals;
    }

    /** @return array<int, array{valor: float, quantidade: int}> */
    private function pendingTotals(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();

        return DB::table('financeiro_movimentos_cartao as fc')
            ->join('financeiro_movimentos as fm', 'fm.id', '=', 'fc.movimento_id')
            ->join('financeiro as f', 'f.id', '=', 'fm.financeiro_id')
            ->join('financeiro_contas as c', 'c.id', '=', 'fm.conta_financeira_id')
            ->where('f.tipo', Financeiro::TIPO_RECEBER)
            ->where('f.impacta_fluxo_caixa', true)
            ->where('f.status', '!=', Financeiro::STATUS_CANCELADO)
            ->whereDate('fm.data_movimento', '<=', $asOf->toDateString())
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('fc.data_credito_efetivo')
                    ->orWhereDate('fc.data_credito_efetivo', '>', $asOf->toDateString());
            })
            ->whereColumn('fm.data_movimento', '>=', 'c.data_inicio_controle')
            ->groupBy('fm.conta_financeira_id')
            ->selectRaw('fm.conta_financeira_id, SUM(fc.valor_liquido) as valor, COUNT(*) as quantidade')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->conta_financeira_id => [
                'valor' => round((float) $row->valor, 2),
                'quantidade' => (int) $row->quantidade,
            ]])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function pendingCards(): array
    {
        return FinanceiroMovimentoCartao::query()
            ->with(['movimento.financeiro:id,tipo,status,descricao', 'movimento.conta:id,nome', 'operadora:id,nome'])
            ->whereNull('data_credito_efetivo')
            ->whereHas('movimento', fn ($query) => $query->whereNotNull('conta_financeira_id')
                ->whereHas('financeiro', fn ($finance) => $finance
                    ->where('tipo', Financeiro::TIPO_RECEBER)
                    ->where('impacta_fluxo_caixa', true)
                    ->where('status', '!=', Financeiro::STATUS_CANCELADO)))
            ->orderBy('data_prevista_recebimento')
            ->limit(100)
            ->get()
            ->map(fn (FinanceiroMovimentoCartao $card): array => [
                'id' => (int) $card->id,
                'movimento_id' => (int) $card->movimento_id,
                'conta_id' => (int) $card->movimento->conta_financeira_id,
                'conta_nome' => $card->movimento->conta?->nome,
                'descricao' => $card->movimento->financeiro?->descricao,
                'operadora' => $card->operadora?->nome,
                'valor_bruto' => round((float) $card->valor_bruto, 2),
                'valor_taxa' => round((float) $card->valor_taxa, 2),
                'valor_liquido' => round((float) $card->valor_liquido, 2),
                'data_venda' => $card->movimento->data_movimento?->toDateString(),
                'data_prevista' => $card->data_prevista_recebimento?->toDateString(),
            ])
            ->all();
    }

    /** @return array<string, int|float> */
    private function unclassifiedSummary(
        EloquentCollection $accounts,
        ?CarbonImmutable $periodStart = null,
        ?CarbonImmutable $periodEnd = null
    ): array {
        if ($accounts->isEmpty()) {
            return ['quantidade' => 0, 'valor' => 0.0];
        }

        $start = $accounts->min(fn (FinanceiroConta $account): string => $account->data_inicio_controle->toDateString());
        $query = DB::table('financeiro_movimentos as fm')
            ->join('financeiro as f', 'f.id', '=', 'fm.financeiro_id')
            ->whereNull('fm.conta_financeira_id')
            ->where('f.impacta_fluxo_caixa', true)
            ->where('f.status', '!=', Financeiro::STATUS_CANCELADO)
            ->whereDate('fm.data_movimento', '>=', $start)
            ->whereNotIn('f.origem_tipo', ['os_recebimento_cartao', 'financeiro_movimento_cartao'])
            ->selectRaw('COUNT(*) as quantidade, COALESCE(SUM(fm.valor_movimento), 0) as valor');
        if ($periodStart) {
            $query->whereDate('fm.data_movimento', '>=', $periodStart->toDateString());
        }
        if ($periodEnd) {
            $query->whereDate('fm.data_movimento', '<=', $periodEnd->toDateString());
        }
        $summary = $query->first();

        return [
            'quantidade' => (int) ($summary->quantidade ?? 0),
            'valor' => round((float) ($summary->valor ?? 0), 2),
        ];
    }

    /** @return array<string, float> */
    private function emptyFlowCategories(): array
    {
        return [
            'saldo_inicial_entrada' => 0.0,
            'saldo_inicial_saida' => 0.0,
            'operacional_entrada' => 0.0,
            'operacional_saida' => 0.0,
            'ajuste_entrada' => 0.0,
            'ajuste_saida' => 0.0,
            'transferencia_entrada' => 0.0,
            'transferencia_saida' => 0.0,
        ];
    }

    private function accountBalance(int $accountId): float
    {
        return $this->net($this->flowTotals(null, CarbonImmutable::today())[$accountId] ?? []);
    }

    /** @param array<string, mixed> $totals */
    private function net(array $totals): float
    {
        return round((float) ($totals['entradas'] ?? 0) - (float) ($totals['saidas'] ?? 0), 2);
    }

    private function accountHasMovements(FinanceiroConta $account): bool
    {
        return $account->movimentosPatrimoniais()->exists()
            || FinanceiroMovimento::query()->where('conta_financeira_id', $account->id)->exists();
    }

    private function assertActiveAndDate(FinanceiroConta $account, string $date): void
    {
        if (! $account->ativo) {
            throw new RuntimeException('A conta financeira está inativa.');
        }
        if (CarbonImmutable::parse($date)->lt(CarbonImmutable::parse($account->data_inicio_controle))) {
            throw new RuntimeException('A data informada é anterior ao início do controle da conta.');
        }
    }

    /** @param array<int, string> $forms */
    private function syncDefaults(FinanceiroConta $account, array $forms): void
    {
        $forms = array_values(array_unique(array_filter($forms, fn ($form): bool => in_array($form, Financeiro::FORMAS_PAGAMENTO, true))));
        $account->defaults()->whereNotIn('forma_pagamento', $forms)->delete();

        foreach ($forms as $form) {
            FinanceiroContaDefault::query()->updateOrCreate(
                ['forma_pagamento' => $form],
                ['conta_financeira_id' => $account->id]
            );
        }
    }

    /** @return array<string, mixed> */
    private function serializeAccount(FinanceiroConta $account): array
    {
        return [
            'id' => (int) $account->id,
            'nome' => $account->nome,
            'tipo' => $account->tipo,
            'instituicao' => $account->instituicao,
            'data_inicio_controle' => $account->data_inicio_controle?->toDateString(),
            'considera_disponivel' => (bool) $account->considera_disponivel,
            'ativo' => (bool) $account->ativo,
            'cor' => $account->cor,
            'observacoes' => $account->observacoes,
            'formas_padrao' => $account->relationLoaded('defaults')
                ? $account->defaults->pluck('forma_pagamento')->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTransfer(FinanceiroTransferencia $transfer): array
    {
        return [
            'id' => (int) $transfer->id,
            'conta_origem_id' => (int) $transfer->conta_origem_id,
            'conta_origem' => $transfer->origem?->nome,
            'conta_destino_id' => (int) $transfer->conta_destino_id,
            'conta_destino' => $transfer->destino?->nome,
            'data_transferencia' => $transfer->data_transferencia?->toDateString(),
            'valor' => round((float) $transfer->valor, 2),
            'descricao' => $transfer->descricao,
            'documento_ref' => $transfer->documento_ref,
            'status' => $transfer->status,
        ];
    }

    private function parseMonth(?string $month): CarbonImmutable
    {
        if (! $month) {
            return CarbonImmutable::today()->startOfMonth();
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('A referência deve estar no formato AAAA-MM.');
        }

        return CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
