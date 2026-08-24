{{-- Um chip do painel de atenção. Mesma marcação usada no primeiro paint
     (Blade) e na hidratação (dashboard.js) — quando as duas divergem, o painel
     "pisca" com layout diferente ao atualizar. --}}
<a class="dashboard-attention-chip tone-{{ $chip['tone'] ?? 'info' }}" href="{{ $chip['url'] }}">
    <span class="dashboard-attention-value">
        <span class="dashboard-attention-dot" aria-hidden="true"></span>
        {{ $chip['value'] }}
    </span>
    <span class="dashboard-attention-label">{{ $chip['label'] }}</span>
    <span class="dashboard-attention-action">
        {{ $chip['action_label'] }} <i class="bi bi-arrow-right-short"></i>
    </span>
</a>
