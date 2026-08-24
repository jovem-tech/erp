@php
    $agenda = is_array($agenda ?? null) ? $agenda : [];
    $agendaAtrasados = (int) ($agenda['atrasados'] ?? 0);
    $agendaProximos = is_array($agenda['proximos'] ?? null) ? $agenda['proximos'] : [];
    $canViewAgenda = \App\Support\DesktopSession::can('agenda', 'visualizar');

    // Chips renderizados já no servidor: só os que dependem da agenda, que o
    // controller carregou junto com a página. Os demais vêm de /dashboard/dados
    // e nascem como skeleton — melhor um bloco cinza por 300ms do que um "0"
    // que o usuário lê como informação e depois vê trocar de valor.
    $agendaChip = $agendaAtrasados > 0 && $canViewAgenda
        ? [
            'tone' => 'danger',
            'value' => number_format($agendaAtrasados, 0, ',', '.'),
            'label' => $agendaAtrasados === 1 ? 'compromisso atrasado' : 'compromissos atrasados',
            'action_label' => 'Ver agenda',
            'url' => route('agenda.index', ['view' => 'lista']),
        ]
        : null;
@endphp

<section class="dashboard-attention mb-4" data-dashboard-attention-panel>
    <div class="dashboard-attention-head">
        <div>
            <span class="dashboard-kpi-label">Prioridades</span>
            <h2 class="dashboard-attention-title">O que merece sua atenção hoje</h2>
        </div>

        @if (\App\Support\DesktopSession::can('os', 'visualizar'))
            <a href="{{ route('orders.index', ['status_scope' => 'open']) }}" class="dashboard-attention-all">
                Ver tudo <i class="bi bi-arrow-right-short"></i>
            </a>
        @endif
    </div>

    <div class="dashboard-attention-grid" data-dashboard-attention-slot>
        @if ($agendaChip)
            @include('dashboard.partials._attention-chip', ['chip' => $agendaChip])
        @endif

        @for ($i = 0; $i < 3; $i++)
            <div class="dashboard-attention-chip is-skeleton" aria-hidden="true">
                <span class="dashboard-skeleton dashboard-skeleton-value"></span>
                <span class="dashboard-skeleton dashboard-skeleton-line"></span>
                <span class="dashboard-skeleton dashboard-skeleton-link"></span>
            </div>
        @endfor
    </div>

    @if ($canViewAgenda && $agendaProximos !== [])
        <div class="dashboard-attention-agenda">
            <div class="dashboard-attention-agenda-head">
                {{-- O título descreve o que a lista realmente mostra. O resumo da
                     agenda devolve `proximos` a partir de hoje, então anunciar
                     "atrasados" aqui — como a versão anterior fazia — prometia
                     uma lista que nunca aparecia. Os atrasados têm chip próprio
                     acima, que abre a agenda. --}}
                <span class="dashboard-kpi-label">Próximos compromissos</span>
                <a href="{{ route('agenda.index') }}" class="dashboard-attention-agenda-link">
                    Abrir agenda <i class="bi bi-arrow-right-short"></i>
                </a>
            </div>

            <ul class="dashboard-agenda-list">
                @foreach (array_slice($agendaProximos, 0, 4) as $item)
                    @php
                        $inicio = \Carbon\CarbonImmutable::parse($item['inicio_em']);
                        $isLate = (bool) ($item['atrasado'] ?? false);
                        $isToday = $inicio->isToday();
                        $itemTone = $isLate ? 'is-late' : ($isToday ? 'is-today' : 'is-upcoming');
                    @endphp
                    <li class="dashboard-agenda-item agenda-tipo-{{ $item['tipo'] ?? 'manual' }} {{ $itemTone }}">
                        <span class="dashboard-agenda-when">
                            {{ $inicio->format('d/m') }}
                            <small>{{ $item['hora'] ?? 'dia' }}</small>
                        </span>
                        <span class="dashboard-agenda-title">{{ $item['titulo'] ?? '' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
