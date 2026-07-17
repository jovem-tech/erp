@extends('layouts.app')

@php
    $osId = (int) ($order['id'] ?? 0);
    $numeroOs = ($order['numero_os'] ?? '') !== '' ? $order['numero_os'] : '#' . $osId;
    $isEncerrada = (bool) ($order['is_encerrada'] ?? false);
    $statusAtual = (string) ($order['status'] ?? '');
    $proximasEtapas = is_array($order['proximas_etapas'] ?? null) ? $order['proximas_etapas'] : [];
    // $statusNames vem do controller (buildStatusNameMap) — reaproveitado
    // pelo partial orders._map_trail tanto no primeiro carregamento quanto
    // no endpoint JSON orders.map.data.

    $client = is_array($order['cliente'] ?? null) ? $order['cliente'] : [];
    $equipment = is_array($order['equipamento'] ?? null) ? $order['equipamento'] : [];

    $clienteContext = array_filter([
        'Nome' => $client['nome_razao'] ?? ($order['cliente_nome'] ?? ''),
        'Telefone' => $client['telefone1'] ?? '',
    ], fn ($v) => trim((string) $v) !== '');

    $equipamentoContext = array_filter([
        'Tipo' => $equipment['tipo_nome'] ?? ($order['equipamento_tipo_nome'] ?? ''),
        'Marca' => $equipment['marca_nome'] ?? '',
        'Modelo' => $equipment['modelo_nome'] ?? '',
    ], fn ($v) => trim((string) $v) !== '');
    $defeitoRelatado = trim((string) ($order['relato_cliente'] ?? ''));

    // Resumo compacto (nº da OS + equipamento) pra mostrar dentro do quadro
    // do mapa — em tela cheia, tudo que fica FORA de .os-map-frame (cabeçalho
    // da página, painel lateral) some, então esse resumo é a única forma de
    // saber de qual OS/equipamento se trata sem sair do fullscreen.
    $equipamentoResumo = trim((string) ($order['equipamento_resumo_curto'] ?? ''));
    if ($equipamentoResumo === '') {
        $equipamentoResumo = implode(' ', $equipamentoContext);
    }
@endphp

@section('styles')
<style>
    .os-map-page {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 320px;
        gap: 1rem;
        align-items: stretch;
    }

    @media (max-width: 1200px) {
        .os-map-page {
            grid-template-columns: minmax(0, 1fr);
        }
    }

    .os-map-frame {
        position: relative;
        background: var(--desktop-surface);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-lg);
        overflow: hidden;
        /* Sem isso, arrastar pra dar pan é interpretado pelo navegador como
           seleção de texto (os rótulos dentro do SVG e da legenda são texto
           selecionável por padrão), roubando o gesto do pan e abrindo o menu
           de seleção. Precisa estar no quadro inteiro (legenda + viewport +
           toolbar), não só no viewport: se só o viewport for protegido, o
           navegador "pula" a seleção pro texto selecionável mais próximo
           (a legenda, o card fica logo acima) em vez de simplesmente não
           selecionar nada. */
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
    }

    .os-map-viewport {
        position: relative;
        height: calc(100vh - 320px);
        min-height: 480px;
        overflow: hidden;
        cursor: grab;
        touch-action: none;
    }

    .os-map-viewport.is-panning {
        cursor: grabbing;
    }

    .os-map-canvas {
        transform-origin: 0 0;
        will-change: transform;
        width: 1780px;
    }

    .os-map-canvas svg {
        display: block;
        width: 1780px;
        height: 1560px;
    }

    .os-map-toolbar {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        z-index: 5;
        display: flex;
        gap: 0.35rem;
    }

    .os-map-toolbar .btn {
        background: var(--desktop-surface);
        border: 1px solid var(--desktop-border);
        color: var(--desktop-text);
        box-shadow: var(--desktop-shadow-soft);
    }

    /* ---- Tela cheia ----------------------------------------------------- */
    /* X de saída: só aparece em tela cheia, acima da toolbar. */
    .os-map-close {
        display: none;
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        z-index: 10;
        width: 2.6rem;
        height: 2.6rem;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        border: 1px solid var(--desktop-border);
        background: var(--desktop-surface);
        color: var(--desktop-text);
        box-shadow: var(--desktop-shadow);
        font-size: 1.05rem;
    }

    .os-map-frame.is-fullscreen {
        display: flex;
        flex-direction: column;
        border-radius: 0;
        background: var(--desktop-surface);
    }

    /* Fallback sem Fullscreen API: overlay fixo ocupando a janela inteira. */
    .os-map-frame.is-fullscreen-overlay {
        position: fixed;
        inset: 0;
        z-index: 1050;
    }

    .os-map-frame.is-fullscreen .os-map-viewport {
        flex: 1;
        height: auto;
        min-height: 0;
    }

    .os-map-frame.is-fullscreen .os-map-close {
        display: inline-flex;
    }

    /* Em tela cheia a toolbar desce para não disputar o canto com o X. */
    .os-map-frame.is-fullscreen .os-map-toolbar {
        top: 4rem;
    }

    .os-map-legend {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem 1.1rem;
        padding: 0.65rem 1rem;
        border-bottom: 1px solid var(--desktop-border);
        background: var(--desktop-surface-soft);
        font-size: 0.78rem;
        color: var(--desktop-text-soft);
    }

    .os-map-legend-items {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem 1.1rem;
    }

    /* Único jeito de saber de qual OS/equipamento se trata em tela cheia —
       tudo fora de .os-map-frame (cabeçalho da página, painel lateral) some
       nesse modo. */
    .os-map-legend-os {
        display: flex;
        align-items: baseline;
        gap: 0.4rem;
        font-size: 0.82rem;
        white-space: nowrap;
        margin-left: auto;
    }

    .os-map-legend-os strong {
        color: var(--desktop-text);
        font-weight: 800;
    }

    .os-map-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .os-map-legend-swatch {
        display: inline-block;
        width: 22px;
        height: 0;
        border-top: 4px solid #AAB4C0;
        border-radius: 2px;
    }

    .os-map-legend-swatch--traveled { border-top-color: #2B8A3E; }
    .os-map-legend-swatch--suggested { border-top-style: dashed; border-top-color: #1864AB; }
    .os-map-legend-swatch--baixa { border-top-color: #7048E8; }

    .os-map-legend-dot {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 999px;
    }

    .os-map-legend-dot--current { background: #1864AB; box-shadow: 0 0 0 3px rgba(24, 100, 171, 0.25); }
    .os-map-legend-dot--clickable { background: #fff; border: 2px solid #1864AB; }

    /* A pílula de status do cabeçalho não fica dentro de .os-map-frame, mas
       ainda é "vizinha" o bastante pro navegador pular a seleção pra ela
       quando o arrasto do pan começa numa área não-selecionável (o quadro
       do mapa). Protegida à parte; o número da OS e o painel de trajeto
       continuam selecionáveis normalmente. */
    #osMapStatusPill {
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
    }

    .os-map-side {
        background: var(--desktop-surface);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-lg);
        padding: 1.1rem;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .os-map-side-title {
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--desktop-text-muted);
        margin-bottom: 0.75rem;
    }

    /* Cliente + equipamento: contexto rápido de quem/o que é essa OS, sem
       precisar voltar pra tela de detalhe. Fica no painel lateral (não no
       viewport) pra não mexer no cálculo de altura do mapa. */
    .os-map-context {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--desktop-border);
    }

    .os-map-context-card {
        background: var(--desktop-surface-soft);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-sm);
        padding: 0.65rem 0.75rem;
    }

    .os-map-context-title {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--desktop-text-muted);
        margin-bottom: 0.4rem;
    }

    .os-map-context-row {
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
        font-size: 0.8rem;
        padding: 0.1rem 0;
    }

    .os-map-context-row span {
        color: var(--desktop-text-muted);
        flex-shrink: 0;
    }

    .os-map-context-row strong {
        text-align: right;
        font-weight: 600;
        color: var(--desktop-text);
    }

    .os-map-context-defeito {
        margin-top: 0.35rem;
        padding-top: 0.35rem;
        border-top: 1px dashed var(--desktop-border);
        font-size: 0.78rem;
        color: var(--desktop-text-soft);
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .os-map-context-defeito strong {
        display: block;
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--desktop-text-muted);
        margin-bottom: 0.2rem;
    }

    .os-map-trail {
        overflow-y: auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
    }

    .os-map-trail-item {
        display: flex;
        gap: 0.6rem;
        font-size: 0.8rem;
        padding: 0.55rem 0.65rem;
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-sm);
        background: var(--desktop-surface-soft);
    }

    .os-map-trail-step {
        flex-shrink: 0;
        width: 1.55rem;
        height: 1.55rem;
        border-radius: 999px;
        background: var(--desktop-primary-soft);
        color: var(--desktop-primary);
        font-weight: 800;
        font-size: 0.72rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .os-map-trail-item.is-latest .os-map-trail-step {
        background: var(--desktop-primary);
        color: #fff;
    }

    .os-map-trail-label {
        font-weight: 700;
        color: var(--desktop-text);
    }

    .os-map-trail-meta {
        font-size: 0.72rem;
        color: var(--desktop-text-muted);
        margin-top: 0.1rem;
    }

    /* ---- Decoração do SVG (aplicada pelo orders-map.js) ---------------- */
    .os-map--decorated .os-map-edge {
        opacity: 0.18;
    }

    .os-map--decorated .os-map-node,
    .os-map--decorated .os-map-port {
        opacity: 0.45;
    }

    .os-map--decorated .os-map-node.is-visited,
    .os-map--decorated .os-map-node.is-current,
    .os-map--decorated .os-map-node.is-clickable,
    .os-map--decorated .os-map-node.is-destination,
    .os-map--decorated .os-map-port.is-suggested {
        opacity: 1;
    }

    .os-map--decorated .os-map-edge.is-traveled {
        opacity: 1;
        stroke: #2B8A3E;
    }

    .os-map--decorated .os-map-edge.is-suggested {
        opacity: 1;
        stroke-dasharray: 10 7;
        animation: os-map-dash 1.4s linear infinite;
    }

    @keyframes os-map-dash {
        to { stroke-dashoffset: -34; }
    }

    .os-map-node.is-clickable {
        cursor: pointer;
    }

    .os-map-node.is-clickable rect {
        stroke: var(--desktop-primary);
        stroke-width: 3;
    }

    .os-map-node.is-clickable:hover rect {
        filter: brightness(1.05);
        stroke-width: 4;
    }

    .os-map-port.is-actionable {
        cursor: pointer;
    }

    .os-map-here {
        fill: #1864AB;
        stroke: #FFFFFF;
        stroke-width: 2.5;
        transform-box: fill-box;
        transform-origin: center;
        animation: os-map-pulse 1.6s ease-out infinite;
    }

    @keyframes os-map-pulse {
        0% { transform: scale(0.85); opacity: 1; }
        70% { transform: scale(1.5); opacity: 0.45; }
        100% { transform: scale(0.85); opacity: 1; }
    }
</style>
@endsection

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
        <div>
            <p class="desktop-eyebrow">Mapa da ordem de serviço</p>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h2 class="surface-title fs-3 mb-0">{{ $numeroOs }}</h2>
                <span id="osMapStatusPill">
                    @include('layouts.partials.status-pill', [
                        'label' => ($order['status_nome'] ?? '') !== '' ? $order['status_nome'] : 'Sem status',
                        'color' => $order['status_cor'] ?? '#64748b',
                    ])
                </span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <a href="{{ route('orders.show', $osId) }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-1"></i>Voltar para a OS
            </a>
        </div>
    </div>

    <div id="osMapBanner">
        @if ($isEncerrada)
            <div class="alert alert-info d-flex align-items-center gap-2">
                <i class="bi bi-lock"></i>
                <div>OS encerrada — o mapa é somente leitura. Para reabrir, use "Cancelar baixa" na tela da OS.</div>
            </div>
        @elseif ($statusAtual === 'cancelado')
            <div class="alert alert-warning d-flex align-items-center gap-2">
                <i class="bi bi-info-circle"></i>
                <div>OS cancelada — a única continuação possível é a reabertura (voltar para Triagem).</div>
            </div>
        @endif
    </div>

    <div class="os-map-page">
        <div class="os-map-frame">
            <div class="os-map-legend">
                <div class="os-map-legend-items">
                    <span class="os-map-legend-item"><span class="os-map-legend-swatch os-map-legend-swatch--traveled"></span>trajeto percorrido</span>
                    <span class="os-map-legend-item"><span class="os-map-legend-dot os-map-legend-dot--current"></span>posição atual</span>
                    <span class="os-map-legend-item"><span class="os-map-legend-swatch os-map-legend-swatch--suggested"></span>rota provável</span>
                    <span class="os-map-legend-item"><span class="os-map-legend-dot os-map-legend-dot--clickable"></span>próximas etapas (clique para mover)</span>
                    <span class="os-map-legend-item"><span class="os-map-legend-swatch os-map-legend-swatch--baixa"></span>baixa da OS (encerramento)</span>
                </div>
                <div class="os-map-legend-os" id="osMapLegendOs">
                    <strong>{{ $numeroOs }}</strong>
                    @if ($equipamentoResumo !== '')
                        <span><i class="bi bi-laptop me-1"></i>{{ $equipamentoResumo }}</span>
                    @endif
                </div>
            </div>
            <div class="os-map-viewport" id="osMapViewport">
                <div class="os-map-canvas" id="osMapCanvas">
                    @include('orders._flow_map_svg')
                </div>
                <div class="os-map-toolbar">
                    <button type="button" class="btn btn-sm" id="osMapZoomOut" title="Reduzir"><i class="bi bi-dash-lg"></i></button>
                    <button type="button" class="btn btn-sm" id="osMapZoomIn" title="Ampliar"><i class="bi bi-plus-lg"></i></button>
                    <button type="button" class="btn btn-sm" id="osMapZoomReset" title="Ajustar à tela"><i class="bi bi-aspect-ratio"></i></button>
                    <button type="button" class="btn btn-sm" id="osMapCenterCurrent" title="Centralizar na posição atual"><i class="bi bi-crosshair"></i></button>
                    <button type="button" class="btn btn-sm" id="osMapFullscreen" title="Tela cheia"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <button type="button" class="os-map-close" id="osMapExitFullscreen" title="Sair da tela cheia (Esc)" aria-label="Sair da tela cheia">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>

        <aside class="os-map-side">
            @if ($clienteContext !== [] || $equipamentoContext !== [] || $defeitoRelatado !== '')
                <div class="os-map-context">
                    @if ($clienteContext !== [])
                        <div class="os-map-context-card">
                            <div class="os-map-context-title"><i class="bi bi-person"></i>Cliente</div>
                            @foreach ($clienteContext as $label => $value)
                                <div class="os-map-context-row"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
                            @endforeach
                        </div>
                    @endif

                    @if ($equipamentoContext !== [] || $defeitoRelatado !== '')
                        <div class="os-map-context-card">
                            <div class="os-map-context-title"><i class="bi bi-laptop"></i>Equipamento</div>
                            @foreach ($equipamentoContext as $label => $value)
                                <div class="os-map-context-row"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
                            @endforeach
                            @if ($defeitoRelatado !== '')
                                <div class="os-map-context-defeito" title="{{ $defeitoRelatado }}">
                                    <strong>Defeito relatado</strong>
                                    {{ $defeitoRelatado }}
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            <div class="os-map-side-title">Trajeto percorrido</div>
            <div id="osMapTrailContainer">
                @include('orders._map_trail', ['path' => $path, 'pathTruncated' => $pathTruncated, 'statusNames' => $statusNames])
            </div>
        </aside>
    </div>
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_OS_MAP = {!! \Illuminate\Support\Js::from([
            'orderId' => $osId,
            'numeroOs' => $numeroOs,
            'statusAtual' => $statusAtual,
            'isEncerrada' => $isEncerrada,
            'canEditStatus' => (bool) $canEditStatus,
            'canClose' => (bool) $canEditStatus && ! $isEncerrada,
            'statusCongelaPrazo' => (bool) ($order['status_congela_prazo'] ?? false),
            'path' => $path,
            'proximasEtapas' => $proximasEtapas,
            'statusUpdateUrl' => route('orders.status.update', $osId),
            'closureUrl' => route('orders.closure.show', $osId),
            'mapDataUrl' => route('orders.map.data', $osId),
            'csrfToken' => csrf_token(),
        ]) !!};
    </script>
    <script src="{{ asset('assets/js/orders-map.js') }}?v={{ filemtime(public_path('assets/js/orders-map.js')) }}"></script>
@endsection
