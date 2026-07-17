{{--
    Painel "Trajeto percorrido" do Mapa da OS. Extraído para partial porque é
    renderizado dos dois jeitos: dentro da página completa (orders.map) e
    isolado, via JSON, pelo endpoint orders.map.data (chamado pelo
    orders-map.js depois de mover o status, sem recarregar a página).
    Espera $path, $pathTruncated, $statusNames (array código => nome).
--}}
@php
    $statusLabel = static fn (string $code): string => $statusNames[$code] ?? ($code !== '' ? $code : '—');
@endphp

@if ($pathTruncated)
    <div class="alert alert-warning py-2 px-3 small">
        Trajeto muito extenso — exibindo apenas as 500 primeiras movimentações.
    </div>
@endif
<div class="os-map-trail">
    @forelse ($path as $index => $hop)
        <div class="os-map-trail-item {{ $loop->last ? 'is-latest' : '' }}">
            <span class="os-map-trail-step">{{ $index + 1 }}</span>
            <div>
                <div class="os-map-trail-label">
                    @if (($hop['de'] ?? '') !== '')
                        {{ $statusLabel($hop['de']) }} <i class="bi bi-arrow-right small"></i>
                    @endif
                    {{ $statusLabel($hop['para'] ?? '') }}
                </div>
                <div class="os-map-trail-meta">
                    {{ ($hop['em'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($hop['em'])->format('d/m/Y H:i') : '' }}
                    {{ ($hop['por'] ?? '') !== '' ? '· por ' . $hop['por'] : '' }}
                </div>
            </div>
        </div>
    @empty
        <p class="text-muted small mb-0">Nenhuma movimentação de status registrada ainda.</p>
    @endforelse
</div>
