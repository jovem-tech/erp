{{--
    Desenho do Mapa da OS, gerado do CATALOGO VIVO de status.

    Ate 09/09/2026 este arquivo era um SVG estatico produzido por
    `scripts/python/diagrama_fluxo_os_organizado.py --embed`, com raias, cards e
    setas em coordenadas escritas a mao. Criar, renomear, reordenar ou desativar
    um status na tela "Status de OS" nao mudava nada aqui, e regenerar exigia um
    dev roteando coordenadas no Python. Agora a geometria vem de
    App\Support\OrderFlowMapLayout::build($statusCatalog).

    As SETAS nao vivem mais no SVG: sao desenhadas em runtime por orders-map.js
    dentro de [data-os-map-layer]. Foi isso que consertou a cronologia — antes a
    seta precisava PREEXISTIR no desenho, entao um salto real fora do catalogo
    de transicoes (ex.: aguardando_reparo -> reparo_concluido) nao aparecia.

    Espera: $layout (saida de OrderFlowMapLayout::build) — opcional; sem ele,
    resolve sozinho via OrderFlowMapLayoutFactory.
--}}
@php
    // A pagina cheia do mapa passa $layout pronto (ja tem o catalogo no payload
    // da OS). O modal "Alterar status" e incluido por cinco telas que nem
    // sempre tem catalogo na view, entao cai no factory memoizado.
    $layout = $layout ?? app(\App\Support\OrderFlowMapLayoutFactory::class)->current();
    $lineHeight = \App\Support\OrderFlowMapLayout::lineHeight();

    // Cores distintas em uso pelos cards. O trajeto percorrido e a proxima
    // etapa sugerida sao desenhados na cor da etapa de DESTINO, entao cada cor
    // precisa da sua propria ponta de seta — marker nao herda o stroke do path
    // (fill="context-stroke" e' SVG2 e nem todo navegador suporta).
    // `finalizado_sem_reparo` e `cancelado` compartilham #CC0000, por isso o
    // array_unique: uma cor, um marker.
    $arrowColors = array_values(array_unique(array_map(
        static fn (array $card): string => $card['color'],
        $layout['cards']
    )));
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {{ $layout['width'] }} {{ $layout['height'] }}" class="os-map-svg">
    <defs>
        <filter id="osMapShadow" x="-20%" y="-20%" width="140%" height="140%">
            <feDropShadow dx="0" dy="3" stdDeviation="3" flood-opacity="0.16"/>
        </filter>
        {{-- Um marker por camada de aresta: as setas sao criadas pelo JS e so
             referenciam estes ids, entao a paleta fica num lugar so. --}}
        <marker id="osMapArrowTraveled" markerUnits="userSpaceOnUse" markerWidth="16" markerHeight="12" refX="15" refY="6" orient="auto">
            <path d="M 0 0 L 16 6 L 0 12 z" fill="#2B8A3E"/>
        </marker>
        <marker id="osMapArrowRoute" markerUnits="userSpaceOnUse" markerWidth="14" markerHeight="11" refX="13" refY="5.5" orient="auto">
            <path d="M 0 0 L 14 5.5 L 0 11 z" fill="#1864AB"/>
        </marker>
        <marker id="osMapArrowNext" markerUnits="userSpaceOnUse" markerWidth="13" markerHeight="10" refX="12" refY="5" orient="auto">
            <path d="M 0 0 L 13 5 L 0 10 z" fill="#F08C00"/>
        </marker>
        <marker id="osMapArrowBaixa" markerUnits="userSpaceOnUse" markerWidth="16" markerHeight="12" refX="15" refY="6" orient="auto">
            <path d="M 0 0 L 16 6 L 0 12 z" fill="#7048E8"/>
        </marker>
        <marker id="osMapArrowCatalog" markerUnits="userSpaceOnUse" markerWidth="11" markerHeight="8" refX="10" refY="4" orient="auto">
            <path d="M 0 0 L 11 4 L 0 8 z" fill="#AAB4C0"/>
        </marker>

        {{-- Pontas coloridas por fase, em dois calibres: o do trajeto (igual
             ao osMapArrowTraveled) e o da sugestao (igual ao osMapArrowNext).
             Os cinco markers acima continuam servindo de fallback para quem
             nao tem cor de destino — a porta da baixa, por exemplo. --}}
        @foreach ($arrowColors as $arrowColor)
            @php $arrowId = ltrim($arrowColor, '#'); @endphp
            <marker id="osMapArrowFase-{{ $arrowId }}" markerUnits="userSpaceOnUse" markerWidth="16" markerHeight="12" refX="15" refY="6" orient="auto">
                <path d="M 0 0 L 16 6 L 0 12 z" fill="{{ $arrowColor }}"/>
            </marker>
            <marker id="osMapArrowFaseNext-{{ $arrowId }}" markerUnits="userSpaceOnUse" markerWidth="13" markerHeight="10" refX="12" refY="5" orient="auto">
                <path d="M 0 0 L 13 5 L 0 10 z" fill="{{ $arrowColor }}"/>
            </marker>
        @endforeach
    </defs>

    <rect width="{{ $layout['width'] }}" height="{{ $layout['height'] }}" fill="#FFFFFF"/>

    {{-- Raias: uma por macrofase presente no catalogo ativo, na ordem oficial
         de OrderStatusMacroGroups::orderIndex(). --}}
    @foreach ($layout['lanes'] as $lane)
        <g class="os-map-lane" data-lane="{{ $lane['grupo'] }}" data-band="{{ $lane['band'] }}">
            <rect x="{{ $lane['x'] }}" y="{{ $lane['y'] }}" width="{{ $lane['w'] }}" height="{{ $lane['h'] }}"
                  rx="16" fill="{{ $lane['color'] }}" fill-opacity="0.10"
                  stroke="{{ $lane['color'] }}" stroke-opacity="0.55" stroke-width="1.5"/>
            <rect x="{{ $lane['x'] }}" y="{{ $lane['y'] }}" width="{{ $lane['w'] }}" height="26"
                  rx="13" fill="{{ $lane['color'] }}"/>
            <text x="{{ $lane['x'] + $lane['w'] / 2 }}" y="{{ $lane['y'] + 18 }}" text-anchor="middle"
                  font-size="12" font-weight="800" fill="{{ $lane['text'] }}"
                  font-family="Segoe UI, Arial, sans-serif" letter-spacing="0.4">
                {{ mb_strtoupper($lane['titulo']) }}
            </text>
        </g>
    @endforeach

    {{-- Camada das arestas: fica ANTES dos cards para as setas passarem por
         baixo deles. Preenchida por orders-map.js a cada redecorate(). --}}
    <g data-os-map-layer="edges"></g>

    {{-- Porta unica de encerramento. Os status de grupo_macro='encerrado' so
         entram por aqui (OrderClosureService::close()) — regra do skill
         sistema-erp-os-fluxo-fechamento, inalterada. --}}
    @if ($layout['port'])
        <g class="os-map-port" data-port="baixa">
            <rect x="{{ $layout['port']['x'] }}" y="{{ $layout['port']['y'] }}"
                  width="{{ $layout['port']['w'] }}" height="{{ $layout['port']['h'] }}"
                  rx="12" fill="#F3F0FF" stroke="#7048E8" stroke-width="2" filter="url(#osMapShadow)"/>
            <text x="{{ $layout['port']['x'] + $layout['port']['w'] / 2 }}" y="{{ $layout['port']['y'] + 21 }}"
                  text-anchor="middle" font-size="14" font-weight="800" fill="#7048E8"
                  font-family="Segoe UI, Arial, sans-serif">BAIXA DA OS</text>
            <text x="{{ $layout['port']['x'] + $layout['port']['w'] / 2 }}" y="{{ $layout['port']['y'] + 38 }}"
                  text-anchor="middle" font-size="10" font-weight="600" fill="#495057"
                  font-family="Segoe UI, Arial, sans-serif">porta única de encerramento</text>
        </g>
        {{-- Acima da porta, nao ao lado: ao lado a legenda ficava no caminho
             da propria seta roxa que chega na porta. --}}
        <text x="{{ $layout['port']['x'] + $layout['port']['w'] / 2 }}" y="{{ $layout['port']['y'] - 10 }}"
              text-anchor="middle" font-size="11" font-weight="600" fill="#7048E8" font-style="italic"
              font-family="Segoe UI, Arial, sans-serif">baixa a partir de qualquer etapa aberta</text>
    @endif

    {{-- Cards: um por status ATIVO. Rotulo = os_status.nome, quebrado em
         linhas por OrderFlowMapLayout::wrapLabel(). --}}
    @foreach ($layout['cards'] as $codigo => $card)
        @php
            $textTop = $card['y'] + ($card['h'] - (count($card['lines']) - 1) * $lineHeight) / 2 + 5;
        @endphp
        <g class="os-map-node" data-status="{{ $codigo }}" data-kind="{{ $card['kind'] }}"
           data-grupo="{{ $card['grupo'] }}" data-cor="{{ $card['color'] }}">
            <title>{{ $card['nome_completo'] }}</title>
            <rect x="{{ $card['x'] }}" y="{{ $card['y'] }}" width="{{ $card['w'] }}" height="{{ $card['h'] }}"
                  rx="14" fill="{{ $card['color'] }}" stroke="{{ $card['color'] }}" stroke-width="2"
                  filter="url(#osMapShadow)"/>
            @foreach ($card['lines'] as $i => $line)
                <text x="{{ $card['x'] + $card['w'] / 2 }}" y="{{ $textTop + $i * $lineHeight }}"
                      text-anchor="middle" font-size="13" font-weight="700" fill="{{ $card['text'] }}"
                      font-family="Segoe UI, Arial, sans-serif">{{ $line }}</text>
            @endforeach
            @if ($card['pausa'])
                <circle cx="{{ $card['x'] + $card['w'] - 12 }}" cy="{{ $card['y'] + 12 }}" r="5"
                        fill="{{ $card['text'] }}" fill-opacity="0.75"/>
            @endif
        </g>
    @endforeach
</svg>
