<?php

namespace App\Services\Financeiro;

use App\Models\Budget;
use App\Models\Client;
use App\Models\Financeiro;
use App\Models\FinanceiroCategoria;
use App\Models\FinanceiroMovimento;
use App\Models\FinanceiroMovimentoCartao;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Supplier;
use App\Services\Orders\OrderEventService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinanceiroService
{
    public function __construct(
        private readonly FinanceiroCartaoService $financeiroCartaoService,
        private readonly FinanceiroContaService $financeiroContaService,
        private readonly OrderEventService $orderEventService
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return Financeiro::query()
            ->withFilters($filters)
            // Relações necessárias para resolveOriginTrail() (trilha de origem
            // exibida sob a categoria, na listagem) — todas eager-loaded para
            // não virar N+1 por linha: 'order.equipment.*' cobre o caso
            // "Serviço" ligado a OS, 'supplier' cobre "A pagar" avulso,
            // 'origemMovimento.financeiro.*' resolve taxas de cartão até o
            // título a receber que as originou.
            ->with([
                'order.equipment.brand',
                'order.equipment.model',
                'client',
                'supplier',
                'origemMovimento.financeiro.client',
                'origemMovimento.financeiro.order',
            ])
            // Ordem de pagamento/recebimento efetivo, não de vencimento. Sem
            // data_pagamento (título ainda pendente) vai para o fim da lista —
            // NULL é o menor valor em ORDER BY DESC.
            ->orderByDesc('data_pagamento')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Trilha de rastreabilidade da origem de um lançamento, exibida na
     * listagem sob a categoria (ex.: "Cliente | OS 26060014 | iPhone 12").
     * Substitui o antigo subtítulo genérico grupo_dre/subgrupo_dre, que era
     * igual para todo lançamento da mesma categoria e não dizia nada sobre a
     * origem específica daquele registro.
     *
     * @return array<int, string>
     */
    public function resolveOriginTrail(Financeiro $financeiro): array
    {
        $segments = [];

        if ((bool) $financeiro->dre_fixo_mensal) {
            $segments[] = 'Fixo mensal';
        }

        // Taxa de cartão (os_recebimento_cartao = gerada na baixa da OS,
        // financeiro_movimento_cartao = gerada num recebimento avulso em
        // cartão) — em ambos os casos origem_id aponta para o movimento cuja
        // baixa gerou a taxa; o título pai desse movimento é a conta a
        // receber de origem.
        if (in_array((string) $financeiro->origem_tipo, ['os_recebimento_cartao', 'financeiro_movimento_cartao'], true)) {
            $tituloOrigem = $financeiro->origemMovimento?->financeiro;

            if (! $tituloOrigem instanceof Financeiro) {
                return array_merge($segments, ['Origem da taxa não encontrada']);
            }

            if ($tituloOrigem->client instanceof Client) {
                $segments[] = (string) $tituloOrigem->client->nome_razao;
            }
            if ($tituloOrigem->order instanceof Order) {
                $segments[] = 'OS '.(string) $tituloOrigem->order->numero_os;
            }
            // Sempre inclui o id do título de origem — é o "mínimo" que
            // identifica a taxa mesmo quando o título pai também é avulso
            // (sem cliente/OS vinculado).
            $segments[] = 'Título #'.(int) $tituloOrigem->id;

            return $segments;
        }

        // Ligado a uma OS (o caso mais comum de "Serviço", mas vale para
        // qualquer lançamento — manual ou automático — com os_id preenchido).
        if ($financeiro->order instanceof Order) {
            $client = $financeiro->client ?? $financeiro->order->client;
            if ($client instanceof Client) {
                $segments[] = (string) $client->nome_razao;
            }

            $segments[] = 'OS '.(string) $financeiro->order->numero_os;

            $equipment = $financeiro->order->equipment;
            $equipmentLabel = trim(implode(' ', array_filter([
                $equipment?->brand?->nome,
                $equipment?->model?->nome,
            ], static fn ($value): bool => trim((string) $value) !== '')));
            if ($equipmentLabel !== '') {
                $segments[] = $equipmentLabel;
            }

            return $segments;
        }

        // Avulso, sem OS: para "a receber", mostra o cliente (se houver) e
        // deixa explícito que não há OS vinculada; para "a pagar", mostra o
        // fornecedor quando preenchido.
        if ((string) $financeiro->tipo === Financeiro::TIPO_RECEBER) {
            if ($financeiro->client instanceof Client) {
                $segments[] = (string) $financeiro->client->nome_razao;
            }
            $segments[] = 'sem OS vinculada';

            return $segments;
        }

        if ($financeiro->supplier instanceof Supplier) {
            $segments[] = (string) ($financeiro->supplier->nome_fantasia ?: $financeiro->supplier->razao_social);

            return $segments;
        }

        if ($segments === []) {
            $segments[] = 'Lançamento avulso';
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): Financeiro
    {
        $resolved = $this->resolveClassification($payload, null);

        $financeiro = Financeiro::create($resolved);
        $this->finalizeAfterSave($financeiro, $payload);
        $financeiro = $financeiro->refresh();

        // Timeline da OS: todo titulo vinculado a uma OS vira evento auditavel
        // (cobre tambem ensureReceivableTitle da baixa, que passa por aqui).
        if ((int) ($financeiro->os_id ?? 0) > 0) {
            $this->orderEventService->record(
                (int) $financeiro->os_id,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_TITULO_CRIADO,
                'Título financeiro criado',
                trim((string) $financeiro->descricao) !== '' ? (string) $financeiro->descricao : null,
                [
                    'financeiro_id' => (int) $financeiro->id,
                    'tipo' => (string) $financeiro->tipo,
                    'categoria' => (string) $financeiro->categoria,
                    'valor' => round((float) $financeiro->valor, 2),
                    'origem_tipo' => $financeiro->origem_tipo,
                ]
            );
        }

        return $financeiro;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Financeiro $financeiro, array $payload): Financeiro
    {
        $this->guardMutationAgainstMovements($financeiro, $payload);

        $antes = [
            'valor' => round((float) $financeiro->valor, 2),
            'status' => (string) $financeiro->status,
            'data_vencimento' => $financeiro->data_vencimento?->toDateString(),
            'descricao' => (string) $financeiro->descricao,
        ];

        $resolved = $this->resolveClassification($payload, $financeiro);
        $financeiro->update($resolved);
        $this->finalizeAfterSave($financeiro, $payload);
        $financeiro = $financeiro->refresh();

        if ((int) ($financeiro->os_id ?? 0) > 0) {
            $depois = [
                'valor' => round((float) $financeiro->valor, 2),
                'status' => (string) $financeiro->status,
                'data_vencimento' => $financeiro->data_vencimento?->toDateString(),
                'descricao' => (string) $financeiro->descricao,
            ];
            $diff = [];
            foreach ($antes as $campo => $valorAntes) {
                if ($valorAntes !== $depois[$campo]) {
                    $diff[$campo] = ['antes' => $valorAntes, 'depois' => $depois[$campo]];
                }
            }

            if ($diff !== []) {
                $this->orderEventService->record(
                    (int) $financeiro->os_id,
                    OrderEvent::CATEGORIA_FINANCEIRO,
                    OrderEvent::TIPO_TITULO_ATUALIZADO,
                    'Título financeiro atualizado',
                    'Campos alterados: '.implode(', ', array_keys($diff)).'.',
                    ['financeiro_id' => (int) $financeiro->id, 'campos' => $diff]
                );
            }
        }

        return $financeiro;
    }

    public function delete(Financeiro $financeiro): void
    {
        // Snapshot ANTES do hard delete — e a unica chance de auditar o que saiu.
        $osId = (int) ($financeiro->os_id ?? 0);
        $snapshot = [
            'financeiro_id' => (int) $financeiro->id,
            'tipo' => (string) $financeiro->tipo,
            'categoria' => (string) $financeiro->categoria,
            'descricao' => (string) $financeiro->descricao,
            'valor' => round((float) $financeiro->valor, 2),
            'status' => (string) $financeiro->status,
        ];

        $financeiro->delete();

        if ($osId > 0) {
            $this->orderEventService->record(
                $osId,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_TITULO_EXCLUIDO,
                'Título financeiro excluído',
                $snapshot['descricao'] !== '' ? $snapshot['descricao'] : null,
                $snapshot
            );
        }
    }

    /**
     * Monta o contexto operacional completo de um lançamento financeiro.
     *
     * A listagem precisa continuar leve e paginada; por isso este método é usado
     * apenas no detalhe do lançamento. Ele carrega, sob demanda, as relações que
     * ajudam a responder "quem pagou/recebeu", "qual OS/equipamento originou o
     * valor", "quais formas de pagamento foram usadas" e "quais taxas de cartão
     * foram aplicadas", sem duplicar regra de negócio na camada desktop.
     *
     * @return array<string, mixed>
     */
    public function detailContext(Financeiro $financeiro): array
    {
        $financeiro->loadMissing([
            'client',
            'supplier',
            'order.client',
            'order.equipment.type',
            'order.equipment.brand',
            'order.equipment.model',
            'order.statusCatalog',
            'movimentos.cartao.operadora',
            'movimentos.cartao.bandeira',
            'movimentos.conta',
            'origemMovimento.financeiro.client',
            'origemMovimento.financeiro.order.equipment.type',
            'origemMovimento.financeiro.order.equipment.brand',
            'origemMovimento.financeiro.order.equipment.model',
        ]);

        return [
            'tipo_label' => $this->financeiroTipoLabel((string) $financeiro->tipo),
            'status_label' => $this->financeiroStatusLabel((string) $financeiro->status),
            'forma_pagamento_label' => $this->paymentMethodLabel($financeiro->forma_pagamento),
            'contraparte' => $this->counterpartyDetail($financeiro),
            'origem' => $this->originDetail($financeiro),
            'os' => $this->orderDetail($financeiro->order),
            'movimentos' => $financeiro->movimentos
                ->sortBy([
                    ['data_movimento', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->map(fn (FinanceiroMovimento $movimento): array => $this->movementDetail($movimento))
                ->all(),
            'impactos' => [
                'impacta_dre' => (bool) $financeiro->impacta_dre,
                'impacta_fluxo_caixa' => (bool) $financeiro->impacta_fluxo_caixa,
                'dre_fixo_mensal' => (bool) $financeiro->dre_fixo_mensal,
                'grupo_dre' => $financeiro->grupo_dre,
                'subgrupo_dre' => $financeiro->subgrupo_dre,
                'data_competencia' => $this->dateForDetail($financeiro->data_competencia),
            ],
            'auditoria' => [
                'criado_em' => $this->dateTimeForDetail($financeiro->created_at),
                'atualizado_em' => $this->dateTimeForDetail($financeiro->updated_at),
            ],
        ];
    }

    /**
     * Cancela um título e estorna (remove) qualquer baixa já registrada, para
     * que o valor pare de contar no fluxo de caixa realizado e no DRE de
     * caixa — ambos calculados a partir de financeiro_movimentos. O DRE por
     * competência é filtrado por status=cancelado diretamente em
     * FinanceiroReportService::groupByCompetencia().
     *
     * Se algum dos movimentos deste título gerou uma despesa de taxa de
     * cartão (ver registerCardFeeExpense()), essa despesa é cancelada junto —
     * senão ela ficaria órfã, continuando a pesar no fluxo de caixa e no DRE
     * mesmo depois da receita que a gerou ter sido estornada.
     */
    public function cancel(Financeiro $financeiro): Financeiro
    {
        if ($financeiro->status === Financeiro::STATUS_CANCELADO) {
            throw new RuntimeException('Este título já está cancelado.');
        }

        $movimentoIds = $financeiro->movimentos()->pluck('id');

        if ($movimentoIds->isNotEmpty()) {
            Financeiro::query()
                ->where('origem_tipo', 'financeiro_movimento_cartao')
                ->whereIn('origem_id', $movimentoIds)
                ->where('status', '!=', Financeiro::STATUS_CANCELADO)
                ->get()
                ->each(function (Financeiro $taxaFinanceiro): void {
                    $taxaFinanceiro->movimentos()->delete();
                    $taxaFinanceiro->update([
                        'status' => Financeiro::STATUS_CANCELADO,
                        'data_pagamento' => null,
                        'forma_pagamento' => null,
                    ]);
                });
        }

        $movimentosEstornados = $movimentoIds->count();

        $financeiro->movimentos()->delete();

        $financeiro->update([
            'status' => Financeiro::STATUS_CANCELADO,
            'data_pagamento' => null,
            'forma_pagamento' => null,
        ]);

        if ((int) ($financeiro->os_id ?? 0) > 0) {
            $this->orderEventService->record(
                (int) $financeiro->os_id,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_TITULO_CANCELADO,
                'Título cancelado (estorno)',
                trim((string) $financeiro->descricao) !== '' ? (string) $financeiro->descricao : null,
                [
                    'financeiro_id' => (int) $financeiro->id,
                    'valor' => round((float) $financeiro->valor, 2),
                    'movimentos_estornados' => $movimentosEstornados,
                ]
            );
        }

        return $financeiro->refresh();
    }

    /**
     * Reduz (ou remove) movimentos de recebimento já registrados para que o
     * total baixado não ultrapasse um novo valor de título menor — necessário
     * quando o valor de uma OS encerrada é corrigido para baixo depois que o
     * pagamento já foi recebido (ex.: recebido R$60, corrigido para R$50).
     * Chamar ANTES de update() com o novo 'valor', senão guardMutationAgainstMovements()
     * rejeita a redução por já haver baixa maior registrada.
     *
     * Reduz a partir do movimento mais recente (LIFO) — mais fácil de conciliar
     * com o extrato bancário do que diluir proporcionalmente entre várias
     * linhas, e trata o pagamento mais recente como o mais provável de conter
     * o erro. Movimentos com meta de cartão vinculada nunca são tocados (uma
     * taxa de cartão já paga à operadora não pode ser "desfeita" automaticamente);
     * lança exceção se, mesmo pulando esses, sobrar excedente sem conseguir zerar.
     *
     * @return array{ajustado: bool, ajustes?: array<int, array<string, mixed>>, valor_liberado?: float}
     */
    public function reduceMovementsToTotal(Financeiro $financeiro, float $novoValorTitulo): array
    {
        $summary = $this->movementSummary($financeiro);
        $excedente = round($summary['valor_movimentado'] - $novoValorTitulo, 2);

        if ($excedente <= 0.009) {
            return ['ajustado' => false];
        }

        $movimentos = $financeiro->movimentos()
            ->with('cartao')
            ->orderByDesc('data_movimento')
            ->orderByDesc('id')
            ->get();

        $ajustes = [];

        foreach ($movimentos as $movimento) {
            if ($excedente <= 0.009) {
                break;
            }

            if ($movimento->cartao !== null) {
                continue;
            }

            $valorAtual = round((float) $movimento->valor_movimento, 2);
            $reducao = min($excedente, $valorAtual);
            $valorRestante = round($valorAtual - $reducao, 2);

            $ajustes[] = [
                'movimento_id' => (int) $movimento->id,
                'valor_antes' => $valorAtual,
                'valor_depois' => $valorRestante > 0.009 ? $valorRestante : 0.0,
                'removido' => $valorRestante <= 0.009,
            ];

            if ($valorRestante <= 0.009) {
                $movimento->delete();
            } else {
                $movimento->update(['valor_movimento' => $valorRestante]);
            }

            $excedente = round($excedente - $reducao, 2);
        }

        if ($excedente > 0.009) {
            throw new RuntimeException(
                'Não foi possível ajustar automaticamente: o valor excedente está concentrado em recebimentos via cartão, que exigem estorno manual.'
            );
        }

        return [
            'ajustado' => true,
            'ajustes' => $ajustes,
            'valor_liberado' => round($summary['valor_movimentado'] - $novoValorTitulo, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function registerMovement(Financeiro $financeiro, array $payload): array
    {
        return DB::transaction(function () use ($financeiro, $payload): array {
            $lockedFinanceiro = Financeiro::query()->lockForUpdate()->findOrFail($financeiro->id);

            return $this->registerMovementLocked($lockedFinanceiro, $payload);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function registerMovementLocked(Financeiro $financeiro, array $payload): array
    {
        if ($financeiro->status === Financeiro::STATUS_CANCELADO) {
            throw new RuntimeException('Não é possível registrar baixa em título cancelado.');
        }

        $summary = $this->movementSummary($financeiro);
        $valorAberto = round((float) $summary['valor_aberto'], 2);

        if ($valorAberto <= 0) {
            throw new RuntimeException('Este título já está totalmente liquidado.');
        }

        $valorMovimento = round((float) ($payload['valor_movimento'] ?? $payload['valor'] ?? 0), 2);

        if ($valorMovimento <= 0) {
            throw new RuntimeException('Informe um valor válido para a baixa.');
        }

        if ($valorMovimento > $valorAberto + 0.001) {
            throw new RuntimeException('O valor da baixa não pode ser maior que o saldo em aberto do título.');
        }

        $formaPagamento = trim((string) ($payload['forma_pagamento'] ?? ''));
        $observacoes = trim((string) ($payload['observacoes'] ?? ''));
        $documentoRef = trim((string) ($payload['documento_ref'] ?? ''));

        $dataMovimento = $this->normalizeDate($payload['data_movimento'] ?? $payload['data_pagamento'] ?? null) ?? now()->toDateString();
        $contaFinanceiraId = (bool) $financeiro->impacta_fluxo_caixa
            ? $this->financeiroContaService->resolveAccountId(
                ! empty($payload['conta_financeira_id']) ? (int) $payload['conta_financeira_id'] : null,
                $formaPagamento !== '' ? $formaPagamento : null,
                $dataMovimento
            )
            : null;

        $movimento = FinanceiroMovimento::create([
            'financeiro_id' => $financeiro->id,
            'conta_financeira_id' => $contaFinanceiraId,
            'tipo_movimento' => $financeiro->tipo === Financeiro::TIPO_RECEBER
                ? FinanceiroMovimento::TIPO_ENTRADA
                : FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => $dataMovimento,
            'valor_movimento' => $valorMovimento,
            'forma_pagamento' => $formaPagamento !== '' ? $formaPagamento : null,
            'documento_ref' => $documentoRef !== '' ? $documentoRef : null,
            'observacoes' => $observacoes !== '' ? $observacoes : null,
        ]);

        // O guard extra por operadora_id (além do forma_pagamento) é proposital:
        // outros chamadores de registerMovement() (ex.: OrderClosureService, no
        // fechamento de OS) simulam a taxa e registram o próprio
        // FinanceiroMovimentoCartao/despesa antes de chamar este método, sem
        // repassar operadora_id aqui — sem esse guard, este bloco tentaria
        // simular de novo sem operadora e derrubaria a baixa com exceção.
        if (str_contains($formaPagamento, 'cartao') && ! empty($payload['operadora_id'])) {
            $simulation = $this->registerCardMovementMeta($movimento, $payload, $valorMovimento, $dataMovimento, $observacoes);
            $this->registerCardFeeExpense($financeiro, $simulation, $movimento);
        }

        $summary = $this->syncFromMovements($financeiro);
        $summary['movement_id'] = $movimento->id;

        if ((int) ($financeiro->os_id ?? 0) > 0) {
            $this->orderEventService->record(
                (int) $financeiro->os_id,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_MOVIMENTO_REGISTRADO,
                $financeiro->tipo === Financeiro::TIPO_RECEBER ? 'Recebimento registrado' : 'Pagamento registrado',
                sprintf(
                    'R$ %s (%s) em %s.',
                    number_format($valorMovimento, 2, ',', '.'),
                    $formaPagamento !== '' ? $formaPagamento : 'forma não informada',
                    Carbon::parse($dataMovimento)->format('d/m/Y')
                ),
                [
                    'financeiro_id' => (int) $financeiro->id,
                    'movimento_id' => (int) $movimento->id,
                    'valor' => $valorMovimento,
                    'forma_pagamento' => $formaPagamento !== '' ? $formaPagamento : null,
                    'data_movimento' => $dataMovimento,
                ]
            );
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function registerCardMovementMeta(
        FinanceiroMovimento $movimento,
        array $payload,
        float $valorMovimento,
        string $dataMovimento,
        string $observacoes
    ): array {
        $simulation = $this->financeiroCartaoService->simulate([
            'valor_bruto' => $valorMovimento,
            'operadora_id' => $payload['operadora_id'] ?? null,
            'bandeira_id' => $payload['bandeira_id'] ?? null,
            'modalidade' => $payload['modalidade'] ?? null,
            'forma_pagamento' => $payload['forma_pagamento'] ?? null,
            'parcelas' => $payload['parcelas'] ?? 1,
        ]);

        FinanceiroMovimentoCartao::query()->create([
            'movimento_id' => $movimento->id,
            'operadora_id' => $simulation['operadora_id'] ?? null,
            'bandeira_id' => $simulation['bandeira_id'] ?? null,
            'taxa_id' => $simulation['taxa_id'] ?? null,
            'modalidade' => (string) ($simulation['modalidade'] ?? 'credito'),
            'parcelas' => (int) ($simulation['parcelas'] ?? 1),
            'valor_bruto' => round((float) ($simulation['valor_bruto'] ?? 0), 2),
            'taxa_percentual' => round((float) ($simulation['taxa_percentual'] ?? 0), 4),
            'taxa_fixa' => round((float) ($simulation['taxa_fixa'] ?? 0), 2),
            'valor_taxa' => round((float) ($simulation['valor_taxa'] ?? 0), 2),
            'valor_liquido' => round((float) ($simulation['valor_liquido'] ?? 0), 2),
            'prazo_recebimento_dias' => (int) ($simulation['prazo_recebimento_dias'] ?? 0),
            'data_competencia' => $dataMovimento,
            'data_prevista_repasse' => $simulation['data_prevista_repasse'] ?? null,
            'data_prevista_recebimento' => $simulation['data_prevista_recebimento'] ?? null,
            'data_credito_efetivo' => $simulation['data_credito_efetivo'] ?? null,
            'observacoes' => $observacoes !== '' ? $observacoes : null,
        ]);

        return $simulation;
    }

    /**
     * Registra a taxa da operadora como uma despesa própria (tipo=pagar), para
     * que o custo real da maquininha deixe de ser invisível no fluxo de caixa e
     * no DRE — sem isso, o título a receber ficava com o valor bruto do cartão
     * como se a assistência tivesse recebido o valor integral, sem controlar
     * quanto a operadora reteve. A baixa original do título permanece com o
     * valor bruto (é o que o cliente de fato pagou e o que quita o título);
     * a taxa é registrada como uma saída separada, já realizada (paga), com
     * seu próprio movimento para contar em todos os relatórios (competência e
     * caixa) — mesma classificação DRE ("Despesas Operacionais" / "Taxas e
     * impostos") já usada para outras taxas do sistema.
     *
     * A taxa é datada no mesmo dia do pagamento (data_movimento), não na data
     * prevista de repasse da operadora — a receita bruta do título também é
     * reconhecida no dia do pagamento, então a taxa precisa seguir a mesma
     * competência/caixa para o fluxo de caixa e o DRE baterem no mesmo dia
     * (a data prevista de repasse continua registrada em
     * financeiro_movimentos_cartao.data_prevista_repasse, só não é usada aqui).
     *
     * @param  array<string, mixed>  $simulation
     */
    private function registerCardFeeExpense(Financeiro $financeiro, array $simulation, FinanceiroMovimento $movimento): void
    {
        $valorTaxa = round((float) ($simulation['valor_taxa'] ?? 0), 2);
        if ($valorTaxa <= 0) {
            return;
        }

        $dataMovimento = $movimento->data_movimento->toDateString();
        $parcelas = (int) ($simulation['parcelas'] ?? 1);

        $taxaFinanceiro = Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            // Herda a OS do título pago (quando houver) — sem isso, a despesa
            // de taxa fica "solta" (os_id nulo) e o cancelamento dela nunca
            // aciona a trava de motivo+admin de OS encerrada (ver
            // FinanceiroController::resolveOsIsEncerrada()), mesmo a OS
            // estando fechada. Mesmo padrão já usado em
            // OrderClosureService::registerCardFeeExpense().
            'os_id' => $financeiro->os_id,
            'categoria' => 'Taxa de cartão',
            'descricao' => sprintf(
                'Taxa %s - Lançamento #%d (%s%s)',
                (string) ($simulation['operadora_nome'] ?? ''),
                $financeiro->id,
                (string) ($simulation['modalidade_label'] ?? ''),
                $parcelas > 1 ? ' em '.$parcelas.'x' : ''
            ),
            'valor' => $valorTaxa,
            'status' => Financeiro::STATUS_PAGO,
            'origem_tipo' => 'financeiro_movimento_cartao',
            'origem_id' => $movimento->id,
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Taxas e impostos',
            'data_vencimento' => $dataMovimento,
            'data_pagamento' => $dataMovimento,
            'data_competencia' => $dataMovimento,
            'forma_pagamento' => ($simulation['modalidade'] ?? '') === 'debito' ? 'cartao_debito' : 'cartao_credito',
            'observacoes' => 'Despesa criada automaticamente na baixa em cartão deste lançamento, para registrar o custo da operadora.',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => false,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $taxaFinanceiro->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => $dataMovimento,
            'valor_movimento' => $valorTaxa,
            'forma_pagamento' => $taxaFinanceiro->forma_pagamento,
            'observacoes' => 'Taxa da operadora referente ao movimento #'.$movimento->id.'.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function movementSummary(Financeiro $financeiro): array
    {
        $valorTitulo = round((float) $financeiro->valor, 2);

        $aggregate = $financeiro->movimentos()
            ->selectRaw('COUNT(*) as total_movimentos, COALESCE(SUM(valor_movimento), 0) as valor_movimentado, MAX(data_movimento) as ultimo_movimento_em')
            ->first();

        $valorMovimentado = round((float) ($aggregate->valor_movimentado ?? 0), 2);
        $valorAberto = max(0, round($valorTitulo - $valorMovimentado, 2));
        $totalMovimentos = (int) ($aggregate->total_movimentos ?? 0);

        $formasPagamento = $financeiro->movimentos()
            ->whereNotNull('forma_pagamento')
            ->distinct()
            ->pluck('forma_pagamento');

        $formaPagamentoResolvida = $totalMovimentos > 1
            ? 'multiplo'
            : ($formasPagamento->first() ?? null);

        return [
            'titulo_id' => $financeiro->id,
            'valor_titulo' => $valorTitulo,
            'valor_movimentado' => $valorMovimentado,
            'valor_aberto' => $valorAberto,
            'total_movimentos' => $totalMovimentos,
            'ultimo_movimento_em' => $aggregate->ultimo_movimento_em ?? null,
            'forma_pagamento_resolvida' => $formaPagamentoResolvida,
            'status_resolvido' => $this->resolveStatus($financeiro->status, $valorTitulo, $valorMovimentado),
            'percentual_quitado' => $valorTitulo > 0 ? min(100, round(($valorMovimentado / $valorTitulo) * 100, 2)) : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function counterpartyDetail(Financeiro $financeiro): array
    {
        if ($financeiro->tipo === Financeiro::TIPO_RECEBER) {
            $client = $financeiro->client ?? $financeiro->order?->client;

            return [
                'tipo' => 'cliente',
                'id' => $client?->id !== null ? (int) $client->id : null,
                'titulo' => 'Quem pagou',
                'nome' => $client?->nome_razao ?: null,
                'documento' => $client?->cpf_cnpj ?: null,
                'telefone' => $client?->telefone1 ?: $client?->telefone2 ?: null,
                'email' => $client?->email ?: null,
                'observacoes' => $client?->observacoes ?: null,
            ];
        }

        $supplier = $financeiro->supplier;

        return [
            'tipo' => 'fornecedor',
            'id' => $supplier?->id !== null ? (int) $supplier->id : null,
            'titulo' => 'Para quem pagou',
            'nome' => $supplier?->nome_fantasia ?: $supplier?->razao_social ?: null,
            'documento' => $supplier?->cnpj_cpf ?: null,
            'telefone' => $supplier?->telefone1 ?: $supplier?->telefone2 ?: null,
            'email' => $supplier?->email ?: null,
            'observacoes' => $supplier?->observacoes ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function originDetail(Financeiro $financeiro): array
    {
        if ((string) $financeiro->origem_tipo === 'financeiro_movimento_cartao') {
            $originMovement = $financeiro->origemMovimento;
            $originFinanceiro = $originMovement?->financeiro;

            return [
                'tipo' => 'taxa_cartao',
                'titulo' => 'Taxa de cartão',
                'descricao' => 'Despesa gerada automaticamente pela baixa em cartão de outro lançamento.',
                'lancamento_origem_id' => $originFinanceiro?->id,
                'movimento_origem_id' => $originMovement?->id,
                'lancamento_origem_descricao' => $originFinanceiro?->descricao,
            ];
        }

        if ((int) $financeiro->os_id > 0) {
            return [
                'tipo' => 'os',
                'titulo' => 'Ordem de serviço',
                'descricao' => 'Lançamento vinculado ao fluxo financeiro de uma OS.',
                'os_id' => (int) $financeiro->os_id,
            ];
        }

        if ((bool) $financeiro->avulso) {
            return [
                'tipo' => 'avulso',
                'titulo' => 'Lançamento avulso',
                'descricao' => (int) $financeiro->cliente_id > 0
                    ? 'Lançamento avulso com cliente vinculado, sem ordem de serviço.'
                    : 'Lançamento avulso puro, sem ordem de serviço e sem cliente vinculado.',
            ];
        }

        return [
            'tipo' => 'manual',
            'titulo' => 'Lançamento manual',
            'descricao' => 'Lançamento financeiro sem origem operacional específica registrada.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function orderDetail(?Order $order): ?array
    {
        if (! $order instanceof Order) {
            return null;
        }

        $equipment = $order->equipment;
        $equipmentParts = array_values(array_filter([
            $equipment?->type?->nome,
            $equipment?->brand?->nome,
            $equipment?->model?->nome,
        ], static fn ($value): bool => trim((string) $value) !== ''));

        return [
            'id' => (int) $order->id,
            'numero_os' => $order->numero_os,
            'status' => $order->status,
            'status_nome' => $order->statusCatalog?->nome ?: $order->status,
            'datas' => [
                'abertura' => $this->dateTimeForDetail($order->data_abertura),
                'entrada' => $this->dateTimeForDetail($order->data_entrada),
                'previsao' => $this->dateForDetail($order->data_previsao),
                'conclusao' => $this->dateTimeForDetail($order->data_conclusao),
                'entrega' => $this->dateTimeForDetail($order->data_entrega),
                'baixa_tecnica' => $this->dateTimeForDetail($order->baixa_tecnica_em),
            ],
            'valores' => [
                'mao_obra' => $order->valor_mao_obra !== null ? round((float) $order->valor_mao_obra, 2) : null,
                'pecas' => $order->valor_pecas !== null ? round((float) $order->valor_pecas, 2) : null,
                'total' => $order->valor_total !== null ? round((float) $order->valor_total, 2) : null,
                'desconto' => $order->desconto !== null ? round((float) $order->desconto, 2) : null,
                'final' => $order->valor_final !== null ? round((float) $order->valor_final, 2) : null,
            ],
            'cliente' => [
                'id' => $order->client?->id,
                'nome' => $order->client?->nome_razao,
                'telefone' => $order->client?->telefone1 ?: $order->client?->telefone2,
            ],
            'equipamento' => [
                'id' => $equipment?->id,
                'label' => $equipmentParts !== [] ? implode(' ', $equipmentParts) : ($equipment?->resumo_tecnico ?: null),
                'tipo' => $equipment?->type?->nome,
                'marca' => $equipment?->brand?->nome,
                'modelo' => $equipment?->model?->nome,
                'serie' => $equipment?->numero_serie,
                'imei' => $equipment?->imei,
                'resumo_tecnico' => $equipment?->resumo_tecnico,
                'estado_fisico' => $equipment?->estado_fisico,
            ],
            'defeito' => [
                'relato_cliente' => $order->relato_cliente,
                'diagnostico_tecnico' => $order->diagnostico_tecnico,
                'solucao_aplicada' => $order->solucao_aplicada,
                'procedimentos_executados' => $order->procedimentos_executados,
            ],
            'orcamento' => $this->budgetSummaryForOrder((int) $order->id),
        ];
    }

    /**
     * Orçamento mais recente da OS vinculada — permite ao frontend oferecer o
     * atalho "Ver orçamento" nos detalhes do lançamento.
     *
     * @return array<string, mixed>|null
     */
    private function budgetSummaryForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $budget = Budget::query()
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->first(['id', 'numero', 'status']);

        if ($budget === null) {
            return null;
        }

        return [
            'id' => (int) $budget->id,
            'numero' => $budget->numero,
            'status' => $budget->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movementDetail(FinanceiroMovimento $movimento): array
    {
        $cartao = $movimento->cartao;

        return [
            'id' => (int) $movimento->id,
            'tipo' => $movimento->tipo_movimento,
            'tipo_label' => $this->movementTypeLabel((string) $movimento->tipo_movimento),
            'data_movimento' => $this->dateForDetail($movimento->data_movimento),
            'valor' => round((float) $movimento->valor_movimento, 2),
            'forma_pagamento' => $movimento->forma_pagamento,
            'forma_pagamento_label' => $this->paymentMethodLabel($movimento->forma_pagamento),
            'conta_financeira' => $movimento->conta ? [
                'id' => (int) $movimento->conta->id,
                'nome' => $movimento->conta->nome,
            ] : null,
            'documento_ref' => $movimento->documento_ref,
            'observacoes' => $movimento->observacoes,
            'cartao' => $cartao instanceof FinanceiroMovimentoCartao ? [
                'operadora' => $cartao->operadora?->nome,
                'bandeira' => $cartao->bandeira?->nome,
                'modalidade' => $cartao->modalidade,
                'parcelas' => (int) $cartao->parcelas,
                'valor_bruto' => round((float) $cartao->valor_bruto, 2),
                'taxa_percentual' => round((float) $cartao->taxa_percentual, 4),
                'taxa_fixa' => round((float) $cartao->taxa_fixa, 2),
                'valor_taxa' => round((float) $cartao->valor_taxa, 2),
                'valor_liquido' => round((float) $cartao->valor_liquido, 2),
                'prazo_recebimento_dias' => (int) $cartao->prazo_recebimento_dias,
                'data_competencia' => $this->dateForDetail($cartao->data_competencia),
                'data_prevista_repasse' => $this->dateForDetail($cartao->data_prevista_repasse),
                'data_prevista_recebimento' => $this->dateForDetail($cartao->data_prevista_recebimento),
                'data_credito_efetivo' => $this->dateForDetail($cartao->data_credito_efetivo),
                'credito_confirmado_em' => $this->dateTimeForDetail($cartao->credito_confirmado_em),
            ] : null,
        ];
    }

    private function financeiroTipoLabel(string $tipo): string
    {
        return $tipo === Financeiro::TIPO_RECEBER ? 'A receber' : 'A pagar';
    }

    private function financeiroStatusLabel(string $status): string
    {
        return match ($status) {
            Financeiro::STATUS_PAGO => 'Pago',
            Financeiro::STATUS_PARCIAL => 'Parcial',
            Financeiro::STATUS_CANCELADO => 'Cancelado',
            default => 'Pendente',
        };
    }

    private function movementTypeLabel(string $tipo): string
    {
        return match ($tipo) {
            FinanceiroMovimento::TIPO_ENTRADA => 'Entrada',
            FinanceiroMovimento::TIPO_SAIDA => 'Saída',
            FinanceiroMovimento::TIPO_ESTORNO => 'Estorno',
            FinanceiroMovimento::TIPO_TRANSFERENCIA => 'Transferência',
            default => ucfirst($tipo),
        };
    }

    private function paymentMethodLabel(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'dinheiro' => 'Dinheiro',
            'cartao_credito' => 'Cartão de crédito',
            'cartao_debito' => 'Cartão de débito',
            'pix' => 'Pix',
            'boleto' => 'Boleto',
            'transferencia' => 'Transferência',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    private function dateForDetail(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $this->normalizeDate($value);
    }

    private function dateTimeForDetail(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncFromMovements(Financeiro $financeiro): array
    {
        $summary = $this->movementSummary($financeiro);
        $status = (string) $summary['status_resolvido'];
        $clearPagamento = in_array($status, [Financeiro::STATUS_CANCELADO, Financeiro::STATUS_PENDENTE], true);

        $formaPagamento = (string) ($summary['forma_pagamento_resolvida'] ?? '');

        $financeiro->update([
            'status' => $status,
            'data_pagamento' => $clearPagamento ? null : ($summary['ultimo_movimento_em'] ?? null),
            // "multiplo" é só um rótulo informativo do resumo da API; a coluna
            // financeiro.forma_pagamento é um ENUM restrito do banco real e não
            // aceita esse valor sintético — o detalhe de cada baixa já fica
            // registrado em financeiro_movimentos.forma_pagamento (texto livre).
            'forma_pagamento' => (! $clearPagamento && in_array($formaPagamento, Financeiro::FORMAS_PAGAMENTO, true))
                ? $formaPagamento
                : null,
        ]);

        return $summary;
    }

    private function resolveStatus(string $statusAtual, float $valorTitulo, float $valorMovimentado): string
    {
        if ($statusAtual === Financeiro::STATUS_CANCELADO) {
            return Financeiro::STATUS_CANCELADO;
        }

        if ($valorMovimentado <= 0) {
            return Financeiro::STATUS_PENDENTE;
        }

        if ($valorTitulo > 0 && $valorMovimentado + 0.001 < $valorTitulo) {
            return Financeiro::STATUS_PARCIAL;
        }

        return Financeiro::STATUS_PAGO;
    }

    /**
     * Depois de criar/atualizar o título, garante que o status declarado e os
     * movimentos de baixa fiquem consistentes entre si (mesma regra do legado:
     * "pago" sem movimento cria a baixa total automaticamente; "parcial" sem
     * movimento volta para "pendente").
     *
     * @param  array<string, mixed>  $payload
     */
    private function finalizeAfterSave(Financeiro $financeiro, array $payload): void
    {
        $summary = $this->movementSummary($financeiro);

        if ($financeiro->status === Financeiro::STATUS_CANCELADO) {
            if ((int) $summary['total_movimentos'] > 0) {
                throw new RuntimeException('Não é possível cancelar um título que já possui movimentos realizados.');
            }

            return;
        }

        if ((int) $summary['total_movimentos'] > 0) {
            $this->syncFromMovements($financeiro);

            return;
        }

        if ($financeiro->status === Financeiro::STATUS_PAGO) {
            $this->registerMovement($financeiro, [
                'valor_movimento' => $financeiro->valor,
                'data_movimento' => $payload['data_pagamento'] ?? $financeiro->data_pagamento,
                'forma_pagamento' => $payload['forma_pagamento'] ?? $financeiro->forma_pagamento,
                'conta_financeira_id' => $payload['conta_financeira_id'] ?? null,
                'observacoes' => $payload['observacoes'] ?? null,
            ]);

            return;
        }

        if ($financeiro->status === Financeiro::STATUS_PARCIAL) {
            $financeiro->update(['status' => Financeiro::STATUS_PENDENTE, 'data_pagamento' => null, 'forma_pagamento' => null]);

            return;
        }

        if ($financeiro->data_pagamento !== null || $financeiro->forma_pagamento !== null) {
            $financeiro->update(['data_pagamento' => null, 'forma_pagamento' => null]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function guardMutationAgainstMovements(Financeiro $financeiro, array $payload): void
    {
        $summary = $this->movementSummary($financeiro);
        if ((int) $summary['total_movimentos'] <= 0) {
            return;
        }

        if (array_key_exists('tipo', $payload) && (string) $payload['tipo'] !== $financeiro->tipo) {
            throw new RuntimeException('Não é possível alterar o tipo de um título que já possui movimentações registradas.');
        }

        if (
            array_key_exists('avulso', $payload)
            && filter_var($payload['avulso'], FILTER_VALIDATE_BOOL) !== (bool) $financeiro->avulso
        ) {
            throw new RuntimeException('Não é possível alterar o vínculo avulso de um título que já possui movimentações registradas.');
        }

        if (array_key_exists('impacta_fluxo_caixa', $payload) && ! filter_var($payload['impacta_fluxo_caixa'], FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Um título que já possui movimentos realizados deve continuar impactando o fluxo de caixa.');
        }

        $statusDestino = (string) ($payload['status'] ?? $financeiro->status);
        if ($statusDestino === Financeiro::STATUS_CANCELADO) {
            throw new RuntimeException('Não é possível cancelar um título que já possui movimentações registradas.');
        }

        if (array_key_exists('valor', $payload) && round((float) $payload['valor'], 2) + 0.001 < round((float) $summary['valor_movimentado'], 2)) {
            throw new RuntimeException('O valor total do título não pode ficar menor que o valor já baixado.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveClassification(array $payload, ?Financeiro $existing): array
    {
        $merged = array_merge($existing?->toArray() ?? [], $payload);

        $tipo = strtolower(trim((string) ($merged['tipo'] ?? '')));
        $categoriaNome = trim((string) ($merged['categoria'] ?? ''));
        $categoriaConfig = $categoriaNome !== ''
            ? FinanceiroCategoria::query()
                ->whereRaw('LOWER(nome) = ?', [mb_strtolower($categoriaNome, 'UTF-8')])
                ->whereIn('tipo', array_filter([$tipo, FinanceiroCategoria::TIPO_AMBOS]))
                ->with(['dre_grupo', 'dre_subgrupo'])
                ->first()
            : null;

        $resolved = $payload;

        $resolved['tipo'] = $tipo;
        $resolved['status'] = trim((string) ($payload['status'] ?? $existing?->status ?? '')) !== ''
            ? $payload['status'] ?? $existing?->status
            : Financeiro::STATUS_PENDENTE;
        $resolved['categoria'] = $categoriaNome !== '' ? $categoriaNome : $existing?->categoria;
        $resolved['descricao'] = trim((string) ($payload['descricao'] ?? '')) !== ''
            ? $payload['descricao']
            : ($existing?->descricao ?? $categoriaNome);

        $resolved['grupo_dre'] = trim((string) ($payload['grupo_dre'] ?? '')) !== ''
            ? $payload['grupo_dre']
            : ($categoriaConfig?->dre_grupo?->nome ?? $existing?->grupo_dre);

        $resolved['subgrupo_dre'] = trim((string) ($payload['subgrupo_dre'] ?? '')) !== ''
            ? $payload['subgrupo_dre']
            : ($categoriaConfig?->dre_subgrupo?->nome ?? $existing?->subgrupo_dre);

        $resolved['impacta_dre'] = array_key_exists('impacta_dre', $payload)
            ? filter_var($payload['impacta_dre'], FILTER_VALIDATE_BOOL)
            : ($existing?->impacta_dre ?? (bool) ($categoriaConfig?->impacta_dre_padrao ?? true));

        $resolved['impacta_fluxo_caixa'] = array_key_exists('impacta_fluxo_caixa', $payload)
            ? filter_var($payload['impacta_fluxo_caixa'], FILTER_VALIDATE_BOOL)
            : ($existing?->impacta_fluxo_caixa ?? (bool) ($categoriaConfig?->impacta_fluxo_caixa_padrao ?? true));

        $resolved['dre_fixo_mensal'] = $tipo === Financeiro::TIPO_PAGAR
            ? (array_key_exists('dre_fixo_mensal', $payload)
                ? filter_var($payload['dre_fixo_mensal'], FILTER_VALIDATE_BOOL)
                : ($existing?->dre_fixo_mensal ?? (bool) ($categoriaConfig?->dre_fixo_mensal_padrao ?? false)))
            : false;

        $resolved['data_competencia'] = $this->normalizeDate($payload['data_competencia'] ?? null)
            ?? $existing?->data_competencia?->toDateString()
            ?? $this->normalizeDate($merged['data_vencimento'] ?? null);

        if (($resolved['status'] ?? $existing?->status) === Financeiro::STATUS_PAGO && empty($merged['data_pagamento'])) {
            $resolved['data_pagamento'] = now()->toDateString();
        }

        $osId = (int) ($merged['os_id'] ?? 0);
        $avulso = array_key_exists('avulso', $payload)
            ? filter_var($payload['avulso'], FILTER_VALIDATE_BOOL)
            : (bool) ($existing?->avulso ?? false);

        if ($avulso && $osId > 0) {
            throw new RuntimeException('Lançamentos avulsos não podem ser vinculados a uma ordem de serviço.');
        }

        $resolved['avulso'] = $avulso;

        if ($tipo === Financeiro::TIPO_RECEBER) {
            $clienteId = (int) ($payload['cliente_id'] ?? $existing?->cliente_id ?? 0);

            if ($osId > 0) {
                $clienteOsId = (int) (Order::query()->where('id', $osId)->value('cliente_id') ?? 0);

                if ($clienteOsId <= 0) {
                    throw new RuntimeException('A ordem de serviço informada não possui um cliente válido.');
                }

                if ($clienteId > 0 && $clienteId !== $clienteOsId) {
                    throw new RuntimeException('O cliente informado não corresponde ao cliente da ordem de serviço.');
                }

                $clienteId = $clienteOsId;
            }

            if ($clienteId <= 0 && $osId <= 0 && ! $avulso) {
                throw new RuntimeException('Selecione o cliente desta cobrança ou vincule uma OS antes de salvar.');
            }

            $resolved['cliente_id'] = $clienteId > 0 ? $clienteId : null;
            $resolved['fornecedor_id'] = null;
        } else {
            $resolved['cliente_id'] = null;
            $resolved['fornecedor_id'] = ! empty($payload['fornecedor_id']) ? (int) $payload['fornecedor_id'] : null;
        }

        return $resolved;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }
}
