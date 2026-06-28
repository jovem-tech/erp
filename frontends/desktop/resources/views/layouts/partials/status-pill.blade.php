@php
    $statusColor = $color ?: '#64748b';
@endphp

<span class="status-pill {{ !empty($small) ? 'status-pill-sm' : '' }}" style="--status-color: {{ $statusColor }}">
    @if (!empty($icon))
        <i class="bi {{ $icon }}"></i>
    @endif
    <span>{{ $label ?: 'Sem status' }}</span>
</span>
