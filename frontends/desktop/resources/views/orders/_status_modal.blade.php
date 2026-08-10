{{--
    Modal de alteração de status da OS.
    Incluir em qualquer view que precise desta funcionalidade.
    Requer orders-status-modal.js e window.__DESKTOP_STATUS_MODAL configurado.
--}}
<style>
    .os-status-context-card {
        background: var(--desktop-surface-soft);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-md);
        padding: 1rem;
        height: 100%;
    }

    .os-status-context-title {
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--desktop-text-muted);
        margin-bottom: 0.5rem;
    }

    .os-status-context-name {
        font-weight: 700;
        color: var(--desktop-text);
        font-size: 0.95rem;
        margin-bottom: 0.25rem;
    }

    .os-status-context-meta {
        font-size: 0.82rem;
        color: var(--desktop-text-soft);
        margin-top: 0.15rem;
    }

    .os-status-modal-panel {
        background: var(--desktop-surface-soft);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-md);
        padding: 1.25rem;
        height: 100%;
    }

    .os-status-modal-section {
        padding-bottom: 1rem;
        margin-bottom: 1rem;
        border-bottom: 1px solid var(--desktop-border);
    }

    .os-status-modal-section:last-child {
        padding-bottom: 0;
        margin-bottom: 0;
        border-bottom: none;
    }

    .os-status-modal-section-title {
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--desktop-text-muted);
        margin-bottom: 0.75rem;
    }

    .os-status-chip-groups {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    /* ---- Fluxograma de status por macrofase -----------------------------
       Layout e cores seguem o fluxograma definido pelo usuário (2026-08-10):
       faixa da macrofase à esquerda + etapas daquela fase fluindo para a
       direita com setas. A ordem das fases é declarada em MACRO_PHASES
       (orders-status-modal.js), não em os_status.ordem_fluxo. */
    .os-flow {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .os-flow-row {
        display: flex;
        align-items: stretch;
        gap: 0.75rem;
        min-width: 0;
    }

    /* Faixa da macrofase: retângulo com a "seta" para baixo do fluxograma,
       indicando a continuidade para a fase seguinte. */
    .os-flow-phase {
        flex: 0 0 108px;
        display: flex;
        align-items: center;
        padding: 0.55rem 0.6rem calc(0.55rem + 8px);
        clip-path: polygon(0 0, 100% 0, 100% calc(100% - 8px), 50% 100%, 0 calc(100% - 8px));
        background: var(--phase-color, var(--desktop-text-soft));
        color: var(--phase-text, #fff);
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        line-height: 1.15;
    }

    /* align-items: stretch deixa todos os cards da mesma linha com a mesma
       altura, mesmo quando um deles quebra em duas linhas (igual ao
       fluxograma de referência). */
    .os-flow-steps {
        flex: 1 1 auto;
        min-width: 0;
        display: flex;
        align-items: stretch;
        gap: 0;
        overflow-x: auto;
        padding-bottom: 0.15rem;
    }

    .os-flow-arrow {
        flex: 0 0 18px;
        align-self: center;
        height: 2px;
        background: var(--desktop-border-strong, #94a3b8);
        position: relative;
    }

    .os-flow-arrow::after {
        content: '';
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        border-left: 6px solid var(--desktop-border-strong, #94a3b8);
        border-top: 4px solid transparent;
        border-bottom: 4px solid transparent;
    }

    .os-flow-step {
        flex: 0 0 auto;
        width: 150px;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.45rem 0.6rem;
        border: 2px solid transparent;
        border-radius: var(--desktop-radius-sm);
        background: var(--phase-color, var(--desktop-surface));
        color: var(--phase-text, #fff);
        font-size: 0.74rem;
        font-weight: 700;
        line-height: 1.25;
        text-align: left;
        cursor: pointer;
        transition: var(--desktop-transition);
        /* Leve esmaecimento no estado "não é a etapa atual" — suficiente para
           a atual/sugerida saltarem, sem prejudicar a leitura do rótulo (a
           0.55 o texto sobre o amarelo ficava lavado). */
        opacity: 0.82;
    }

    .os-flow-step:hover {
        opacity: 1;
        transform: translateY(-1px);
    }

    .os-flow-step:focus-visible {
        outline: 2px solid var(--desktop-primary);
        outline-offset: 2px;
        opacity: 1;
    }

    .os-flow-step i {
        font-size: 0.85rem;
        flex-shrink: 0;
    }

    /* Rótulo quebra em até duas linhas em vez de truncar — nomes longos como
       "Entregue - Pendência Financeira" precisam aparecer por inteiro. */
    .os-flow-step-label {
        flex: 1 1 auto;
        min-width: 0;
        overflow-wrap: anywhere;
    }

    /* Etapa atual da OS: opaca e com anel escuro. */
    .os-flow-step.is-current {
        opacity: 1;
        border-color: rgba(15, 23, 42, 0.55);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.2);
    }

    .os-flow-step-dot {
        font-size: 0.5rem !important;
    }

    /* Próxima etapa sugerida pelo catálogo de transições — realce sutil, não
       restringe a escolha (qualquer etapa não-baixa é clicável). */
    .os-flow-step.is-suggested {
        opacity: 1;
        border-color: rgba(255, 255, 255, 0.85);
    }

    /* Etapa escolhida (ainda não salva): anel roxo do sistema. */
    .os-flow-step.is-selected {
        opacity: 1;
        border-color: var(--desktop-primary);
        box-shadow: 0 0 0 3px var(--desktop-primary-soft);
    }

    /* Cores por macrofase — paleta definida pelo usuário no fluxograma. */
    .os-flow-row[data-phase="recepcao"] { --phase-color: #10739E; --phase-text: #fff; }
    .os-flow-row[data-phase="diagnostico"] { --phase-color: #F2931E; --phase-text: #fff; }
    .os-flow-row[data-phase="orcamento"] { --phase-color: #66B2FF; --phase-text: #fff; }
    .os-flow-row[data-phase="interrupcao"] { --phase-color: #FFD400; --phase-text: #3d3000; }
    .os-flow-row[data-phase="execucao"] { --phase-color: #999900; --phase-text: #fff; }
    .os-flow-row[data-phase="qualidade"] { --phase-color: #9999FF; --phase-text: #fff; }
    .os-flow-row[data-phase="concluido"] { --phase-color: #00994D; --phase-text: #fff; }
    .os-flow-row[data-phase="finalizado_sem_reparo"] { --phase-color: #CC0000; --phase-text: #fff; }
    .os-flow-row[data-phase="cancelado"] { --phase-color: #CC0000; --phase-text: #fff; }

    /* "Em espera" é amarelo com texto escuro; o anel de etapa atual/sugerida
       precisa contrastar com o fundo claro. */
    .os-flow-row[data-phase="interrupcao"] .os-flow-step.is-suggested {
        border-color: rgba(61, 48, 0, 0.6);
    }

    /* Saídas do fluxo (sem reparo / cancelado): bloco à parte, separado das
       fases de progresso por um divisor. */
    .os-flow-exit {
        margin-top: 0.35rem;
        padding-top: 0.85rem;
        border-top: 1px dashed var(--desktop-border);
    }

    .os-flow-exit-title {
        font-size: 0.66rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--desktop-text-muted);
        margin-bottom: 0.5rem;
    }

    .os-status-chip-empty {
        font-size: 0.82rem;
        color: var(--desktop-text-muted);
        margin-bottom: 0;
    }

    .os-status-modal-workflow {
        display: flex;
        flex-direction: column;
    }

    .os-status-modal-history-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        max-height: 340px;
        overflow-y: auto;
    }

    .os-status-history-item {
        padding: 0.75rem;
        background: var(--desktop-surface);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-sm);
        font-size: 0.82rem;
    }

    .os-status-history-item-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.5rem;
        margin-bottom: 0.35rem;
        flex-wrap: wrap;
    }

    .os-status-history-item-badge {
        font-size: 0.72rem;
        font-weight: 600;
        padding: 0.15rem 0.5rem;
        border-radius: 999px;
        background: var(--desktop-primary-soft);
        color: var(--desktop-primary);
        white-space: nowrap;
    }

    .os-status-history-item-date {
        font-size: 0.72rem;
        color: var(--desktop-text-muted);
        flex-shrink: 0;
    }

    .os-status-history-item-obs {
        color: var(--desktop-text-soft);
        margin-top: 0.25rem;
        line-height: 1.45;
    }

    .os-status-history-item-author {
        font-size: 0.72rem;
        color: var(--desktop-text-muted);
        margin-top: 0.25rem;
    }

    .os-status-modal-footer-notify {
        margin-right: auto;
    }

    /* ---- Aba "Mapa de status" -------------------------------------------
       Reaproveita literalmente o mesmo mapa interativo de orders/map.blade.php
       (mesmo SVG estático, mesmo orders-map.js via window.DesktopOsMap.create)
       — classes e nomes de variáveis de decoração idênticos de propósito, só
       o dimensionamento do viewport muda (aqui é uma aba de modal em tela
       cheia, lá é a página inteira). Ver skill sistema-erp-os-fluxo-fechamento
       pra por que os nós de encerramento nunca são clicáveis aqui. */
    .os-map-frame {
        position: relative;
        background: var(--desktop-surface);
        border: 1px solid var(--desktop-border);
        border-radius: var(--desktop-radius-lg);
        overflow: hidden;
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
    }

    /* Altura descontando o "cromo" do modal em tela cheia (cabeçalho, cards de
       contexto, abas, dica, legenda, texto de ajuda e rodapé) — assim o mapa
       cabe sem forçar rolagem do corpo do modal, que competiria com o pan. */
    .os-map-viewport {
        position: relative;
        height: calc(100vh - 430px);
        min-height: 360px;
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

    .os-map-frame.is-fullscreen-overlay {
        position: fixed;
        inset: 0;
        z-index: 1090;
    }

    .os-map-frame.is-fullscreen .os-map-viewport {
        flex: 1;
        height: auto;
        min-height: 0;
    }

    .os-map-frame.is-fullscreen .os-map-close {
        display: inline-flex;
    }

    .os-map-frame.is-fullscreen .os-map-toolbar {
        top: 4rem;
    }

    .os-map-legend {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
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

    /* ---- Decoração do SVG (aplicada por orders-map.js) ------------------ */
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

<div class="modal fade" id="orderStatusModal" tabindex="-1" aria-hidden="true" aria-labelledby="orderStatusModalTitleEl">
    <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content modal-shell">
            <form id="orderStatusModalForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="orderStatusModalTitleEl">
                        Alterar status da OS
                        <span id="orderStatusModalNumero" class="text-primary fw-bold ms-1">-</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    {{-- Estado de carregamento --}}
                    <div id="orderStatusModalLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted small mt-3 mb-0">Carregando dados da OS...</p>
                    </div>

                    {{-- Estado de erro --}}
                    <div id="orderStatusModalError" class="d-none">
                        <div class="alert alert-danger mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <span id="orderStatusModalErrorText">Não foi possível carregar os dados da OS.</span>
                        </div>
                    </div>

                    {{-- Conteúdo principal --}}
                    <div id="orderStatusModalContent" class="d-none">
                        {{-- Cards de contexto: cliente + equipamento --}}
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-lg-6">
                                <div class="os-status-context-card">
                                    <div class="os-status-context-title">Cliente</div>
                                    <div class="os-status-context-name" id="orderStatusModalClientName">-</div>
                                    <div class="os-status-context-meta" id="orderStatusModalClientPhone">Telefone: -</div>
                                    <div class="os-status-context-meta" id="orderStatusModalClientEmail">E-mail: -</div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <div class="os-status-context-card">
                                    <div class="os-status-context-title">Equipamento</div>
                                    <div class="os-status-context-name" id="orderStatusModalEquipName">-</div>
                                    <div class="os-status-context-meta" id="orderStatusModalEquipType">Tipo: -</div>
                                    <div class="os-status-context-meta" id="orderStatusModalEquipSerial">Nº de série: -</div>
                                </div>
                            </div>
                        </div>

                        <ul class="nav nav-tabs mb-3" id="orderStatusModalTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="orderStatusModalTabStatusBtn" data-bs-toggle="tab"
                                    data-bs-target="#orderStatusModalTabStatus" type="button" role="tab"
                                    aria-controls="orderStatusModalTabStatus" aria-selected="true">
                                    Status
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="orderStatusModalTabMapBtn" data-bs-toggle="tab"
                                    data-bs-target="#orderStatusModalTabMap" type="button" role="tab"
                                    aria-controls="orderStatusModalTabMap" aria-selected="false">
                                    Mapa de status
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="orderStatusModalTabProceduresBtn" data-bs-toggle="tab"
                                    data-bs-target="#orderStatusModalTabProcedures" type="button" role="tab"
                                    aria-controls="orderStatusModalTabProcedures" aria-selected="false">
                                    Procedimentos
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="orderStatusModalTabsContent">
                            <div class="tab-pane fade show active" id="orderStatusModalTabStatus" role="tabpanel" aria-labelledby="orderStatusModalTabStatusBtn">
                                <div class="row g-4">
                                    {{-- Coluna esquerda: formulário de mudança --}}
                                    <div class="col-12 col-xl-8">
                                        <div class="os-status-modal-panel">
                                            {{-- Fluxograma de etapas, uma linha por macrofase --}}
                                            <div class="os-status-modal-section">
                                                <div class="os-status-modal-section-title">Etapas do fluxo</div>
                                                <div class="small text-muted mb-2" id="orderStatusModalCurrentHint">Status atual da OS: aguardando contexto.</div>

                                                <div class="os-status-chip-groups" id="orderStatusModalChipGroups">
                                                    <p class="os-status-chip-empty">Carregando opções de status...</p>
                                                </div>

                                                <div class="small text-muted mt-2" id="orderStatusModalTargetHint">Selecione um fluxo para continuar.</div>
                                                <div class="form-text">Cada linha é uma macrofase do fluxo da OS, com suas etapas na ordem em que acontecem. Clique em qualquer etapa para selecioná-la — a mudança só é aplicada ao salvar. A etapa atual aparece destacada e marcada com <i class="bi bi-record-fill"></i>, e a próxima etapa sugerida pelo fluxo padrão vem com borda clara, mas isso não impede escolher outra. Status de encerramento (equipamento entregue, devolvido, descartado) não aparecem aqui — são feitos pela tela de baixa da OS.</div>

                                                {{-- Campo real do form; escondido, a fonte de verdade é a
                                                    seleção via chip. Segue existindo pra manter toda a lógica
                                                    de submit/validação inalterada. --}}
                                                <select id="orderStatusModalSelect" name="status" class="d-none" data-select2="false" required>
                                                    <option value="">Selecione um status</option>
                                                </select>
                                            </div>

                                            {{-- Redefinição de prazo (só aparece ao sair de um status com prazo congelado) --}}
                                            <div class="os-status-modal-section d-none" id="orderStatusModalNovoPrazoWrapper">
                                                <label class="form-label" for="orderStatusModalNovoPrazo">Novo prazo de entrega</label>
                                                <input
                                                    type="date"
                                                    id="orderStatusModalNovoPrazo"
                                                    name="novo_prazo"
                                                    class="form-control"
                                                    disabled
                                                >
                                                <div class="form-text">Esta OS estava com o prazo congelado. Confirme ou ajuste o novo prazo de entrega.</div>
                                            </div>

                                            {{-- Observações --}}
                                            <div class="os-status-modal-section">
                                                <label class="form-label" for="orderStatusModalObservacao">Observações</label>
                                                <textarea
                                                    id="orderStatusModalObservacao"
                                                    name="observacao"
                                                    class="form-control"
                                                    rows="4"
                                                    placeholder="Registre contexto da mudança, combinados com o cliente ou justificativa do cancelamento."
                                                ></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Coluna direita: histórico --}}
                                    <div class="col-12 col-xl-4">
                                        <div class="os-status-modal-panel os-status-modal-workflow">
                                            <div class="os-status-modal-section-title">Histórico e progresso</div>
                                            <p class="small text-muted mb-3">Etapas percorridas e últimas movimentações desta OS.</p>
                                            <div id="orderStatusModalHistory" class="os-status-modal-history-list">
                                                <p class="text-muted small mb-0">Sem histórico recente.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="orderStatusModalTabMap" role="tabpanel" aria-labelledby="orderStatusModalTabMapBtn">
                                <div class="small text-muted mb-2" id="orderStatusModalMapCurrentHint">Status atual da OS: aguardando contexto.</div>
                                <div class="alert alert-warning d-none" id="orderStatusModalMapError">Não foi possível carregar o mapa de status desta OS. Use a aba "Status" para alterar por lista.</div>
                                <div class="os-map-frame" id="orderStatusModalMapFrame">
                                    <div class="os-map-legend">
                                        <div class="os-map-legend-items">
                                            <span class="os-map-legend-item"><span class="os-map-legend-swatch os-map-legend-swatch--traveled"></span>trajeto percorrido</span>
                                            <span class="os-map-legend-item"><span class="os-map-legend-dot os-map-legend-dot--current"></span>posição atual</span>
                                            <span class="os-map-legend-item"><span class="os-map-legend-swatch os-map-legend-swatch--suggested"></span>rota provável</span>
                                            <span class="os-map-legend-item"><span class="os-map-legend-dot os-map-legend-dot--clickable"></span>clique em qualquer etapa pra mover</span>
                                            <span class="os-map-legend-item"><span class="os-map-legend-swatch os-map-legend-swatch--baixa"></span>baixa da OS (encerramento)</span>
                                        </div>
                                    </div>
                                    <div class="os-map-viewport" data-os-map="viewport">
                                        <div class="os-map-canvas" data-os-map="canvas">
                                            @include('orders._flow_map_svg')
                                        </div>
                                        <div class="os-map-toolbar">
                                            <button type="button" class="btn btn-sm" data-os-map="zoom-out" title="Reduzir"><i class="bi bi-dash-lg"></i></button>
                                            <button type="button" class="btn btn-sm" data-os-map="zoom-in" title="Ampliar"><i class="bi bi-plus-lg"></i></button>
                                            <button type="button" class="btn btn-sm" data-os-map="zoom-reset" title="Ajustar à tela"><i class="bi bi-aspect-ratio"></i></button>
                                            <button type="button" class="btn btn-sm" data-os-map="center-current" title="Centralizar na posição atual"><i class="bi bi-crosshair"></i></button>
                                            <button type="button" class="btn btn-sm" data-os-map="fullscreen" title="Tela cheia"><i class="bi bi-arrows-fullscreen"></i></button>
                                        </div>
                                        <button type="button" class="os-map-close" data-os-map="exit-fullscreen" title="Sair da tela cheia (Esc)" aria-label="Sair da tela cheia">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="form-text mt-2">Clique numa etapa pra selecioná-la e mover a OS direto — pede confirmação antes de salvar. Status de encerramento (equipamento entregue, devolvido, descartado) só pela tela de baixa, clicar neles aqui explica o porquê.</div>
                            </div>

                            <div class="tab-pane fade" id="orderStatusModalTabProcedures" role="tabpanel" aria-labelledby="orderStatusModalTabProceduresBtn">
                                <div class="row g-4">
                                    {{-- Coluna esquerda: registrar procedimento + diagnóstico/solução --}}
                                    <div class="col-12 col-xl-7">
                                        <div class="os-status-modal-panel">
                                            <div class="os-status-modal-section">
                                                <label class="form-label" for="orderStatusModalProcedures">Procedimentos executados</label>
                                                <textarea
                                                    id="orderStatusModalProcedures"
                                                    class="form-control"
                                                    rows="3"
                                                    placeholder="Descreva um procedimento executado para registrar no histórico."
                                                ></textarea>
                                                <div class="text-end mt-2">
                                                    <button type="button" class="btn btn-primary btn-sm" id="orderStatusModalProceduresSave">
                                                        <i class="bi bi-check2-circle me-1"></i>Salvar procedimento
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="os-status-modal-section">
                                                <label class="form-label" for="orderStatusModalDiagnosis">Diagnóstico do problema</label>
                                                <textarea
                                                    id="orderStatusModalDiagnosis"
                                                    name="diagnostico_tecnico"
                                                    class="form-control"
                                                    rows="3"
                                                    placeholder="Descreva o diagnóstico técnico do problema."
                                                ></textarea>
                                            </div>

                                            <div class="os-status-modal-section">
                                                <label class="form-label" for="orderStatusModalSolution">Solução aplicada</label>
                                                <textarea
                                                    id="orderStatusModalSolution"
                                                    name="solucao_aplicada"
                                                    class="form-control"
                                                    rows="3"
                                                    placeholder="Descreva a solução aplicada para resolver o problema."
                                                ></textarea>
                                                <div class="form-text">Diagnóstico e solução são salvos junto com "Salvar status", no rodapé.</div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Coluna direita: histórico de procedimentos --}}
                                    <div class="col-12 col-xl-5">
                                        <div class="os-status-modal-panel os-status-modal-workflow">
                                            <div class="os-status-modal-section-title">Histórico de procedimentos</div>
                                            <p class="small text-muted mb-3">Procedimentos registrados pelos técnicos nesta OS.</p>
                                            <div id="orderStatusModalProceduresHistory" class="os-status-modal-history-list">
                                                <p class="text-muted small mb-0">Nenhum procedimento registrado ainda.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <div class="form-check form-switch os-status-modal-footer-notify">
                        <input class="form-check-input" type="checkbox" role="switch"
                            id="orderStatusModalNotify" name="comunicar_cliente" value="1">
                        <label class="form-check-label" for="orderStatusModalNotify">
                            Notificar o cliente sobre esta mudança
                        </label>
                    </div>
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="orderStatusModalSubmit" disabled>
                        <i class="bi bi-check2-circle me-1"></i>Salvar status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
