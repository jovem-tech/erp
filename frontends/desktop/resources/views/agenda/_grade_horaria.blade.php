{{-- Grade de 24 horas usada pelas visões Dia e Semana.

     A mesma partial serve as duas porque a diferença entre elas é só o número
     de colunas: dia = 1, semana = 7. Duplicar o arquivo faria as duas
     divergirem no primeiro ajuste de altura de hora ou de faixa de dia inteiro.

     Compromissos de dia inteiro (vencimentos, prazos de OS) ficam na faixa fixa
     do topo, como no Google Agenda: eles valem o dia todo e colocá-los numa
     linha de horário mentiria sobre o dado. --}}
@php
    $isDia = ($viewMode ?? 'semana') === 'dia';
    $agora = \Carbon\CarbonImmutable::now();
    // Percentual do dia já decorrido — posiciona a linha vermelha do "agora".
    $agoraPct = round((($agora->hour * 60) + $agora->minute) / 1440 * 100, 4);
    $temAllDay = collect($dias)->contains(fn (array $d) => ($d['all_day'] ?? []) !== []);
@endphp

<div class="agenda-timegrid {{ $isDia ? 'is-single-day' : '' }}" style="--agenda-columns: {{ count($dias) }};">
    <div class="agenda-timegrid-scroll">
        {{-- Cabeçalho com os dias --}}
        <div class="agenda-timegrid-head">
            <div class="agenda-timegrid-gutter" aria-hidden="true"></div>
            @foreach ($dias as $dia)
                <div class="agenda-timegrid-dayhead {{ $dia['is_today'] ? 'is-today' : '' }} {{ $dia['is_weekend'] ? 'is-weekend' : '' }}">
                    <span class="agenda-timegrid-weekday">{{ $isDia ? $dia['weekday_long'] : $dia['weekday_short'] }}</span>
                    <span class="agenda-timegrid-daynum">{{ $dia['day'] }}</span>
                </div>
            @endforeach
        </div>

        {{-- Faixa de dia inteiro. Só aparece quando há algo nela: uma faixa
             vazia permanente rouba altura da grade sem informar nada. --}}
        @if ($temAllDay)
            <div class="agenda-timegrid-allday">
                <div class="agenda-timegrid-gutter">
                    <span class="agenda-timegrid-allday-label">Dia inteiro</span>
                </div>
                @foreach ($dias as $dia)
                    <div class="agenda-timegrid-allday-cell {{ $dia['is_today'] ? 'is-today' : '' }}">
                        @foreach ($dia['all_day'] ?? [] as $item)
                            @include('agenda._evento_chip', ['item' => $item, 'mostrarHora' => false])
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Corpo: 24 linhas de hora + uma coluna por dia --}}
        <div class="agenda-timegrid-body">
            <div class="agenda-timegrid-gutter agenda-timegrid-hours">
                @foreach ($horas as $index => $hora)
                    <div class="agenda-timegrid-hour">
                        {{-- 00:00 não recebe rótulo: ele ficaria cortado na borda de cima. --}}
                        <span>{{ $index === 0 ? '' : $hora }}</span>
                    </div>
                @endforeach
            </div>

            @foreach ($dias as $dia)
                <div class="agenda-timegrid-col {{ $dia['is_today'] ? 'is-today' : '' }} {{ $dia['is_weekend'] ? 'is-weekend' : '' }}">
                    @foreach ($horas as $index => $hora)
                        <div class="agenda-timegrid-slot" aria-hidden="true"></div>
                    @endforeach

                    @if ($dia['is_today'])
                        <div class="agenda-timegrid-now" style="top: {{ $agoraPct }}%;" aria-hidden="true">
                            <span class="agenda-timegrid-now-dot"></span>
                        </div>
                    @endif

                    @foreach ($dia['timed'] ?? [] as $item)
                        @php $pos = $item['position'] ?? ['top' => 0, 'height' => 4, 'left' => 0, 'width' => 100]; @endphp
                        <div class="agenda-timegrid-event-slot"
                             style="top: {{ $pos['top'] }}%; height: {{ $pos['height'] }}%; left: {{ $pos['left'] }}%; width: {{ $pos['width'] }}%;">
                            @include('agenda._evento_chip', ['item' => $item, 'mostrarHora' => true, 'bloco' => true])
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</div>
