@extends('layouts.app')

@section('content')
    @php
        $viewMode = in_array((string) ($viewMode ?? 'mes'), ['dia', 'semana', 'mes', 'ano', 'lista'], true)
            ? (string) $viewMode
            : 'mes';
        $tipos = $tipos ?? [];
        $filtros = $filtros ?? [];
        $google = $google ?? [];

        // Preserva cursor e filtros ao trocar de visão: quem está olhando uma
        // semana de setembro e clica em "Mês" espera cair em setembro.
        $urlFor = static fn (array $overrides = []) => route('agenda.index', array_filter(array_merge([
            'data' => $cursor,
            'view' => $viewMode,
            'tipo' => $filtros['tipo'] ?? '',
            'status' => $filtros['status'] ?? '',
        ], $overrides), static fn ($v) => $v !== '' && $v !== null));

        $viewOptions = [
            'dia' => ['label' => 'Dia', 'icon' => 'bi-calendar-day'],
            'semana' => ['label' => 'Semana', 'icon' => 'bi-calendar-week'],
            'mes' => ['label' => 'Mês', 'icon' => 'bi-calendar3'],
            'ano' => ['label' => 'Ano', 'icon' => 'bi-calendar4'],
            'lista' => ['label' => 'Lista', 'icon' => 'bi-list-ul'],
        ];

        $atrasados = (int) ($resumo['atrasados'] ?? 0);
        $hoje = (int) ($resumo['hoje'] ?? 0);
        $proximos7 = (int) ($resumo['proximos_7_dias'] ?? 0);

        $tituloPainel = match ($viewMode) {
            'dia' => 'Dia',
            'semana' => 'Semana',
            'ano' => 'Ano',
            'lista' => 'Lista de compromissos',
            default => 'Calendário do mês',
        };
        $subtituloPainel = match ($viewMode) {
            'dia' => 'Hora a hora, com os compromissos do dia inteiro na faixa de cima.',
            'semana' => 'Os sete dias lado a lado — clique num compromisso para abrir.',
            'ano' => 'O ano inteiro; a intensidade da cor mostra os dias mais carregados.',
            'lista' => 'Tudo do período em ordem cronológica.',
            default => 'Cada dia mostra o que está agendado — clique num compromisso para abrir.',
        };
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Visão geral</p>
            <h2 class="surface-title fs-3 mb-2">Agenda</h2>
            <p class="surface-subtitle mb-0">
                Compromissos, obrigações e lembretes num lugar só — {{ $periodLabel }}.
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <div class="agenda-view-toggle" role="group" aria-label="Alternar visualização da agenda">
                @foreach ($viewOptions as $key => $option)
                    <a href="{{ $urlFor(['view' => $key]) }}"
                       class="btn btn-outline-light {{ $viewMode === $key ? 'is-active' : '' }}"
                       aria-pressed="{{ $viewMode === $key ? 'true' : 'false' }}">
                        <i class="bi {{ $option['icon'] }}"></i>
                        <span>{{ $option['label'] }}</span>
                    </a>
                @endforeach
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#agendaFormModal" data-agenda-new>
                <i class="bi bi-plus-lg me-2"></i>Novo compromisso
            </button>
        </div>
    </div>

    {{-- Barra de estado: o que exige atenção agora, antes de qualquer navegação. --}}
    <div class="agenda-summary mb-4">
        <a href="{{ $urlFor(['view' => 'lista']) }}" class="agenda-summary-card {{ $atrasados > 0 ? 'is-late' : '' }}">
            <span class="agenda-summary-label">Atrasados</span>
            <strong class="agenda-summary-value">{{ $atrasados }}</strong>
            <span class="agenda-summary-hint">{{ $atrasados === 1 ? 'compromisso vencido' : 'compromissos vencidos' }}</span>
        </a>
        <a href="{{ $urlFor(['view' => 'dia', 'data' => $hojeCursor]) }}" class="agenda-summary-card is-today">
            <span class="agenda-summary-label">Hoje</span>
            <strong class="agenda-summary-value">{{ $hoje }}</strong>
            <span class="agenda-summary-hint">para resolver até o fim do dia</span>
        </a>
        <a href="{{ $urlFor(['view' => 'semana', 'data' => $hojeCursor]) }}" class="agenda-summary-card">
            <span class="agenda-summary-label">Próximos 7 dias</span>
            <strong class="agenda-summary-value">{{ $proximos7 }}</strong>
            <span class="agenda-summary-hint">na semana à frente</span>
        </a>
        <div class="agenda-summary-card agenda-summary-google {{ ($google['connected'] ?? false) ? 'is-connected' : '' }}">
            <span class="agenda-summary-label">
                <i class="bi bi-google me-1"></i>Google Agenda
            </span>
            <strong class="agenda-summary-value fs-6">{{ $google['status_label'] ?? 'Não configurado' }}</strong>
            <span class="agenda-summary-hint">
                @if ($google['connected'] ?? false)
                    Lembretes chegam no seu celular.
                @else
                    <a href="{{ route('configurations.integrations.index') }}#agenda-google">Conectar para receber no celular</a>
                @endif
            </span>
        </div>
    </div>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">{{ $tituloPainel }}</h2>
                <p class="surface-subtitle">{{ $subtituloPainel }}</p>
            </div>

            <div class="cashflow-calendar-nav d-flex flex-wrap align-items-center gap-2">
                <a href="{{ $urlFor(['data' => $hojeCursor]) }}" class="btn btn-outline-light btn-sm">Hoje</a>
                <a href="{{ $urlFor(['data' => $anterior]) }}" class="btn btn-outline-light btn-sm" aria-label="Período anterior">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <span class="desktop-chip"><i class="bi bi-calendar3 me-1"></i>{{ $periodLabel }}</span>
                <a href="{{ $urlFor(['data' => $proximo]) }}" class="btn btn-outline-light btn-sm" aria-label="Próximo período">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>

        {{-- Filtros por tipo: as fontes automáticas vêm do backend, então um
             módulo novo aparece aqui sozinho, sem tocar nesta view. --}}
        <div class="agenda-filters">
            <a href="{{ $urlFor(['tipo' => null]) }}" class="agenda-filter {{ ($filtros['tipo'] ?? '') === '' ? 'is-active' : '' }}">
                <i class="bi bi-collection"></i> Todos
            </a>
            @foreach ($tipos as $tipo)
                <a href="{{ $urlFor(['tipo' => $tipo['key']]) }}"
                   class="agenda-filter agenda-tipo-{{ $tipo['key'] }} {{ ($filtros['tipo'] ?? '') === $tipo['key'] ? 'is-active' : '' }}">
                    <i class="bi {{ $tipo['icon'] }}"></i> {{ $tipo['label'] }}
                </a>
            @endforeach
            <span class="agenda-filters-divider" aria-hidden="true"></span>
            <a href="{{ $urlFor(['status' => 'pendente']) }}" class="agenda-filter {{ ($filtros['status'] ?? '') === 'pendente' ? 'is-active' : '' }}">
                <i class="bi bi-hourglass-split"></i> Só pendentes
            </a>
            <a href="{{ $urlFor(['status' => 'concluido']) }}" class="agenda-filter {{ ($filtros['status'] ?? '') === 'concluido' ? 'is-active' : '' }}">
                <i class="bi bi-check2-circle"></i> Concluídos
            </a>
        </div>

        @switch ($viewMode)
            @case('dia')
            @case('semana')
                @include('agenda._grade_horaria', ['dias' => $dias, 'horas' => $horas, 'viewMode' => $viewMode])
                @break
            @case('ano')
                @include('agenda._ano', ['meses' => $meses])
                @break
            @case('lista')
                @include('agenda._lista', ['compromissos' => $compromissos])
                @break
            @default
                @include('agenda._calendario', [
                    'weeks' => $calendar['weeks'] ?? [],
                    'weekdays' => $calendar['weekdays'] ?? [],
                ])
        @endswitch
    </section>

    @include('agenda._form_modal')
    @include('agenda._detalhe_modal')

    <script src="{{ asset('assets/js/agenda.js') }}?v={{ filemtime(public_path('assets/js/agenda.js')) }}"></script>
@endsection
