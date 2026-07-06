@extends('layouts.app')

@section('content')
    @php
        $fmt = static fn ($valor) => 'R$ ' . number_format((float) ($valor ?? 0), 2, ',', '.');
        $linhas = $fluxo['linhas_diarias'] ?? [];
        $viewMode = in_array((string) ($viewMode ?? 'list'), ['list', 'calendar'], true) ? (string) $viewMode : 'list';
        $calendarWeeks = $calendar['weeks'] ?? [];
        $calendarWeekdays = $calendar['weekdays'] ?? ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
        $calendarMonthLabel = $calendar['month_label'] ?? ($fluxo['periodo_label'] ?? '');
        $listUrl = route('financeiro.relatorios.fluxo-caixa', ['mes' => $mes, 'view' => 'list']);
        $calendarUrl = route('financeiro.relatorios.fluxo-caixa', ['mes' => $mes, 'view' => 'calendar']);
        $previousMonthUrl = route('financeiro.relatorios.fluxo-caixa', ['mes' => $mesAnterior ?? $mes, 'view' => 'calendar']);
        $nextMonthUrl = route('financeiro.relatorios.fluxo-caixa', ['mes' => $mesProximo ?? $mes, 'view' => 'calendar']);

        // Deduplicado por movimento_id: o mesmo movimento pode aparecer duas
        // vezes (na tabela "pago/recebido" do dia da venda e em "previsto
        // para hoje" no dia do repasse), mas o detalhe do cartão é o mesmo —
        // um único modal por movimento evita ids duplicados no HTML.
        $cardDetailsByMovimento = [];
        foreach ($linhas as $linha) {
            foreach (($linha['detalhes']['movimentos'] ?? []) as $mov) {
                if (! empty($mov['cartao']) && ! isset($cardDetailsByMovimento[$mov['movimento_id']])) {
                    $cardDetailsByMovimento[$mov['movimento_id']] = $mov['cartao'];
                }
            }
            foreach (($linha['detalhes']['previstos_para_hoje'] ?? []) as $prev) {
                if (! empty($prev['cartao']) && ! isset($cardDetailsByMovimento[$prev['movimento_id']])) {
                    $cardDetailsByMovimento[$prev['movimento_id']] = $prev['cartao'];
                }
            }
        }
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Fluxo de caixa</h2>
            <p class="surface-subtitle mb-0">Movimentos já realizados e títulos com vencimento previsto, referência: {{ $fluxo['periodo_label'] ?? '' }}.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.relatorios.dre', ['mes' => $mes]) }}" class="btn btn-outline-light">
                <i class="bi bi-graph-up-arrow me-2"></i>
                DRE por competência
            </a>
            <div class="cashflow-view-toggle" role="group" aria-label="Alternar visualização do fluxo de caixa">
                <a href="{{ $listUrl }}" class="btn btn-outline-light {{ $viewMode === 'list' ? 'is-active' : '' }}" aria-pressed="{{ $viewMode === 'list' ? 'true' : 'false' }}">
                    <i class="bi bi-list-ul me-2"></i>
                    Lista
                </a>
                <a href="{{ $calendarUrl }}" class="btn btn-outline-light {{ $viewMode === 'calendar' ? 'is-active' : '' }}" aria-pressed="{{ $viewMode === 'calendar' ? 'true' : 'false' }}">
                    <i class="bi bi-calendar3 me-2"></i>
                    Calendário
                </a>
            </div>
        </div>
    </div>

    <section class="desktop-form-card mb-4">
        <form method="get" class="desktop-filter-grid">
            <input type="hidden" name="view" value="{{ $viewMode }}">
            <div>
                <label for="mes">Mês de referência</label>
                <input type="month" id="mes" name="mes" class="form-control" value="{{ $mes }}">
            </div>
            <div class="field-actions">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Atualizar
                </button>
            </div>
        </form>
    </section>

    <div class="desktop-grid desktop-grid-four mb-4">
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Saldo inicial</p>
            <h3 class="surface-title mb-0">{{ $fmt($fluxo['saldo_inicial'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Saldo final (realizado)</p>
            <h3 class="surface-title mb-0 cashflow-list-amount is-summary">{{ $fmt($fluxo['saldo_final'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Saldo projetado</p>
            <h3 class="surface-title mb-0">{{ $fmt($fluxo['saldo_projetado'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">
                Saldo líquido em conta
                <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="O que de fato está (ou vai estar) disponível no banco: soma o valor já líquido de taxa, no dia em que o dinheiro efetivamente pousa na conta — diferente do saldo realizado, que soma o bruto no dia da venda."></i>
            </p>
            <h3 class="surface-title mb-0 cashflow-list-amount is-projected">{{ $fmt($fluxo['saldo_liquido_final'] ?? 0) }}</h3>
        </div>
    </div>

    <div class="desktop-grid desktop-grid-two mb-4">
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Realizado no período</h4>
            <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Entradas</span>
                <span class="cashflow-list-amount is-positive">{{ $fmt($fluxo['entradas_realizadas'] ?? 0) }}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-secondary">Saídas</span>
                <span class="cashflow-list-amount is-negative">{{ $fmt($fluxo['saidas_realizadas'] ?? 0) }}</span>
            </div>
        </div>
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Previsto até o vencimento</h4>
            <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Entradas previstas</span>
                <span class="fw-semibold">{{ $fmt($fluxo['entradas_previstas'] ?? 0) }}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-secondary">Saídas previstas</span>
                <span class="fw-semibold">{{ $fmt($fluxo['saidas_previstas'] ?? 0) }}</span>
            </div>
        </div>
    </div>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">{{ $viewMode === 'calendar' ? 'Calendário de lançamentos' : 'Movimento diário' }}</h2>
                <p class="surface-subtitle">
                    {{ $viewMode === 'calendar'
                        ? 'Visão mensal com entradas, saídas e saldo por dia.'
                        : 'Saldo realizado acumulado dia a dia dentro do período.' }}
                </p>
            </div>

            @if ($viewMode === 'calendar')
                <div class="cashflow-calendar-nav d-flex flex-wrap align-items-center gap-2">
                    <a href="{{ $previousMonthUrl }}" class="btn btn-outline-light btn-sm" aria-label="Mês anterior">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                    <span class="desktop-chip">
                        <i class="bi bi-calendar3 me-1"></i>
                        {{ $calendarMonthLabel }}
                    </span>
                    <a href="{{ $nextMonthUrl }}" class="btn btn-outline-light btn-sm" aria-label="Próximo mês">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            @endif
        </div>

        @if ($viewMode === 'calendar')
            <div class="cashflow-calendar-shell mt-3">
                <div class="cashflow-calendar-legend">
                    <span class="cashflow-calendar-legend-item is-positive">
                        <i class="bi bi-arrow-down-right"></i>
                        Entradas
                    </span>
                    <span class="cashflow-calendar-legend-item is-negative">
                        <i class="bi bi-arrow-up-right"></i>
                        Saídas
                    </span>
                    <span class="cashflow-calendar-legend-item is-neutral">
                        <i class="bi bi-activity"></i>
                        Saldo
                    </span>
                </div>

                @if ($calendarWeeks !== [])
                    <div class="cashflow-calendar-scroll">
                        <div class="cashflow-calendar-weekdays" aria-hidden="true">
                            @foreach ($calendarWeekdays as $weekday)
                                <div class="cashflow-calendar-weekday">{{ $weekday }}</div>
                            @endforeach
                        </div>

                        @foreach ($calendarWeeks as $week)
                            <div class="cashflow-calendar-week">
                                @foreach ($week as $day)
                                    @php
                                        $dayBalance = (float) ($day['balance'] ?? 0);
                                        $dayEntries = (float) ($day['entries'] ?? 0);
                                        $dayExits = (float) ($day['exits'] ?? 0);
                                        $dayHasMovement = (bool) ($day['has_movement'] ?? false);
                                        $dayClasses = ['cashflow-calendar-day'];
                                        $dayClasses[] = $day['in_month'] ? 'is-current-month' : 'is-outside-month';
                                        $dayClasses[] = $dayHasMovement
                                            ? ($dayBalance > 0 ? 'is-positive' : ($dayBalance < 0 ? 'is-negative' : 'is-neutral'))
                                            : 'is-neutral';

                                        if ((bool) ($day['is_today'] ?? false)) {
                                            $dayClasses[] = 'is-today';
                                        }
                                    @endphp
                                    <article class="{{ implode(' ', $dayClasses) }}" data-cashflow-day="{{ $day['date'] }}">
                                        <div class="cashflow-calendar-day-head">
                                            <span class="cashflow-calendar-day-number">{{ $day['day'] }}</span>
                                            @if ($day['in_month'])
                                                @if ($dayBalance !== 0.0)
                                                    <span class="cashflow-calendar-day-balance">{{ $fmt($dayBalance) }}</span>
                                                @endif
                                            @else
                                                <span class="cashflow-calendar-day-badge">&nbsp;</span>
                                            @endif
                                        </div>

                                        @if ($day['in_month'])
                                            <div class="cashflow-calendar-day-metrics">
                                                @if ($dayHasMovement)
                                                    @if ($dayEntries !== 0.0)
                                                        <div class="cashflow-calendar-metric is-positive">
                                                            <span>Entradas</span>
                                                            <strong>{{ $fmt($dayEntries) }}</strong>
                                                        </div>
                                                    @endif
                                                    @if ($dayExits !== 0.0)
                                                        <div class="cashflow-calendar-metric is-negative">
                                                            <span>Saídas</span>
                                                            <strong>{{ $fmt($dayExits) }}</strong>
                                                        </div>
                                                    @endif
                                                @else
                                                    <div class="cashflow-calendar-day-empty">
                                                        <i class="bi bi-dash-circle"></i>
                                                        <span>Sem lançamentos</span>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @else
                    @include('layouts.partials.empty-state', [
                        'icon' => 'bi-calendar3-week',
                        'title' => 'Sem calendário para exibir',
                        'message' => 'Ajuste o mês de referência para ver os lançamentos em grade.',
                    ])
                @endif
            </div>
        @else
            @if ($linhas !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr>
                            <th>Data</th>
                            <th class="text-end">Entradas</th>
                            <th class="text-end">
                                Entrada projetada
                                <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="Quando o dinheiro efetivamente cai na conta. Vendas em cartão aparecem aqui na data do repasse/crédito, que pode ser dias ou semanas depois da venda — diferente de &quot;Entradas&quot;, que mostra o dia da venda."></i>
                            </th>
                            <th class="text-end">Saídas</th>
                            <th class="text-end">Saldo realizado</th>
                            <th class="text-end">
                                Saldo líquido
                                <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" title="O que de fato está disponível no banco nesse dia — já líquido de taxa, acumulado pelo dia em que o dinheiro efetivamente pousa na conta. Diferente do saldo realizado, que soma o bruto no dia da venda."></i>
                            </th>
                            <th class="text-end">Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($linhas as $linha)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($linha['data'])->format('d/m/Y') }}</td>
                                <td class="text-end">
                                    <span class="cashflow-list-amount is-positive">{{ $fmt($linha['entradas_realizadas'] ?? 0) }}</span>
                                </td>
                                <td class="text-end">
                                    <span class="cashflow-list-amount is-projected">{{ $fmt($linha['entrada_projetada'] ?? 0) }}</span>
                                </td>
                                <td class="text-end">
                                    <span class="cashflow-list-amount is-negative">{{ $fmt($linha['saidas_realizadas'] ?? 0) }}</span>
                                </td>
                                <td class="text-end">
                                    <span class="cashflow-list-amount is-summary">{{ $fmt($linha['saldo_realizado'] ?? 0) }}</span>
                                </td>
                                <td class="text-end">
                                    <span class="cashflow-list-amount is-projected">{{ $fmt($linha['saldo_liquido'] ?? 0) }}</span>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#dayDetailModal{{ str_replace('-', '', $linha['data']) }}">
                                        <i class="bi bi-list-check me-1"></i>
                                        Detalhes
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                {{--
                    Modais renderizados FORA da tabela, num loop separado —
                    mesmo padrão usado em financeiro/index.blade.php: um
                    <div class="modal"> dentro de <tbody> é HTML inválido e o
                    navegador aplica "foster parenting" (move o conteúdo para
                    antes da <table>), quebrando a estrutura interna do modal.
                --}}
                @foreach ($linhas as $linha)
                    @php
                        $diaId = str_replace('-', '', $linha['data']);
                        $diaFormatado = \Illuminate\Support\Carbon::parse($linha['data'])->format('d/m/Y');
                        $movimentosDia = $linha['detalhes']['movimentos'] ?? [];
                        $previstosHojeDia = $linha['detalhes']['previstos_para_hoje'] ?? [];
                    @endphp
                    <div class="modal fade" id="dayDetailModal{{ $diaId }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Lançamentos de {{ $diaFormatado }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                </div>
                                <div class="modal-body">
                                    <h6 class="surface-title fs-6">Pago/recebido neste dia</h6>
                                    @if ($movimentosDia !== [])
                                        <div class="table-responsive mb-4">
                                            <table class="table table-sm align-middle">
                                                <thead>
                                                <tr>
                                                    <th>Tipo</th>
                                                    <th>Origem</th>
                                                    <th>Categoria</th>
                                                    <th>Cliente/Fornecedor</th>
                                                    <th>Forma</th>
                                                    <th class="text-end">Valor</th>
                                                    <th>Cai na conta</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach ($movimentosDia as $mov)
                                                    <tr>
                                                        <td>
                                                            <span class="badge {{ $mov['tipo'] === 'receber' ? 'text-bg-success' : 'text-bg-secondary' }}">
                                                                {{ $mov['tipo'] === 'receber' ? 'Recebido' : 'Pago' }}
                                                            </span>
                                                        </td>
                                                        <td>{{ $mov['origem'] }}</td>
                                                        <td>{{ $mov['categoria'] ?? '-' }}</td>
                                                        <td>{{ $mov['contraparte'] ?? '-' }}</td>
                                                        <td>
                                                            {{ $mov['forma_pagamento'] ?? '-' }}
                                                            @if (! empty($mov['cartao']))
                                                                <button type="button" class="btn btn-sm btn-link p-0 ms-1 align-baseline" data-bs-toggle="modal" data-bs-target="#cardDetailModal{{ $mov['movimento_id'] }}" title="Detalhes do cartão">
                                                                    <i class="bi bi-credit-card-2-front"></i>
                                                                </button>
                                                            @endif
                                                        </td>
                                                        <td class="text-end">{{ $fmt($mov['valor']) }}</td>
                                                        <td>{{ $mov['data_prevista_caixa'] ? \Illuminate\Support\Carbon::parse($mov['data_prevista_caixa'])->format('d/m/Y') : 'Imediato' }}</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @else
                                        <p class="text-secondary">Nenhum lançamento neste dia.</p>
                                    @endif

                                    <h6 class="surface-title fs-6 mt-4">
                                        Entradas de cartão previstas para cair hoje
                                    </h6>
                                    <p class="small text-secondary mb-2">
                                        Vendas feitas em outros dias cujo repasse/crédito está previsto para cair na conta justamente hoje — informativo, não conta no saldo realizado.
                                    </p>
                                    @if ($previstosHojeDia !== [])
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle">
                                                <thead>
                                                <tr>
                                                    <th>Origem</th>
                                                    <th>Categoria</th>
                                                    <th>Cliente</th>
                                                    <th>Forma</th>
                                                    <th class="text-end">Valor</th>
                                                    <th>Vendido em</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach ($previstosHojeDia as $prev)
                                                    <tr>
                                                        <td>{{ $prev['origem'] }}</td>
                                                        <td>{{ $prev['categoria'] ?? '-' }}</td>
                                                        <td>{{ $prev['contraparte'] ?? '-' }}</td>
                                                        <td>
                                                            {{ $prev['forma_pagamento'] ?? '-' }}
                                                            @if (! empty($prev['cartao']))
                                                                <button type="button" class="btn btn-sm btn-link p-0 ms-1 align-baseline" data-bs-toggle="modal" data-bs-target="#cardDetailModal{{ $prev['movimento_id'] }}" title="Detalhes do cartão">
                                                                    <i class="bi bi-credit-card-2-front"></i>
                                                                </button>
                                                            @endif
                                                        </td>
                                                        <td class="text-end">{{ $fmt($prev['valor']) }}</td>
                                                        <td>{{ \Illuminate\Support\Carbon::parse($prev['data_venda'])->format('d/m/Y') }}</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @else
                                        <p class="text-secondary mb-0">Nenhuma entrada de cartão prevista para cair hoje.</p>
                                    @endif
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                {{--
                    Modais de detalhe de cartão, um por movimento_id (não por
                    dia) — ficam fora dos modais de dia para não duplicar o
                    mesmo id quando o movimento aparece tanto em
                    "pago/recebido" quanto em "previsto para hoje". Abrir um
                    destes a partir de um modal de dia já aberto empilha dois
                    `.modal` do Bootstrap ao mesmo tempo; o ajuste de
                    z-index/backdrop para esse caso já é feito de forma
                    genérica em assets/js/desktop.js.
                --}}
                @foreach ($cardDetailsByMovimento as $movimentoId => $detalheCartao)
                    <div class="modal fade" id="cardDetailModal{{ $movimentoId }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Detalhes do cartão</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                </div>
                                <div class="modal-body">
                                    <dl class="row mb-0">
                                        <dt class="col-6">Operadora</dt>
                                        <dd class="col-6">{{ $detalheCartao['operadora'] ?? '-' }}</dd>

                                        <dt class="col-6">Bandeira</dt>
                                        <dd class="col-6">{{ $detalheCartao['bandeira'] ?? 'Genérica (qualquer bandeira)' }}</dd>

                                        <dt class="col-6">Modalidade</dt>
                                        <dd class="col-6">{{ $detalheCartao['modalidade'] === 'debito' ? 'Débito' : 'Crédito' }}</dd>

                                        <dt class="col-6">Parcelas</dt>
                                        <dd class="col-6">{{ $detalheCartao['parcelas'] }}x</dd>

                                        <dt class="col-6">Taxa da operadora</dt>
                                        <dd class="col-6">{{ number_format($detalheCartao['taxa_percentual'], 2, ',', '.') }}% + {{ $fmt($detalheCartao['taxa_fixa']) }}</dd>

                                        <dt class="col-6">Valor da taxa</dt>
                                        <dd class="col-6">{{ $fmt($detalheCartao['valor_taxa']) }}</dd>

                                        <dt class="col-6">Valor líquido</dt>
                                        <dd class="col-6">{{ $fmt($detalheCartao['valor_liquido']) }}</dd>

                                        <dt class="col-6">Prazo de recebimento</dt>
                                        <dd class="col-6">{{ $detalheCartao['prazo_recebimento_dias'] }} dia(s)</dd>
                                    </dl>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-calendar3-week',
                    'title' => 'Sem movimentos no período',
                    'message' => 'Ajuste o mês de referência para ver o detalhamento diário.',
                ])
            @endif
        @endif
    </section>

    <div class="desktop-grid desktop-grid-two mt-4">
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Realizado por categoria</h4>
            @forelse (($fluxo['realizados_por_categoria'] ?? []) as $categoria => $valor)
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-secondary">{{ $categoria }}</span>
                    <span class="fw-semibold">{{ $fmt($valor) }}</span>
                </div>
            @empty
                <p class="text-secondary mb-0">Sem movimentos classificados no período.</p>
            @endforelse
        </div>
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Previsto por categoria</h4>
            @forelse (($fluxo['previstos_por_categoria'] ?? []) as $categoria => $valor)
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-secondary">{{ $categoria }}</span>
                    <span class="fw-semibold">{{ $fmt($valor) }}</span>
                </div>
            @empty
                <p class="text-secondary mb-0">Sem títulos pendentes no período.</p>
            @endforelse
        </div>
    </div>
@endsection
