<?php

namespace App\Services\Caixa;

use App\Models\CaixaMovimento;
use App\Models\CaixaSessao;
use App\Models\FinanceiroConta;
use App\Models\FinanceiroContaDefault;
use App\Models\FinanceiroContaMovimento;
use App\Models\Sale;
use App\Models\User;
use App\Services\Financeiro\FinanceiroContaService;
use App\Support\CommercialAdjustment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turnos de caixa — specs/028-caixa-sessoes/spec.md.
 *
 * Toda a máquina financeira (contas, saldos, transferências) já existe; este
 * serviço só acrescenta a camada de turno: quem abriu, com quanto, o que passou
 * pela gaveta e quanto foi contado no fim.
 */
class CaixaSessionService
{
    private const ACCOUNT_NAME = 'Caixa da loja';

    public function __construct(
        private readonly FinanceiroContaService $financeiroContaService
    ) {}

    /**
     * Conta de caixa físico da loja.
     *
     * A operação tem um único ponto de caixa, então a conta é resolvida sozinha
     * e o operador nunca escolhe. O modelo suporta N (a sessão aponta para
     * `conta_financeira_id`); abrir um segundo caixa é cadastro, não migration.
     */
    public function resolveCashAccount(): FinanceiroConta
    {
        $account = FinanceiroConta::query()
            ->where('tipo', FinanceiroConta::TIPO_CAIXA)
            ->where('ativo', 1)
            ->orderBy('id')
            ->first();

        if (! $account instanceof FinanceiroConta) {
            throw new RuntimeException(
                'Nenhuma conta de caixa físico ativa foi encontrada. Abra o caixa pela primeira vez para criá-la.'
            );
        }

        return $account;
    }

    /**
     * Conta de caixa, criando-a na primeira vez.
     *
     * Só a abertura EXPLÍCITA passa por aqui, e o motivo é importante: enquanto
     * nenhuma conta financeira ativa existe, FinanceiroContaService opera sem
     * rastreio de conta; a primeira conta cadastrada passa a exigir conta em
     * toda baixa do sistema (OS, venda, lançamento avulso). Isso precisa ser
     * uma decisão visível do usuário — por isso não nasce numa migration nem
     * numa venda automática.
     */
    public function resolveOrCreateCashAccount(?int $actorId = null): FinanceiroConta
    {
        $account = FinanceiroConta::query()
            ->where('tipo', FinanceiroConta::TIPO_CAIXA)
            ->where('ativo', 1)
            ->orderBy('id')
            ->first();

        if ($account instanceof FinanceiroConta) {
            return $account;
        }

        $now = now();

        $account = FinanceiroConta::query()->create([
            'nome' => self::ACCOUNT_NAME,
            'tipo' => FinanceiroConta::TIPO_CAIXA,
            'data_inicio_controle' => $now->toDateString(),
            'considera_disponivel' => 1,
            'ativo' => 1,
            'cor' => '#29C384',
            'observacoes' => 'Gaveta do balcão. Criada na primeira abertura de caixa (specs/028-caixa-sessoes).',
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Sem este padrão, toda venda em dinheiro voltaria a exigir que o
        // operador escolhesse a conta na mão.
        $hasDefault = FinanceiroContaDefault::query()->where('forma_pagamento', 'dinheiro')->exists();

        if (! $hasDefault) {
            FinanceiroContaDefault::query()->create([
                'forma_pagamento' => 'dinheiro',
                'conta_financeira_id' => (int) $account->id,
            ]);
        }

        return $account;
    }

    public function currentSession(?int $accountId = null): ?CaixaSessao
    {
        $accountId ??= (int) $this->resolveCashAccount()->id;

        return CaixaSessao::query()
            ->with(['operator', 'account', 'movements.responsible'])
            ->where('conta_financeira_id', $accountId)
            ->open()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Abertura declarada: o operador informa o troco que colocou na gaveta.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(User $actor, array $attributes = []): CaixaSessao
    {
        $account = $this->resolveOrCreateCashAccount((int) $actor->id);

        return DB::transaction(function () use ($actor, $account, $attributes): CaixaSessao {
            $this->assertNoOpenSession((int) $account->id);

            $declared = CommercialAdjustment::money($attributes['valor_abertura'] ?? 0);

            $session = $this->createSession(
                $account,
                $actor,
                $declared,
                false,
                trim((string) ($attributes['observacoes'] ?? '')) ?: null
            );

            // A abertura declarada é um ponto de reconciliação: alinha o saldo
            // financeiro da conta ao dinheiro que está fisicamente na gaveta.
            // Sem isso, "Contas e Saldos" mostraria o caixa errado para sempre
            // e a sangria não encontraria saldo para transferir.
            $this->reconcileAccountBalance(
                $account,
                $declared,
                $actor,
                'Abertura do caixa #'.(int) $session->id
            );

            return $session;
        });
    }

    /**
     * Devolve a sessão aberta ou abre uma automaticamente.
     *
     * Chamado pela venda em dinheiro. Bloquear a venda seria o controle mais
     * rígido, mas travaria o balcão toda vez que alguém esquecesse de abrir — e
     * vai esquecer. A abertura automática preserva o vínculo turno↔venda, que é
     * o que dá sentido à conferência, sem custo operacional.
     *
     * Deve rodar DENTRO da transação da venda.
     */
    public function ensureOpenSession(User $actor): CaixaSessao
    {
        $account = $this->resolveCashAccount();

        $current = CaixaSessao::query()
            ->where('conta_financeira_id', (int) $account->id)
            ->open()
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if ($current instanceof CaixaSessao) {
            return $current;
        }

        // Herda o que foi contado no fechamento anterior: é o dinheiro que
        // fisicamente ficou na gaveta.
        $previous = CaixaSessao::query()
            ->where('conta_financeira_id', (int) $account->id)
            ->where('status', CaixaSessao::STATUS_FECHADA)
            ->orderByDesc('fechado_em')
            ->orderByDesc('id')
            ->first();

        return $this->createSession(
            $account,
            $actor,
            $previous instanceof CaixaSessao ? (float) ($previous->valor_informado ?? 0) : 0.0,
            true,
            'Aberta automaticamente pela primeira venda em dinheiro do turno.'
        );
    }

    /**
     * Igual a ensureOpenSession(), mas devolve null em vez de estourar quando
     * não há conta de caixa cadastrada.
     *
     * Usado pela venda: o módulo de caixa não pode ser pré-requisito para
     * vender. Sem conta de caixa configurada, a venda segue pelo caminho
     * anterior (conta escolhida no pagamento) e apenas fica sem turno.
     */
    public function ensureOpenSessionOrNull(User $actor): ?CaixaSessao
    {
        try {
            return $this->ensureOpenSession($actor);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Corrige o valor de abertura enquanto o turno está aberto — necessário
     * porque a abertura automática herda um valor que pode não bater com a
     * gaveta real.
     */
    public function updateOpeningAmount(CaixaSessao $session, mixed $value, ?User $actor = null): CaixaSessao
    {
        if (! $session->isOpen()) {
            throw new RuntimeException('Só é possível corrigir o valor de abertura com o caixa aberto.');
        }

        return DB::transaction(function () use ($session, $value, $actor): CaixaSessao {
            $corrected = CommercialAdjustment::money($value);
            $delta = round($corrected - (float) $session->valor_abertura, 2);

            $session->forceFill([
                'valor_abertura' => $corrected,
                'abertura_automatica' => false,
            ])->save();

            // Corrigir a abertura move o saldo da conta na mesma medida: o
            // dinheiro estava lá, só não tinha sido declarado.
            if (abs($delta) >= 0.01 && $actor instanceof User) {
                $this->adjustAccount(
                    $session->account ?? FinanceiroConta::query()->findOrFail((int) $session->conta_financeira_id),
                    $delta,
                    $actor,
                    'Correção da abertura do caixa #'.(int) $session->id
                );
            }

            return $session->refresh();
        });
    }

    /**
     * Sangria (dinheiro sai) ou suprimento (dinheiro entra).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerMovement(User $actor, CaixaSessao $session, array $attributes): CaixaMovimento
    {
        return DB::transaction(function () use ($actor, $session, $attributes): CaixaMovimento {
            $locked = CaixaSessao::query()->lockForUpdate()->findOrFail((int) $session->id);

            if (! $locked->isOpen()) {
                throw new RuntimeException('Não é possível movimentar um caixa já fechado.');
            }

            $type = trim((string) ($attributes['tipo'] ?? ''));

            if (! in_array($type, CaixaMovimento::types(), true)) {
                throw new RuntimeException('Informe se o movimento é uma sangria ou um suprimento.');
            }

            $value = CommercialAdjustment::money($attributes['valor'] ?? 0);

            if ($value <= 0) {
                throw new RuntimeException('Informe um valor maior que zero para o movimento.');
            }

            if ($type === CaixaMovimento::TIPO_SANGRIA) {
                $available = $this->expectedAmount($locked);

                if ($value > $available + 0.001) {
                    throw new RuntimeException(
                        'A sangria não pode ser maior que o dinheiro em caixa ('
                        .number_format($available, 2, ',', '.').').'
                    );
                }
            }

            $destinationId = (int) ($attributes['conta_destino_id'] ?? 0);
            $transferId = null;

            // Sangria com destino é uma transferência de verdade entre contas:
            // o dinheiro sai da gaveta e entra no banco/cofre. Reaproveita o
            // caminho que já valida saldo, data e trava as duas contas.
            if ($type === CaixaMovimento::TIPO_SANGRIA && $destinationId > 0) {
                $transfer = $this->financeiroContaService->createTransfer([
                    'conta_origem_id' => (int) $locked->conta_financeira_id,
                    'conta_destino_id' => $destinationId,
                    'data_transferencia' => now()->toDateString(),
                    'valor' => $value,
                    'descricao' => 'Sangria do caixa: '.trim((string) $attributes['motivo']),
                ], (int) $actor->id);

                $transferId = (int) $transfer->id;
            }

            // Suprimento (dinheiro que entra) e sangria sem destino (dinheiro
            // que sai sem ir para outra conta) precisam existir como ajuste na
            // conta, senão o saldo da gaveta não acompanha a realidade.
            // Sangria com destino já foi movimentada pela transferência acima.
            if ($transferId === null) {
                $account = FinanceiroConta::query()->findOrFail((int) $locked->conta_financeira_id);

                $this->adjustAccount(
                    $account,
                    $type === CaixaMovimento::TIPO_SUPRIMENTO ? $value : -$value,
                    $actor,
                    CaixaMovimento::typeLabel($type).' do caixa #'.(int) $locked->id
                );
            }

            return CaixaMovimento::query()->create([
                'caixa_sessao_id' => (int) $locked->id,
                'tipo' => $type,
                'valor' => $value,
                'motivo' => trim((string) $attributes['motivo']),
                'responsavel_id' => (int) $actor->id,
                'conta_destino_id' => $destinationId > 0 ? $destinationId : null,
                'transferencia_id' => $transferId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Fechamento cego: o operador conta e informa, e só então o esperado e a
     * diferença são calculados e revelados.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function close(User $actor, CaixaSessao $session, array $attributes): CaixaSessao
    {
        return DB::transaction(function () use ($actor, $session, $attributes): CaixaSessao {
            $locked = CaixaSessao::query()->lockForUpdate()->findOrFail((int) $session->id);

            if (! $locked->isOpen()) {
                throw new RuntimeException('Este caixa já está fechado.');
            }

            if (! array_key_exists('valor_informado', $attributes) || $attributes['valor_informado'] === null || $attributes['valor_informado'] === '') {
                throw new RuntimeException('Informe o valor contado na gaveta para fechar o caixa.');
            }

            $counted = CommercialAdjustment::money($attributes['valor_informado']);

            if ($counted < 0) {
                throw new RuntimeException('O valor contado não pode ser negativo.');
            }

            $totals = $this->sessionTotals($locked);
            $expected = round(
                (float) $locked->valor_abertura
                + $totals['vendas_dinheiro']
                + $totals['suprimentos']
                - $totals['sangrias'],
                2
            );

            $locked->forceFill([
                'status' => CaixaSessao::STATUS_FECHADA,
                'fechado_em' => now(),
                'fechado_por' => (int) $actor->id,
                'valor_esperado' => $expected,
                'valor_informado' => $counted,
                'diferenca' => round($counted - $expected, 2),
                // Fotografia congelada: recalcular depois traria número
                // diferente se alguma venda do turno for cancelada mais tarde.
                'total_vendas_dinheiro' => $totals['vendas_dinheiro'],
                'total_suprimentos' => $totals['suprimentos'],
                'total_sangrias' => $totals['sangrias'],
                'quantidade_vendas' => $totals['quantidade_vendas'],
                'observacoes_fechamento' => trim((string) ($attributes['observacoes'] ?? '')) ?: null,
            ])->save();

            // A diferença apurada vira ajuste na conta: o dia seguinte precisa
            // começar do dinheiro que realmente está na gaveta, não do que
            // deveria estar.
            $difference = round($counted - $expected, 2);

            if (abs($difference) >= 0.01) {
                $this->adjustAccount(
                    FinanceiroConta::query()->findOrFail((int) $locked->conta_financeira_id),
                    $difference,
                    $actor,
                    'Diferença apurada no fechamento do caixa #'.(int) $locked->id
                );
            }

            return $locked->refresh();
        });
    }

    /**
     * Reabre uma sessão fechada para correção. Limpa a conferência anterior —
     * um fechamento reaberto não vale mais como conferência.
     */
    public function reopen(User $actor, CaixaSessao $session): CaixaSessao
    {
        return DB::transaction(function () use ($session): CaixaSessao {
            $locked = CaixaSessao::query()->lockForUpdate()->findOrFail((int) $session->id);

            if ($locked->isOpen()) {
                throw new RuntimeException('Este caixa já está aberto.');
            }

            $this->assertNoOpenSession((int) $locked->conta_financeira_id);

            $locked->forceFill([
                'status' => CaixaSessao::STATUS_ABERTA,
                'fechado_em' => null,
                'fechado_por' => null,
                'valor_esperado' => null,
                'valor_informado' => null,
                'diferenca' => null,
                'observacoes_fechamento' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Dinheiro que deveria estar na gaveta agora.
     *
     * Troco não entra separadamente: numa venda de R$ 50 paga com R$ 100 a
     * gaveta ganha 100 e devolve 50, então o líquido é o próprio `valor` do
     * pagamento. Cartão e Pix ficam de fora — não passam pela gaveta.
     */
    public function expectedAmount(CaixaSessao $session): float
    {
        $totals = $this->sessionTotals($session);

        return round(
            (float) $session->valor_abertura
            + $totals['vendas_dinheiro']
            + $totals['suprimentos']
            - $totals['sangrias'],
            2
        );
    }

    /**
     * @return array{vendas_dinheiro: float, suprimentos: float, sangrias: float, quantidade_vendas: int}
     */
    public function sessionTotals(CaixaSessao $session): array
    {
        // Venda cancelada devolve o dinheiro ao cliente, então sai da conta.
        $sales = DB::table('venda_pagamentos')
            ->join('vendas', 'vendas.id', '=', 'venda_pagamentos.venda_id')
            ->where('vendas.caixa_sessao_id', (int) $session->id)
            ->where('vendas.status', Sale::STATUS_COMPLETED)
            ->where('venda_pagamentos.forma_pagamento', 'dinheiro')
            ->selectRaw('COALESCE(SUM(venda_pagamentos.valor), 0) as total')
            ->selectRaw('COUNT(DISTINCT vendas.id) as quantidade')
            ->first();

        $movements = CaixaMovimento::query()
            ->where('caixa_sessao_id', (int) $session->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'suprimento' THEN valor ELSE 0 END), 0) as suprimentos")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'sangria' THEN valor ELSE 0 END), 0) as sangrias")
            ->first();

        return [
            'vendas_dinheiro' => round((float) ($sales->total ?? 0), 2),
            'quantidade_vendas' => (int) ($sales->quantidade ?? 0),
            'suprimentos' => round((float) ($movements->suprimentos ?? 0), 2),
            'sangrias' => round((float) ($movements->sangrias ?? 0), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CaixaSessao>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = CaixaSessao::query()->with(['operator', 'closedBy', 'account']);

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $operatorId = (int) ($filters['operador_id'] ?? 0);
        if ($operatorId > 0) {
            $query->where('operador_id', $operatorId);
        }

        $from = $this->normalizeDate($filters['data_inicio'] ?? null);
        if ($from !== null) {
            $query->whereDate('aberto_em', '>=', $from);
        }

        $to = $this->normalizeDate($filters['data_fim'] ?? null);
        if ($to !== null) {
            $query->whereDate('aberto_em', '<=', $to);
        }

        // Só sessões com diferença — o filtro que a gestão realmente usa.
        if (filter_var($filters['com_diferenca'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->where('status', CaixaSessao::STATUS_FECHADA)->where('diferenca', '<>', 0);
        }

        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 15)));

        $paginator = $query
            ->orderByDesc('aberto_em')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->getCollection()->transform(fn (CaixaSessao $s): array => $this->mapSummary($s));

        return $paginator;
    }

    /**
     * Detalhe do turno. `revelar_esperado` fica falso enquanto o caixa está
     * aberto: a conferência é cega, e mostrar o esperado antes da contagem
     * transformaria o fechamento em "digitar o número que o sistema quer".
     *
     * @return array<string, mixed>
     */
    public function mapDetail(CaixaSessao $session, bool $revelarEsperado = false): array
    {
        $session->loadMissing(['operator', 'closedBy', 'account', 'movements.responsible']);

        $totals = $session->isOpen()
            ? $this->sessionTotals($session)
            : [
                'vendas_dinheiro' => (float) $session->total_vendas_dinheiro,
                'suprimentos' => (float) $session->total_suprimentos,
                'sangrias' => (float) $session->total_sangrias,
                'quantidade_vendas' => (int) $session->quantidade_vendas,
            ];

        $detail = array_merge($this->mapSummary($session), [
            'observacoes_abertura' => $session->observacoes_abertura,
            'observacoes_fechamento' => $session->observacoes_fechamento,
            'total_vendas_dinheiro' => $totals['vendas_dinheiro'],
            'total_suprimentos' => $totals['suprimentos'],
            'total_sangrias' => $totals['sangrias'],
            'quantidade_vendas' => $totals['quantidade_vendas'],
            'movimentos' => $session->movements->map(static fn (CaixaMovimento $m): array => [
                'id' => (int) $m->id,
                'tipo' => (string) $m->tipo,
                'tipo_label' => CaixaMovimento::typeLabel($m->tipo),
                'valor' => round((float) $m->valor, 2),
                'motivo' => (string) $m->motivo,
                'responsavel_nome' => (string) ($m->responsible?->nome ?? ''),
                'conta_destino_id' => $m->conta_destino_id !== null ? (int) $m->conta_destino_id : null,
                'transferencia_id' => $m->transferencia_id !== null ? (int) $m->transferencia_id : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all(),
        ]);

        // Com o caixa fechado o esperado já é público — a contagem foi feita.
        if (! $session->isOpen() || $revelarEsperado) {
            $detail['valor_esperado'] = $session->isOpen()
                ? $this->expectedAmount($session)
                : round((float) $session->valor_esperado, 2);
        }

        return $detail;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapSummary(CaixaSessao $session): array
    {
        return [
            'id' => (int) $session->id,
            'status' => (string) $session->status,
            'status_label' => CaixaSessao::statusLabel($session->status),
            'conta_financeira_id' => (int) $session->conta_financeira_id,
            'conta_nome' => (string) ($session->account?->nome ?? ''),
            'operador_id' => $session->operador_id !== null ? (int) $session->operador_id : null,
            'operador_nome' => (string) ($session->operator?->nome ?? ''),
            'fechado_por_nome' => (string) ($session->closedBy?->nome ?? ''),
            'aberto_em' => $session->aberto_em?->toIso8601String(),
            'fechado_em' => $session->fechado_em?->toIso8601String(),
            'valor_abertura' => round((float) $session->valor_abertura, 2),
            'abertura_automatica' => (bool) $session->abertura_automatica,
            'valor_informado' => $session->valor_informado !== null ? round((float) $session->valor_informado, 2) : null,
            'diferenca' => $session->diferenca !== null ? round((float) $session->diferenca, 2) : null,
            'total_vendas_dinheiro' => round((float) $session->total_vendas_dinheiro, 2),
            'quantidade_vendas' => (int) $session->quantidade_vendas,
        ];
    }

    private function createSession(
        FinanceiroConta $account,
        User $actor,
        float $openingAmount,
        bool $automatic,
        ?string $notes
    ): CaixaSessao {
        $session = new CaixaSessao();
        $session->conta_financeira_id = (int) $account->id;
        $session->operador_id = (int) $actor->id;
        $session->status = CaixaSessao::STATUS_ABERTA;
        $session->aberto_em = now();
        $session->aberto_por = (int) $actor->id;
        $session->valor_abertura = max(0, $openingAmount);
        $session->abertura_automatica = $automatic;
        $session->observacoes_abertura = $notes;
        $session->save();

        return $session;
    }

    /**
     * Alinha o saldo financeiro da conta a um valor alvo, lançando o ajuste da
     * diferença. É o que mantém "Contas e Saldos" mostrando a gaveta real.
     */
    private function reconcileAccountBalance(
        FinanceiroConta $account,
        float $target,
        User $actor,
        string $description
    ): void {
        $current = $this->financeiroContaService->balanceOf((int) $account->id);
        $this->adjustAccount($account, round($target - $current, 2), $actor, $description);
    }

    /**
     * Ajuste assinado: positivo entra na conta, negativo sai. Delta irrelevante
     * não gera lançamento — o extrato não precisa de ruído de centavo.
     */
    private function adjustAccount(FinanceiroConta $account, float $delta, User $actor, string $description): void
    {
        if (abs($delta) < 0.01) {
            return;
        }

        $this->financeiroContaService->createAdjustment(
            $account,
            [
                'valor' => abs($delta),
                'natureza' => $delta > 0
                    ? FinanceiroContaMovimento::NATUREZA_ENTRADA
                    : FinanceiroContaMovimento::NATUREZA_SAIDA,
                'data_movimento' => now()->toDateString(),
                'descricao' => $description,
            ],
            (int) $actor->id
        );
    }

    private function assertNoOpenSession(int $accountId): void
    {
        $open = CaixaSessao::query()
            ->where('conta_financeira_id', $accountId)
            ->open()
            ->lockForUpdate()
            ->exists();

        if ($open) {
            throw new RuntimeException('Já existe um caixa aberto. Feche o turno atual antes de abrir outro.');
        }
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
