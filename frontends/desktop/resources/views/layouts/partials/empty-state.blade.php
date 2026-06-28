<div class="empty-state-shell">
    <i class="bi {{ $icon ?? 'bi-inbox' }}"></i>
    <h3>{{ $title ?? 'Nada para mostrar agora' }}</h3>
    <p>{{ $slot ?? ($message ?? 'Assim que novos dados chegarem, eles aparecerão aqui.') }}</p>
</div>
