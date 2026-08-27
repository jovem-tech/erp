@extends('layouts.app')

@php
    use App\Support\DesktopSession;

    $dashboard = is_array($dashboard ?? null) ? $dashboard : [];
    $summary = is_array($dashboard['resumo'] ?? null) ? $dashboard['resumo'] : [];
    $accounts = is_array($dashboard['contas'] ?? null) ? $dashboard['contas'] : [];
    $activeAccounts = array_values(array_filter($accounts, static fn (array $account): bool => (bool) ($account['ativo'] ?? false)));
    $pendingCards = is_array($dashboard['cartoes_pendentes'] ?? null) ? $dashboard['cartoes_pendentes'] : [];
    $transfers = is_array($dashboard['transferencias_recentes'] ?? null) ? $dashboard['transferencias_recentes'] : [];
    $unclassified = is_array($dashboard['sem_conta'] ?? null) ? $dashboard['sem_conta'] : [];
    $options = is_array($dashboard['opcoes'] ?? null) ? $dashboard['opcoes'] : [];
    $typeOptions = is_array($options['tipos'] ?? null) ? $options['tipos'] : [];
    $canViewFinanceiro = DesktopSession::can('financeiro', 'visualizar');
    $canCreate = DesktopSession::can('contas_saldos', 'criar');
    $canEdit = DesktopSession::can('contas_saldos', 'editar');
    $canViewCaixa = DesktopSession::can('caixa', 'visualizar');
    $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    $date = static fn ($value): string => $value ? date('d/m/Y', strtotime((string) $value)) : '—';
    $paymentLabels = [
        'dinheiro' => 'Dinheiro',
        'pix' => 'Pix',
        'cartao_credito' => 'Cartão de crédito',
        'cartao_debito' => 'Cartão de débito',
        'boleto' => 'Boleto',
        'transferencia' => 'Transferência',
    ];
    $typeLabels = collect($typeOptions)->pluck('label', 'value')->all();

    // Cartões de crédito da assistência (compras). Ficam numa aba própria
    // desta tela: são "onde o dinheiro está" do ponto de vista do usuário,
    // mas o saldo deles é a fatura em aberto (soma das despesas do ciclo),
    // não um saldo de conta — por isso nunca entram nos totais do topo.
    $cartoesCredito = is_array($cartoesCredito ?? null) ? $cartoesCredito : [];
    $totalFaturasAbertas = array_sum(array_map(
        static fn (array $cartao): float => (float) ($cartao['total_em_aberto'] ?? 0),
        $cartoesCredito
    ));
    $activeTab = in_array(request()->query('aba'), ['cartoes-credito'], true) ? 'cartoes-credito' : 'visao-geral';
@endphp

@section('styles')
    <style>
        .treasury-account-card { border-top: 4px solid var(--account-color, #3868b0); }
        .treasury-account-card.is-inactive { opacity: .7; }
        .treasury-balance { font-size: clamp(1.55rem, 2.3vw, 2.15rem); font-weight: 800; letter-spacing: -.04em; }
        .treasury-metric { background: rgba(255,255,255,.035); border: 1px solid rgba(148,163,184,.13); border-radius: 14px; padding: .8rem; }
        .treasury-metric span { display: block; font-size: .72rem; color: var(--bs-secondary-color); margin-bottom: .2rem; }
        .treasury-metric strong { font-size: .9rem; }
        .treasury-help { border-left: 4px solid #38bdf8; }
        .treasury-pending { color: #fbbf24; }
        .treasury-positive { color: #5ee0a0; }
        .treasury-negative { color: #fb7185; }
        .treasury-table td, .treasury-table th { vertical-align: middle; }
    </style>
@endsection

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Contas e Saldos <x-favorite-toggle /></h2>
            <p class="surface-subtitle mb-0">Quanto existe efetivamente em caixa, bancos, maquininhas e reservas — separado do faturamento do mês.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.contas.consolidado', ['mes' => $month]) }}" class="btn btn-outline-info">
                <i class="bi bi-table me-2"></i>Consolidado geral
            </a>
            @if ($canViewCaixa)
                {{-- Turno de caixa — specs/028-caixa-sessoes. Caixa é uma das
                     contas geridas aqui, então a tela de turno vive dentro de
                     Contas e Saldos, não num menu próprio. --}}
                <a href="{{ route('caixa.index') }}" class="btn btn-outline-info">
                    <i class="bi bi-cash-stack me-2"></i>Caixa
                </a>
            @endif
            @if ($canViewFinanceiro)
                <a href="{{ route('financeiro.index') }}" class="btn btn-outline-light">
                    <i class="bi bi-arrow-left me-2"></i>Lançamentos
                </a>
            @endif
            @if ($canEdit)
                <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#transferModal" @disabled(count($activeAccounts) < 2)>
                    <i class="bi bi-arrow-left-right me-2"></i>Transferir
                </button>
            @endif
            @if ($canCreate)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#accountCreateModal">
                    <i class="bi bi-plus-lg me-2"></i>Nova conta
                </button>
            @endif
        </div>
    </div>

    <div class="config-subtabs mb-2" role="tablist" aria-label="Contas e cartões">
        <button type="button" class="config-subtab {{ $activeTab === 'visao-geral' ? 'is-active' : '' }}" data-config-subtab="visao-geral">
            <i class="bi bi-wallet2 me-2"></i>Visão geral
        </button>
        <button type="button" class="config-subtab {{ $activeTab === 'cartoes-credito' ? 'is-active' : '' }}" data-config-subtab="cartoes-credito">
            <i class="bi bi-credit-card me-2"></i>Cartões de crédito
            @if ($cartoesCredito !== [])<span class="badge rounded-pill text-bg-secondary ms-2">{{ count($cartoesCredito) }}</span>@endif
        </button>
    </div>

    <div class="config-subpanel {{ $activeTab === 'visao-geral' ? 'is-active' : '' }}" data-config-subpanel="visao-geral">
    <section class="surface-card treasury-help mb-4">
        <div class="d-flex gap-3 align-items-start">
            <i class="bi bi-info-circle text-info fs-4"></i>
            <div>
                <strong>Esta tela controla patrimônio, não receita.</strong>
                <p class="surface-subtitle mb-0 mt-1">O saldo inicial, os ajustes de conciliação e as transferências entre contas nunca entram no faturamento nem no DRE. Recebimentos entram automaticamente na conta escolhida; cartão só fica disponível quando o crédito líquido é confirmado.</p>
            </div>
        </div>
    </section>

    <form method="GET" action="{{ route('financeiro.contas.index') }}" class="surface-card mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-4 col-xl-3">
                <label for="treasuryMonth" class="form-label">Mês de referência</label>
                <input id="treasuryMonth" type="month" name="mes" value="{{ $month }}" class="form-control" required>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary"><i class="bi bi-arrow-clockwise me-2"></i>Atualizar</button>
            </div>
            <div class="col text-md-end">
                <small class="text-body-secondary">Posição calculada até {{ $date($dashboard['ate'] ?? null) }}</small>
            </div>
        </div>
    </form>

    <div class="desktop-grid desktop-grid-four mb-4">
        <article class="summary-card">
            <p class="summary-card-eyebrow">Disponível operacional</p>
            <h3 class="summary-card-value treasury-positive">{{ $money($summary['disponivel_operacional'] ?? 0) }}</h3>
            <p class="summary-card-meta">Caixa e contas marcadas como disponíveis</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Total em contas</p>
            <h3 class="summary-card-value">{{ $money($summary['total_em_contas'] ?? 0) }}</h3>
            <p class="summary-card-meta">Inclui reservas já creditadas</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Cartão a receber</p>
            <h3 class="summary-card-value treasury-pending">{{ $money($summary['cartao_a_receber'] ?? 0) }}</h3>
            <p class="summary-card-meta">Líquido de taxas, ainda não confirmado</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Posição total</p>
            <h3 class="summary-card-value">{{ $money($summary['posicao_total'] ?? 0) }}</h3>
            <p class="summary-card-meta">Em contas + cartão pendente</p>
        </article>
    </div>

    @if ((int) ($unclassified['quantidade'] ?? 0) > 0)
        <div class="alert alert-warning d-flex gap-3 align-items-start mb-4">
            <i class="bi bi-exclamation-triangle fs-5"></i>
            <div>
                <strong>{{ (int) $unclassified['quantidade'] }} baixa(s) sem conta financeira</strong>
                <div>{{ $money($unclassified['valor'] ?? 0) }} precisa(m) ser revisado(s). Isso normalmente indica um recebimento antigo ou integração ainda não classificada.</div>
            </div>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="surface-title fs-5 mb-1">Saldos por conta</h3>
            <p class="surface-subtitle mb-0">A origem do pagamento e o lugar onde o dinheiro está são controles independentes.</p>
        </div>
    </div>

    @if ($accounts === [])
        <section class="surface-card text-center py-5 mb-4">
            <i class="bi bi-wallet2 fs-1 text-info"></i>
            <h3 class="surface-title fs-5 mt-3">Comece cadastrando onde o dinheiro está hoje</h3>
            <p class="surface-subtitle mx-auto" style="max-width: 680px">Exemplo: Caixa físico R$ 3.000, Conta Inter R$ 1.900, TOM a receber R$ 3.000 líquido e Reserva de lucro. Os valores entram como saldo inicial patrimonial.</p>
            @if ($canCreate)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#accountCreateModal">Cadastrar primeira conta</button>
            @endif
        </section>
    @else
        <div class="row g-4 mb-5">
            @foreach ($accounts as $account)
                @php
                    $balance = (float) ($account['saldo_disponivel'] ?? 0);
                    $accountId = (int) ($account['id'] ?? 0);
                    $isActive = (bool) ($account['ativo'] ?? false);
                    $forms = is_array($account['formas_padrao'] ?? null) ? $account['formas_padrao'] : [];
                @endphp
                <div class="col-xl-4 col-md-6">
                    <article class="surface-card h-100 treasury-account-card {{ $isActive ? '' : 'is-inactive' }}" style="--account-color: {{ $account['cor'] ?? '#3868B0' }}">
                        <div class="d-flex justify-content-between gap-3 mb-3">
                            <div>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <h3 class="surface-title fs-5 mb-0">{{ $account['nome'] ?? 'Conta' }}</h3>
                                    @unless($isActive)<span class="badge text-bg-secondary">Inativa</span>@endunless
                                    @unless((bool) ($account['considera_disponivel'] ?? false))<span class="badge text-bg-info">Reserva</span>@endunless
                                </div>
                                <small class="text-body-secondary">{{ $typeLabels[$account['tipo'] ?? ''] ?? ucfirst((string) ($account['tipo'] ?? 'Conta')) }}{{ !empty($account['instituicao']) ? ' · ' . $account['instituicao'] : '' }}</small>
                            </div>
                            <i class="bi bi-wallet2 fs-4" style="color: {{ $account['cor'] ?? '#3868B0' }}"></i>
                        </div>

                        <div class="mb-3">
                            <small class="text-body-secondary">Saldo disponível</small>
                            <div class="treasury-balance {{ $balance < 0 ? 'treasury-negative' : '' }}">{{ $money($balance) }}</div>
                            @if ((float) ($account['cartao_pendente'] ?? 0) > 0)
                                <div class="treasury-pending small"><i class="bi bi-hourglass-split me-1"></i>{{ $money($account['cartao_pendente']) }} de cartão pendente</div>
                            @endif
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-4"><div class="treasury-metric"><span>Início do mês</span><strong>{{ $money($account['mes']['saldo_inicial'] ?? 0) }}</strong></div></div>
                            <div class="col-4"><div class="treasury-metric"><span>Entradas</span><strong class="treasury-positive">{{ $money($account['mes']['entradas'] ?? 0) }}</strong></div></div>
                            <div class="col-4"><div class="treasury-metric"><span>Saídas</span><strong class="treasury-negative">{{ $money($account['mes']['saidas'] ?? 0) }}</strong></div></div>
                        </div>

                        <div class="small text-body-secondary mb-3">
                            <div>Controlada desde {{ $date($account['data_inicio_controle'] ?? null) }}</div>
                            @if ($forms !== [])
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    @foreach ($forms as $form)<span class="badge rounded-pill text-bg-secondary">Padrão: {{ $paymentLabels[$form] ?? $form }}</span>@endforeach
                                </div>
                            @endif
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-auto">
                            <a class="btn btn-sm btn-outline-light" href="{{ route('financeiro.contas.extrato', ['conta' => $accountId]) }}"><i class="bi bi-list-ul me-1"></i>Extrato</a>
                            @if ($canEdit && $isActive)
                                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#adjustModal{{ $accountId }}"><i class="bi bi-sliders me-1"></i>Conciliar</button>
                            @endif
                            @if ($canEdit)
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal{{ $accountId }}"><i class="bi bi-pencil me-1"></i>Editar</button>
                            @endif
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-7">
            <section class="surface-card h-100">
                <div class="mb-3">
                    <h3 class="surface-title fs-5 mb-1">Cartões aguardando crédito</h3>
                    <p class="surface-subtitle mb-0">Valor líquido após a taxa da operadora. Confirme somente quando aparecer no extrato da conta.</p>
                </div>
                <div class="table-responsive">
                    <table class="table treasury-table mb-0">
                        <thead><tr><th>Venda</th><th>Conta / operadora</th><th class="text-end">Líquido</th><th>Previsão</th>@if($canEdit)<th></th>@endif</tr></thead>
                        <tbody>
                            @forelse ($pendingCards as $card)
                                <tr>
                                    <td><strong>{{ $card['descricao'] ?? 'Recebimento em cartão' }}</strong><br><small class="text-body-secondary">{{ $date($card['data_venda'] ?? null) }}</small></td>
                                    <td>{{ $card['conta_nome'] ?? 'Conta' }}<br><small class="text-body-secondary">{{ $card['operadora'] ?? 'Operadora' }}</small></td>
                                    <td class="text-end"><strong>{{ $money($card['valor_liquido'] ?? 0) }}</strong><br><small class="text-body-secondary">taxa {{ $money($card['valor_taxa'] ?? 0) }}</small></td>
                                    <td>{{ $date($card['data_prevista'] ?? null) }}</td>
                                    @if($canEdit)
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('financeiro.contas.cartoes.confirmar', ['cartao' => (int) $card['id']]) }}" class="d-flex gap-2 justify-content-end">
                                                @csrf
                                                <input type="date" name="data_credito_efetivo" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control form-control-sm" style="min-width: 135px" required>
                                                <button class="btn btn-sm btn-success" title="Confirmar crédito"><i class="bi bi-check-lg"></i></button>
                                            </form>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-body-secondary py-4">Nenhum crédito de cartão pendente.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <div class="col-xl-5">
            <section class="surface-card h-100">
                <h3 class="surface-title fs-5 mb-1">Transferências recentes</h3>
                <p class="surface-subtitle mb-3">Movem saldo sem criar receita ou despesa.</p>
                <div class="d-grid gap-3">
                    @forelse ($transfers as $transfer)
                        <div class="treasury-metric">
                            <div class="d-flex justify-content-between gap-2"><strong>{{ $money($transfer['valor'] ?? 0) }}</strong><span class="badge {{ ($transfer['status'] ?? '') === 'cancelada' ? 'text-bg-secondary' : 'text-bg-success' }}">{{ ucfirst((string) ($transfer['status'] ?? '')) }}</span></div>
                            <div class="small mt-1">{{ $transfer['conta_origem'] ?? 'Origem' }} <i class="bi bi-arrow-right mx-1"></i> {{ $transfer['conta_destino'] ?? 'Destino' }}</div>
                            <div class="small text-body-secondary">{{ $date($transfer['data_transferencia'] ?? null) }} · {{ $transfer['descricao'] ?? '' }}</div>
                            @if ($canEdit && ($transfer['status'] ?? '') === 'realizada')
                                <form method="POST" action="{{ route('financeiro.contas.transferencias.cancelar', ['transferencia' => (int) $transfer['id']]) }}" class="d-flex gap-2 mt-2">
                                    @csrf
                                    <input name="motivo" class="form-control form-control-sm" placeholder="Motivo do cancelamento" minlength="5" maxlength="500" required>
                                    <button class="btn btn-sm btn-outline-danger">Cancelar</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-body-secondary mb-0">Nenhuma transferência registrada.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
    </div>{{-- /painel visão geral --}}

    <div class="config-subpanel {{ $activeTab === 'cartoes-credito' ? 'is-active' : '' }}" data-config-subpanel="cartoes-credito">
        <section class="surface-card treasury-help mb-4">
            <div class="d-flex gap-3 align-items-start">
                <i class="bi bi-credit-card text-info fs-4"></i>
                <div>
                    <strong>Cartões que a assistência usa para comprar.</strong>
                    <p class="surface-subtitle mb-0 mt-1">
                        A fatura concentra despesas fixas e variáveis do ciclo (fechamento → vencimento). O valor em aberto abaixo <strong>não entra</strong> nos totais de "Disponível operacional" nem "Total em contas": é dívida a pagar, não dinheiro em caixa. Ao marcar a fatura como paga, todas as despesas dela são baixadas de uma vez.
                    </p>
                </div>
            </div>
        </section>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h3 class="surface-title fs-5 mb-1">Cartões cadastrados</h3>
                <p class="surface-subtitle mb-0">
                    @if ($totalFaturasAbertas > 0)
                        {{ $money($totalFaturasAbertas) }} em faturas ainda em aberto.
                    @else
                        Nenhuma fatura em aberto no momento.
                    @endif
                </p>
            </div>
            @if ($canCreate)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#cartaoCreditoCreateModal">
                    <i class="bi bi-plus-lg me-2"></i>Novo cartão
                </button>
            @endif
        </div>

        @if ($cartoesCredito === [])
            <section class="surface-card text-center py-5">
                <i class="bi bi-credit-card-2-front fs-1 text-info"></i>
                <h3 class="surface-title fs-5 mt-3">Cadastre os cartões da assistência</h3>
                <p class="surface-subtitle mx-auto" style="max-width: 680px">
                    Informe o dia de fechamento e o de vencimento de cada cartão. A partir daí, toda despesa lançada no cartão cai automaticamente na fatura certa — e você vê quanto cada cartão já consumiu antes mesmo da fatura chegar.
                </p>
                @if ($canCreate)
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#cartaoCreditoCreateModal">Cadastrar primeiro cartão</button>
                @endif
            </section>
        @else
            <div class="row g-4">
                @foreach ($cartoesCredito as $cartao)
                    @php
                        $cartaoId = (int) ($cartao['id'] ?? 0);
                        $isActive = (bool) ($cartao['ativo'] ?? false);
                        $faturaAtual = is_array($cartao['fatura_atual'] ?? null) ? $cartao['fatura_atual'] : null;
                    @endphp
                    <div class="col-xl-4 col-md-6">
                        <article class="surface-card h-100 treasury-account-card {{ $isActive ? '' : 'is-inactive' }}" style="--account-color: {{ $cartao['cor'] ?? '#3868B0' }}">
                            <div class="d-flex justify-content-between gap-3 mb-3">
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <h3 class="surface-title fs-5 mb-0">{{ $cartao['nome'] ?? 'Cartão' }}</h3>
                                        @unless($isActive)<span class="badge text-bg-secondary">Inativo</span>@endunless
                                    </div>
                                    <small class="text-body-secondary d-block">
                                        {{ $cartao['instituicao'] ?: 'Cartão de crédito' }}{{ !empty($cartao['final_cartao']) ? ' · final ' . $cartao['final_cartao'] : '' }}
                                    </small>
                                    @if (!empty($cartao['conta_financeira_nome']))
                                        <small class="text-body-secondary">
                                            <i class="bi bi-wallet2 me-1"></i>Debita em {{ $cartao['conta_financeira_nome'] }}
                                        </small>
                                    @endif
                                </div>
                                <i class="bi bi-credit-card fs-4" style="color: {{ $cartao['cor'] ?? '#3868B0' }}"></i>
                            </div>

                            <div class="mb-3">
                                <small class="text-body-secondary">Fatura em aberto</small>
                                <div class="treasury-balance {{ (float) ($cartao['total_em_aberto'] ?? 0) > 0 ? 'treasury-pending' : '' }}">
                                    {{ $money($cartao['total_em_aberto'] ?? 0) }}
                                </div>
                                @if ($faturaAtual !== null)
                                    <div class="small text-body-secondary">
                                        Próximo vencimento em {{ $date($faturaAtual['data_vencimento'] ?? null) }}
                                        · {{ (int) ($faturaAtual['quantidade_despesas'] ?? 0) }} despesa(s)
                                    </div>
                                @endif
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-4"><div class="treasury-metric"><span>Fecha dia</span><strong>{{ (int) ($cartao['dia_fechamento'] ?? 0) }}</strong></div></div>
                                <div class="col-4"><div class="treasury-metric"><span>Vence dia</span><strong>{{ (int) ($cartao['dia_vencimento'] ?? 0) }}</strong></div></div>
                                <div class="col-4"><div class="treasury-metric"><span>Em aberto</span><strong>{{ (int) ($cartao['faturas_abertas'] ?? 0) }} fatura(s)</strong></div></div>
                            </div>

                            @if ($faturaAtual !== null)
                                <div class="d-flex flex-wrap gap-1 mb-3">
                                    <span class="badge rounded-pill text-bg-secondary">Fixas: {{ $money($faturaAtual['total_fixas'] ?? 0) }}</span>
                                    <span class="badge rounded-pill text-bg-secondary">Variáveis: {{ $money($faturaAtual['total_variaveis'] ?? 0) }}</span>
                                </div>
                            @endif

                            <div class="d-flex flex-wrap gap-2 mt-auto">
                                <a class="btn btn-sm btn-outline-light" href="{{ route('financeiro.cartoes-credito.faturas', ['cartaoCredito' => $cartaoId]) }}">
                                    <i class="bi bi-receipt me-1"></i>Faturas
                                </a>
                                @if ($canEdit)
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#cartaoCreditoEditModal{{ $cartaoId }}">
                                        <i class="bi bi-pencil me-1"></i>Editar
                                    </button>
                                @endif
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>
        @endif
    </div>{{-- /painel cartões --}}
@endsection

@if ($canCreate || $canEdit)
    @push('modals')
        @if ($canCreate)
        <div class="modal fade" id="accountCreateModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable"><form method="POST" action="{{ route('financeiro.contas.store') }}" class="modal-content">@csrf
                <div class="modal-header"><div><h5 class="modal-title">Nova conta financeira</h5><small class="text-body-secondary">Cadastre onde o dinheiro já está; não informe faturamento histórico.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="alert alert-info"><strong>Saldo inicial é patrimonial.</strong> Informe o valor real disponível nesta conta na data de início. Ele não será contabilizado como receita.</div>
                    <div class="row g-3">
                        <div class="col-md-7"><label class="form-label">Nome</label><input name="nome" class="form-control" placeholder="Ex.: Conta Inter" maxlength="100" required></div>
                        <div class="col-md-5"><label class="form-label">Tipo</label><select name="tipo" class="form-select" required>@foreach($typeOptions as $type)<option value="{{ $type['value'] }}">{{ $type['label'] }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">Instituição</label><input name="instituicao" class="form-control" placeholder="Ex.: Banco Inter, Stone TOM" maxlength="100"></div>
                        <div class="col-md-6"><label class="form-label">Integração bancária</label><select name="integracao_provider" class="form-select"><option value="">Nenhuma</option><option value="inter">Banco Inter (saldo e extrato)</option></select><small class="text-body-secondary">Só para conta do tipo banco. Habilita saldo e conciliação automática.</small></div>
                        <div class="col-md-3"><label class="form-label">Início do controle</label><input type="date" name="data_inicio_controle" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required></div>
                        <div class="col-md-3"><label class="form-label">Saldo inicial</label><input name="saldo_inicial" class="form-control" value="0,00" inputmode="decimal" required></div>
                        <div class="col-md-3"><label class="form-label">Cor</label><input type="color" name="cor" value="#3868B0" class="form-control form-control-color"></div>
                        <div class="col-md-9 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="considera_disponivel" value="1" id="createAvailable" checked><label class="form-check-label" for="createAvailable">Conta dinheiro disponível para operação</label></div></div>
                        <div class="col-12"><label class="form-label">Usar como padrão ao receber/pagar por</label><div class="d-flex flex-wrap gap-3">@foreach($paymentLabels as $value => $label)<label class="form-check"><input class="form-check-input" type="checkbox" name="formas_padrao[]" value="{{ $value }}"><span class="form-check-label">{{ $label }}</span></label>@endforeach</div></div>
                        <div class="col-12"><label class="form-label">Observações</label><textarea name="observacoes" class="form-control" rows="2" maxlength="2000"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Criar conta</button></div>
            </form></div>
        </div>
        @endif

        @if ($canEdit)
        <div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog"><form method="POST" action="{{ route('financeiro.contas.transferencias.store') }}" class="modal-content">@csrf
                <div class="modal-header"><div><h5 class="modal-title">Transferir entre contas</h5><small class="text-body-secondary">Não impacta faturamento nem DRE.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><div class="row g-3">
                    <div class="col-12"><label class="form-label">Conta de origem</label><select name="conta_origem_id" class="form-select" required><option value="">Selecione</option>@foreach($activeAccounts as $account)<option value="{{ $account['id'] }}">{{ $account['nome'] }} — {{ $money($account['saldo_disponivel'] ?? 0) }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label">Conta de destino</label><select name="conta_destino_id" class="form-select" required><option value="">Selecione</option>@foreach($activeAccounts as $account)<option value="{{ $account['id'] }}">{{ $account['nome'] }}</option>@endforeach</select></div>
                    <div class="col-sm-6"><label class="form-label">Valor</label><input name="valor" inputmode="decimal" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Data</label><input type="date" name="data_transferencia" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required></div>
                    <div class="col-12"><label class="form-label">Descrição</label><input name="descricao" value="Transferência entre contas" class="form-control" maxlength="255" required></div>
                    <div class="col-12"><label class="form-label">Documento / referência</label><input name="documento_ref" class="form-control" maxlength="100"></div>
                </div></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Transferir</button></div>
            </form></div>
        </div>

        @foreach ($accounts as $account)
            @php $accountId = (int) $account['id']; $forms = is_array($account['formas_padrao'] ?? null) ? $account['formas_padrao'] : []; @endphp
            <div class="modal fade" id="editModal{{ $accountId }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable"><form method="POST" action="{{ route('financeiro.contas.update', ['conta' => $accountId]) }}" class="modal-content">@csrf @method('PATCH')
                    <div class="modal-header"><h5 class="modal-title">Editar {{ $account['nome'] }}</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body"><div class="row g-3">
                        <div class="col-md-7"><label class="form-label">Nome</label><input name="nome" value="{{ $account['nome'] }}" class="form-control" maxlength="100" required></div>
                        <div class="col-md-5"><label class="form-label">Tipo</label><select name="tipo" class="form-select" required>@foreach($typeOptions as $type)<option value="{{ $type['value'] }}" @selected(($account['tipo'] ?? '') === $type['value'])>{{ $type['label'] }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">Instituição</label><input name="instituicao" value="{{ $account['instituicao'] ?? '' }}" class="form-control" maxlength="100"></div>
                        <div class="col-md-6"><label class="form-label">Integração bancária</label><select name="integracao_provider" class="form-select"><option value="">Nenhuma</option><option value="inter" @selected(($account['integracao_provider'] ?? '') === 'inter')>Banco Inter (saldo e extrato)</option></select><small class="text-body-secondary">Só para conta do tipo banco. Habilita saldo e conciliação automática.</small></div>
                        <div class="col-md-3"><label class="form-label">Início do controle</label><input type="date" name="data_inicio_controle" value="{{ $account['data_inicio_controle'] }}" max="{{ now()->toDateString() }}" class="form-control" required></div>
                        <div class="col-md-3"><label class="form-label">Cor</label><input type="color" name="cor" value="{{ $account['cor'] ?? '#3868B0' }}" class="form-control form-control-color"></div>
                        <div class="col-12 d-flex flex-wrap gap-4"><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="considera_disponivel" value="1" @checked((bool) ($account['considera_disponivel'] ?? false))><span class="form-check-label">Disponível para operação</span></label><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ativo" value="1" @checked((bool) ($account['ativo'] ?? false))><span class="form-check-label">Conta ativa</span></label></div>
                        <div class="col-12"><label class="form-label">Padrão por forma</label><div class="d-flex flex-wrap gap-3">@foreach($paymentLabels as $value => $label)<label class="form-check"><input class="form-check-input" type="checkbox" name="formas_padrao[]" value="{{ $value }}" @checked(in_array($value, $forms, true))><span class="form-check-label">{{ $label }}</span></label>@endforeach</div></div>
                        <div class="col-12"><label class="form-label">Observações</label><textarea name="observacoes" class="form-control" rows="2" maxlength="2000">{{ $account['observacoes'] ?? '' }}</textarea></div>
                    </div></div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Salvar</button></div>
                </form></div>
            </div>

            @if ((bool) ($account['ativo'] ?? false))
                <div class="modal fade" id="adjustModal{{ $accountId }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog"><form method="POST" action="{{ route('financeiro.contas.ajustes.store', ['conta' => $accountId]) }}" class="modal-content">@csrf
                        <div class="modal-header"><div><h5 class="modal-title">Conciliar {{ $account['nome'] }}</h5><small class="text-body-secondary">Use somente para corrigir diferença com o extrato ou contagem física.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body"><div class="alert alert-warning">Este ajuste altera o saldo patrimonial, mas nunca o faturamento ou o DRE. Descreva o motivo para manter a auditoria.</div><div class="row g-3">
                            <div class="col-sm-6"><label class="form-label">Natureza</label><select name="natureza" class="form-select" required><option value="entrada">Adicionar ao saldo</option><option value="saida">Retirar do saldo</option></select></div>
                            <div class="col-sm-6"><label class="form-label">Valor</label><input name="valor" inputmode="decimal" class="form-control" required></div>
                            <div class="col-sm-6"><label class="form-label">Data</label><input type="date" name="data_movimento" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required></div>
                            <div class="col-sm-6"><label class="form-label">Documento / referência</label><input name="documento_ref" class="form-control" maxlength="100"></div>
                            <div class="col-12"><label class="form-label">Motivo da diferença</label><input name="descricao" class="form-control" minlength="5" maxlength="255" required></div>
                        </div></div>
                        <div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Registrar ajuste</button></div>
                    </form></div>
                </div>
            @endif
        @endforeach
        @endif

        @include('financeiro._cartao_credito_form_modal', [
            'canCreate' => $canCreate,
            'canEdit' => $canEdit,
            'cartoesCredito' => $cartoesCredito,
            'accounts' => $accounts,
        ])
    @endpush
@endif
