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
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Fluxo de caixa</h2>
            <p class="surface-subtitle mb-0">Movimentos jÃ¡ realizados e tÃ­tulos com vencimento previsto, referÃªncia: {{ $fluxo['periodo_label'] ?? '' }}.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.relatorios.dre', ['mes' => $mes]) }}" class="btn btn-outline-light">
                <i class="bi bi-graph-up-arrow me-2"></i>
                DRE por competÃªncia
            </a>
            <div class="cashflow-view-toggle" role="group" aria-label="Alternar visualizaÃ§Ã£o do fluxo de caixa">
                <a href="{{ $listUrl }}" class="btn btn-outline-light {{ $viewMode === 'list' ? 'is-active' : '' }}" aria-pressed="{{ $viewMode === 'list' ? 'true' : 'false' }}">
                    <i class="bi bi-list-ul me-2"></i>
                    Lista
                </a>
                <a href="{{ $calendarUrl }}" class="btn btn-outline-light {{ $viewMode === 'calendar' ? 'is-active' : '' }}" aria-pressed="{{ $viewMode === 'calendar' ? 'true' : 'false' }}">
                    <i class="bi bi-calendar3 me-2"></i>
                    CalendÃ¡rio
                </a>
            </div>
        </div>
    </div>

    <section class="desktop-form-card mb-4">
        <form method="get" class="desktop-filter-grid">
            <input type="hidden" name="view" value="{{ $viewMode }}">
            <div>
                <label for="mes">MÃªs de referÃªncia</label>
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

    <div class="desktop-grid desktop-grid-three mb-4">
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Saldo inicial</p>
            <h3 class="surface-title mb-0">{{ $fmt($fluxo['saldo_inicial'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Saldo final (realizado)</p>
            <h3 class="surface-title mb-0">{{ $fmt($fluxo['saldo_final'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Saldo projetado</p>
            <h3 class="surface-title mb-0">{{ $fmt($fluxo['saldo_projetado'] ?? 0) }}</h3>
        </div>
    </div>

    <div class="desktop-grid desktop-grid-two mb-4">
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Realizado no perÃ­odo</h4>
            <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Entradas</span>
                <span class="fw-semibold">{{ $fmt($fluxo['entradas_realizadas'] ?? 0) }}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-secondary">SaÃ­das</span>
                <span class="fw-semibold">{{ $fmt($fluxo['saidas_realizadas'] ?? 0) }}</span>
            </div>
        </div>
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Previsto atÃ© o vencimento</h4>
            <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Entradas previstas</span>
                <span class="fw-semibold">{{ $fmt($fluxo['entradas_previstas'] ?? 0) }}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-secondary">SaÃ­das previstas</span>
                <span class="fw-semibold">{{ $fmt($fluxo['saidas_previstas'] ?? 0) }}</span>
            </div>
        </div>
    </div>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">{{ $viewMode === 'calendar' ? 'CalendÃ¡rio de lanÃ§amentos' : 'Movimento diÃ¡rio' }}</h2>
                <p class="surface-subtitle">
                    {{ $viewMode === 'calendar'
                        ? 'VisÃ£o mensal com entradas, saÃ­das e saldo por dia.'
                        : 'Saldo realizado acumulado dia a dia dentro do perÃ­odo.' }}
                </p>
            </div>

            @if ($viewMode === 'calendar')
                <div class="cashflow-calendar-nav d-flex flex-wrap align-items-center gap-2">
                    <a href="{{ $previousMonthUrl }}" class="btn btn-outline-light btn-sm" aria-label="MÃªs anterior">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                    <span class="desktop-chip">
                        <i class="bi bi-calendar3 me-1"></i>
                        {{ $calendarMonthLabel }}
                    </span>
                    <a href="{{ $nextMonthUrl }}" class="btn btn-outline-light btn-sm" aria-label="PrÃ³ximo mÃªs">
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
                        SaÃ­das
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
                                        $dayClasses = ['cashflow-calendar-day'];
                                        $dayClasses[] = $day['in_month'] ? 'is-current-month' : 'is-outside-month';
                                        $dayClasses[] = $day['has_movement']
                                            ? ((float) ($day['balance'] ?? 0) >= 0 ? 'is-positive' : 'is-negative')
                                            : 'is-neutral';

                                        if ((bool) ($day['is_today'] ?? false)) {
                                            $dayClasses[] = 'is-today';
                                        }
                                    @endphp
                                    <article class="{{ implode(' ', $dayClasses) }}" data-cashflow-day="{{ $day['date'] }}">
                                        <div class="cashflow-calendar-day-head">
                                            <span class="cashflow-calendar-day-number">{{ $day['day'] }}</span>
                                            @if ($day['in_month'])
                                                <span class="cashflow-calendar-day-balance">{{ $fmt($day['balance'] ?? 0) }}</span>
                                            @else
                                                <span class="cashflow-calendar-day-badge">&nbsp;</span>
                                            @endif
                                        </div>

                                        @if ($day['in_month'])
                                            <div class="cashflow-calendar-day-metrics">
                                                <div class="cashflow-calendar-metric is-positive">
                                                    <span>Entradas</span>
                                                    <strong>{{ $fmt($day['entries'] ?? 0) }}</strong>
                                                </div>
                                                <div class="cashflow-calendar-metric is-negative">
                                                    <span>SaÃ­das</span>
                                                    <strong>{{ $fmt($day['exits'] ?? 0) }}</strong>
                                                </div>
                                            </div>
                                            <p class="cashflow-calendar-day-note mb-0">
                                                {{ (float) ($day['net'] ?? 0) >= 0
                                                    ? (($day['has_movement'] ?? false) ? 'Saldo positivo no dia' : 'Sem lanÃ§amentos no dia')
                                                    : 'Saldo negativo no dia' }}
                                            </p>
                                        @else
                                            <p class="cashflow-calendar-day-note mb-0">Fora do mÃªs selecionado</p>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @else
                    @include('layouts.partials.empty-state', [
                        'icon' => 'bi-calendar3-week',
                        'title' => 'Sem calendÃ¡rio para exibir',
                        'message' => 'Ajuste o mÃªs de referÃªncia para ver os lanÃ§amentos em grade.',
                    ])
                @endif
            </div>
        @else
            @if ($linhas !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                        <tr><th>Data</th><th class="text-end">Entradas</th><th class="text-end">SaÃ­das</th><th class="text-end">Saldo realizado</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($linhas as $linha)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($linha['data'])->format('d/m/Y') }}</td>
                                <td class="text-end">{{ $fmt($linha['entradas_realizadas'] ?? 0) }}</td>
                                <td class="text-end">{{ $fmt($linha['saidas_realizadas'] ?? 0) }}</td>
                                <td class="text-end fw-semibold">{{ $fmt($linha['saldo_realizado'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-calendar3-week',
                    'title' => 'Sem movimentos no perÃ­odo',
                    'message' => 'Ajuste o mÃªs de referÃªncia para ver o detalhamento diÃ¡rio.',
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
                <p class="text-secondary mb-0">Sem movimentos classificados no perÃ­odo.</p>
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
                <p class="text-secondary mb-0">Sem tÃ­tulos pendentes no perÃ­odo.</p>
            @endforelse
        </div>
    </div>
@endsection
