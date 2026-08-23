@php
    // Agrupa por data para a lista não virar uma parede de linhas iguais.
    $porData = [];
    foreach ($compromissos as $item) {
        $porData[(string) ($item['data'] ?? '')][] = $item;
    }
    ksort($porData);

    $diasSemana = ['Mon' => 'Segunda', 'Tue' => 'Terça', 'Wed' => 'Quarta', 'Thu' => 'Quinta',
                   'Fri' => 'Sexta', 'Sat' => 'Sábado', 'Sun' => 'Domingo'];
@endphp

<div class="agenda-list mt-3">
    @forelse ($porData as $data => $itens)
        @php
            $carbon = $data !== '' ? \Carbon\CarbonImmutable::parse($data) : null;
            $isToday = $carbon?->isToday() ?? false;
        @endphp
        <div class="agenda-list-group {{ $isToday ? 'is-today' : '' }}">
            <div class="agenda-list-date">
                <strong>{{ $carbon?->format('d/m') }}</strong>
                <span>{{ $carbon ? ($diasSemana[$carbon->format('D')] ?? '') : '' }}</span>
                @if ($isToday)
                    <span class="badge rounded-pill text-bg-primary">Hoje</span>
                @endif
            </div>

            <div class="agenda-list-items">
                @foreach ($itens as $item)
                    @php
                        $rowClasses = ['agenda-list-item', 'agenda-tipo-'.($item['tipo'] ?? 'manual')];
                        if (($item['status'] ?? '') === 'concluido') { $rowClasses[] = 'is-done'; }
                        if ($item['atrasado'] ?? false) { $rowClasses[] = 'is-late'; }
                    @endphp
                    <div class="{{ implode(' ', $rowClasses) }}">
                        <span class="agenda-list-time">{{ $item['hora'] ?? '' ?: 'Dia inteiro' }}</span>

                        <button type="button" class="agenda-list-main" data-agenda-open
                                data-agenda-item="{{ json_encode($item, JSON_UNESCAPED_UNICODE) }}">
                            <span class="agenda-list-title">{{ $item['titulo'] ?? '' }}</span>
                            @if (! empty($item['descricao']))
                                <span class="agenda-list-desc">{{ \Illuminate\Support\Str::limit($item['descricao'], 110) }}</span>
                            @endif
                        </button>

                        <span class="agenda-list-actions">
                            @if (($item['status'] ?? '') === 'pendente')
                                <form method="POST" action="{{ route('agenda.complete', $item['id']) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="data" value="{{ $cursor }}">
                                    <input type="hidden" name="view" value="{{ $viewMode }}">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Concluir">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('agenda.reopen', $item['id']) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="data" value="{{ $cursor }}">
                                    <input type="hidden" name="view" value="{{ $viewMode }}">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Reabrir">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </form>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="text-center py-5">
            <i class="bi bi-calendar-check fs-1 text-muted d-block mb-3"></i>
            <p class="surface-subtitle mb-0">Nenhum compromisso neste período.</p>
        </div>
    @endforelse
</div>
