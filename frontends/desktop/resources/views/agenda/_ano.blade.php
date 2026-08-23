{{-- Doze mini-meses. Não cabe título de compromisso aqui, então cada dia mostra
     densidade: quanto mais carregado, mais forte a cor. É a visão de "onde o
     ano aperta" — clicar num mês ou num dia leva à visão detalhada. --}}
@php
    // Escala relativa ao próprio ano: um ano de pico 3 e outro de pico 30 não
    // podem pintar o mesmo tom para "1 compromisso".
    $maxNoAno = 0;
    foreach ($meses as $mes) {
        foreach ($mes['grid']['weeks'] as $semana) {
            foreach ($semana as $dia) {
                if ($dia['in_month']) {
                    $maxNoAno = max($maxNoAno, (int) ($dia['total'] ?? 0));
                }
            }
        }
    }
    $nivel = static function (int $total) use ($maxNoAno): int {
        if ($total <= 0 || $maxNoAno <= 0) { return 0; }
        return (int) max(1, min(4, ceil($total / $maxNoAno * 4)));
    };
@endphp

<div class="agenda-year mt-3">
    @foreach ($meses as $mes)
        <section class="agenda-year-month">
            <a href="{{ route('agenda.index', array_filter([
                'data' => $mes['month_param'].'-01',
                'view' => 'mes',
                'tipo' => $filtros['tipo'] ?? '',
                'status' => $filtros['status'] ?? '',
            ])) }}" class="agenda-year-month-title">{{ ucfirst($mes['label']) }}</a>

            <div class="agenda-year-weekdays" aria-hidden="true">
                @foreach ($mes['grid']['weekdays'] as $weekday)
                    <span>{{ mb_substr($weekday, 0, 1) }}</span>
                @endforeach
            </div>

            <div class="agenda-year-days">
                @foreach ($mes['grid']['weeks'] as $semana)
                    @foreach ($semana as $dia)
                        @if (! $dia['in_month'])
                            <span class="agenda-year-day is-outside" aria-hidden="true"></span>
                        @else
                            @php
                                $total = (int) ($dia['total'] ?? 0);
                                $classes = ['agenda-year-day', 'level-'.$nivel($total)];
                                if ($dia['is_today']) { $classes[] = 'is-today'; }
                                if (($dia['atrasados'] ?? 0) > 0) { $classes[] = 'has-late'; }
                            @endphp
                            <a href="{{ route('agenda.index', array_filter([
                                'data' => $dia['date'],
                                'view' => 'dia',
                                'tipo' => $filtros['tipo'] ?? '',
                                'status' => $filtros['status'] ?? '',
                            ])) }}"
                               class="{{ implode(' ', $classes) }}"
                               title="{{ $dia['date'] }}{{ $total > 0 ? ' — '.$total.($total === 1 ? ' compromisso' : ' compromissos') : '' }}">
                                {{ $dia['day'] }}
                            </a>
                        @endif
                    @endforeach
                @endforeach
            </div>
        </section>
    @endforeach
</div>

<div class="agenda-year-legend">
    <span>Menos</span>
    <span class="agenda-year-day level-0" aria-hidden="true"></span>
    <span class="agenda-year-day level-1" aria-hidden="true"></span>
    <span class="agenda-year-day level-2" aria-hidden="true"></span>
    <span class="agenda-year-day level-3" aria-hidden="true"></span>
    <span class="agenda-year-day level-4" aria-hidden="true"></span>
    <span>Mais</span>
</div>
