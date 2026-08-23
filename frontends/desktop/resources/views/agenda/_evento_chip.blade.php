{{-- Chip de um compromisso. Único ponto onde as classes de estado são
     decididas, para que calendário, grade horária e ano combinem sempre. --}}
@php
    $classes = ['agenda-event', 'agenda-tipo-'.($item['tipo'] ?? 'manual')];
    if (($item['status'] ?? '') === 'concluido') { $classes[] = 'is-done'; }
    if ($item['atrasado'] ?? false) { $classes[] = 'is-late'; }
    if (($item['prioridade'] ?? '') === 'alta') { $classes[] = 'is-high'; }
    if ($bloco ?? false) {
        $classes[] = 'is-block';
        // Bloco curto deita hora e título na mesma linha; empilhados, o título
        // ficaria sob o overflow e o compromisso viraria só um horário.
        if ($item['position']['compact'] ?? false) { $classes[] = 'is-compact'; }
    }
@endphp
<button type="button"
        class="{{ implode(' ', $classes) }}"
        data-agenda-open
        data-agenda-item="{{ json_encode($item, JSON_UNESCAPED_UNICODE) }}"
        title="{{ ($item['hora'] ?? '') ? $item['hora'].' — ' : '' }}{{ $item['titulo'] ?? '' }}">
    @if (($mostrarHora ?? false) && ! empty($item['hora']))
        <span class="agenda-event-time">{{ $item['hora'] }}</span>
    @endif
    <span class="agenda-event-title">{{ $item['titulo'] ?? '' }}</span>
</button>
