{{-- Grade mensal da agenda. Mesma estrutura da grade do Fluxo de Caixa
     (App\Support\CalendarGrid monta as duas), com o conteúdo do dia trocado:
     lá são valores, aqui são os compromissos. --}}
<div class="agenda-calendar-shell mt-3">
    @if ($weeks !== [])
        <div class="agenda-calendar-scroll">
            <div class="agenda-calendar-weekdays" aria-hidden="true">
                @foreach ($weekdays as $weekday)
                    <div class="agenda-calendar-weekday">{{ $weekday }}</div>
                @endforeach
            </div>

            @foreach ($weeks as $week)
                <div class="agenda-calendar-week">
                    @foreach ($week as $day)
                        @php
                            $items = $day['items'] ?? [];
                            $dayClasses = ['agenda-calendar-day'];
                            $dayClasses[] = $day['in_month'] ? 'is-current-month' : 'is-outside-month';
                            if ($day['is_today'] ?? false) { $dayClasses[] = 'is-today'; }
                            if ($day['is_weekend'] ?? false) { $dayClasses[] = 'is-weekend'; }
                            if (($day['atrasados'] ?? 0) > 0) { $dayClasses[] = 'has-late'; }
                        @endphp
                        <article class="{{ implode(' ', $dayClasses) }}">
                            <div class="agenda-calendar-day-head">
                                {{-- O número do dia leva à visão de dia: é o
                                     caminho natural de "o que tem aqui?". --}}
                                <a href="{{ route('agenda.index', array_filter([
                                    'data' => $day['date'],
                                    'view' => 'dia',
                                    'tipo' => $filtros['tipo'] ?? '',
                                    'status' => $filtros['status'] ?? '',
                                ])) }}" class="agenda-calendar-day-number">{{ $day['day'] }}</a>
                                @if (($day['total'] ?? 0) > 0)
                                    <span class="agenda-calendar-day-count">{{ $day['total'] }}</span>
                                @endif
                            </div>

                            <div class="agenda-calendar-day-body">
                                @forelse ($items as $item)
                                    @include('agenda._evento_chip', ['item' => $item, 'mostrarHora' => true])
                                @empty
                                    @if ($day['in_month'])
                                        <span class="agenda-calendar-day-empty">&nbsp;</span>
                                    @endif
                                @endforelse
                            </div>
                        </article>
                    @endforeach
                </div>
            @endforeach
        </div>
    @else
        <p class="text-muted mb-0">Nenhum dia para exibir neste período.</p>
    @endif
</div>
