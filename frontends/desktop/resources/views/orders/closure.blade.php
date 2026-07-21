@extends('layouts.app')

@section('styles')
    <style>
        /* Tabs row */
        .closure-tabs-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--desktop-border);
            padding-bottom: 1rem;
            flex-wrap: wrap;
        }

        .closure-tabs {
            display: flex;
            gap: 0.4rem;
            list-style: none;
            padding: 0;
            margin: 0;
            flex-wrap: wrap;
        }

        .closure-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 1rem;
            border-radius: 999px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--desktop-text-muted);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
        }

        .closure-tab-btn:hover {
            background: var(--desktop-surface-soft);
        }

        .closure-tab-btn.is-active:hover {
            background: var(--desktop-primary);
        }

        .closure-tab-btn.is-active {
            background: var(--desktop-primary);
            color: #fff;
            border-color: var(--desktop-primary);
        }

        .closure-tab-btn.is-done {
            background: var(--desktop-primary-soft);
            color: var(--desktop-primary);
            border-color: var(--desktop-border-strong);
        }

        .closure-tab-btn.is-disabled,
        .closure-tab-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .closure-tab-step {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .closure-tab-btn:not(.is-active) .closure-tab-step {
            background: var(--desktop-border);
        }

        /* Cards de contexto: cliente + equipamento (abaixo da barra de etapas) */
        .closure-context-card {
            background: var(--desktop-surface);
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-lg);
            box-shadow: var(--desktop-shadow-soft);
            padding: 1.15rem;
            font-size: 0.8rem;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .closure-equipment-context-body {
            display: flex;
            gap: 0.85rem;
            align-items: flex-start;
        }

        .closure-equipment-photo-frame {
            width: 72px;
            height: 72px;
            border-radius: var(--desktop-radius-sm);
            overflow: hidden;
            background: var(--desktop-surface);
            border: 1px solid var(--desktop-border);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .closure-equipment-photo-frame img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #fff;
        }

        .closure-equipment-photo-empty {
            color: var(--desktop-text-muted);
            font-size: 1.4rem;
        }

        .closure-equipment-serial {
            margin-top: 0.35rem;
            width: 72px;
            font-size: 0.68rem;
            color: var(--desktop-text-muted);
            text-align: center;
            line-height: 1.3;
        }

        .closure-equipment-lines {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            min-width: 0;
            flex: 1 1 auto;
        }

        .closure-equipment-line strong {
            display: block;
            color: var(--desktop-text);
            font-weight: 700;
            line-height: 1.3;
        }

        .closure-equipment-line-split {
            display: flex;
            gap: 1.25rem;
        }

        .closure-equipment-line-split > div {
            flex: 1 1 0;
            min-width: 0;
        }

        .closure-equipment-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--desktop-text-muted);
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .closure-equipment-name {
            font-weight: 800;
            color: var(--desktop-heading);
            line-height: 1.3;
            font-size: 1.15rem;
        }

        .closure-equipment-meta {
            color: var(--desktop-text-soft);
            margin-top: 0.35rem;
            font-size: 0.9rem;
        }

        /* Step panels */
        .closure-pdv-panel {
            background: var(--desktop-surface-soft);
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-md);
            padding: 1.25rem;
            height: 100%;
        }

        .closure-panel-title {
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--desktop-text-soft);
            margin-bottom: 0.55rem;
        }

        /* Decision note (step 1) */
        .closure-decision-note {
            display: flex;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: rgba(14, 165, 233, 0.06);
            border: 1px solid rgba(14, 165, 233, 0.18);
            border-radius: var(--desktop-radius-sm);
            font-size: 0.82rem;
            color: var(--desktop-text-soft);
            margin-top: 1rem;
            line-height: 1.5;
        }

        .closure-decision-note .bi {
            color: var(--desktop-info);
            font-size: 1rem;
            flex-shrink: 0;
            margin-top: 0.1rem;
        }

        /* Financial summary list (step 2 left) */
        .closure-summary-list {
            display: flex;
            flex-direction: column;
        }

        .closure-summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            padding: 0.45rem 0;
            border-bottom: 1px solid var(--desktop-border);
            gap: 1rem;
        }

        .closure-summary-item:last-child {
            border-bottom: none;
        }

        .closure-summary-item span {
            color: var(--desktop-text-soft);
        }

        .closure-summary-item.is-highlight strong {
            color: var(--desktop-primary);
            font-size: 1rem;
        }

        /* Metric grid (step 2 right) */
        .closure-metric-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .closure-metric-grid .summary-card {
            height: 100%;
        }

        .closure-metric-grid .summary-card-value {
            font-size: 1.1rem;
        }

        .closure-metric-grid .summary-card-meta {
            min-height: 2.75rem;
        }

        @media (max-width: 767.98px) {
            .closure-metric-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Payment action buttons */
        .closure-payment-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        /* Empty payments box */
        .closure-muted-box {
            padding: 1rem;
            background: var(--desktop-surface);
            border: 1px dashed var(--desktop-border);
            border-radius: var(--desktop-radius-sm);
            color: var(--desktop-text-soft);
            font-size: 0.82rem;
            margin-bottom: 0.75rem;
            line-height: 1.5;
        }

        /* Receipt row card */
        .closure-receipt-row {
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-sm);
            padding: 1rem;
            background: var(--desktop-surface);
        }

        /* Confirm step */
        .closure-confirm-intro {
            font-size: 0.875rem;
            color: var(--desktop-text-soft);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .closure-confirm-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .closure-confirm-card {
            background: var(--desktop-surface);
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-sm);
            padding: 0.85rem;
        }

        .closure-confirm-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--desktop-text-muted);
            font-weight: 700;
            display: block;
            margin-bottom: 0.3rem;
        }

        .closure-confirm-card strong {
            display: block;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--desktop-text);
            margin-bottom: 0.2rem;
        }

        .closure-confirm-card small {
            color: var(--desktop-text-soft);
            font-size: 0.78rem;
            display: block;
        }

        /* Review checkbox wrapper */
        .closure-review-check {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            margin-top: 1rem;
            padding: 0.85rem 1rem;
            background: var(--desktop-surface-soft);
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-sm);
        }

        .closure-review-check .form-check-input {
            margin-top: 0.2rem;
            flex-shrink: 0;
        }

        /* Communication choice cards (step 3 right) */
        .closure-choice-card {
            background: var(--desktop-surface);
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-sm);
            padding: 1rem;
            margin-bottom: 0.75rem;
        }

        .closure-choice-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .closure-choice-head strong {
            font-size: 0.9rem;
        }

        .closure-choice-state {
            font-size: 0.78rem;
            color: var(--desktop-text-muted);
            padding: 0.25rem 0.7rem;
            background: var(--desktop-surface-soft);
            border: 1px solid var(--desktop-border);
            border-radius: 999px;
            display: inline-block;
        }

        .closure-return-date {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--desktop-border);
        }

        /* Checklist */
        .closure-confirm-checklist {
            background: var(--desktop-surface-soft);
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-sm);
            padding: 0.85rem 1rem;
            margin-bottom: 0.75rem;
        }

        .closure-confirm-checklist-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--desktop-text-muted);
            margin-bottom: 0.5rem;
        }

        .closure-confirm-checklist ul {
            padding-left: 1.25rem;
            margin: 0;
            color: var(--desktop-text-soft);
            font-size: 0.85rem;
        }

        .closure-confirm-checklist li {
            margin-bottom: 0.3rem;
            line-height: 1.4;
        }

        /* Footer strip */
        .closure-footer-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--desktop-border);
        }

        .closure-footer-overview {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
        }

        .closure-footer-pill {
            background: var(--desktop-surface-soft);
            border: 1px solid var(--desktop-border);
            border-radius: 999px;
            padding: 0.3rem 0.85rem;
            font-size: 0.78rem;
            display: inline-flex;
            gap: 0.35rem;
            align-items: center;
        }

        .closure-footer-pill span {
            color: var(--desktop-text-muted);
        }

        .closure-footer-pill strong {
            color: var(--desktop-text);
        }

        .closure-footer-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
    </style>
@endsection

@section('content')
    @php
        $orderId = (int) ($order['id'] ?? 0);
        $opcoesEncerramento = $closure['opcoes_encerramento'] ?? [];
        $financeiro = $closure['financeiro'] ?? [];
        $custoSummary = $closure['custo_summary'] ?? ['pecas' => 0, 'servicos' => 0, 'total' => 0];
        $cartaoDataset = $closure['cartao'] ?? ['operadoras' => [], 'bandeiras' => [], 'taxas' => []];
        $accountDataset = $closure['contas_financeiras'] ?? ['contas' => [], 'contas_padrao' => []];
        $financialAccounts = array_values(array_filter(
            is_array($accountDataset['contas'] ?? null) ? $accountDataset['contas'] : [],
            static fn (array $account): bool => (bool) ($account['ativo'] ?? false)
        ));
        $valorFinal = (float) ($order['valor_final'] ?? 0);
        $valorAberto = (float) ($financeiro['valor_aberto'] ?? $valorFinal);
        $temSaldoAberto = $valorAberto > 0.0;
        $valorMovimentado = (float) ($financeiro['valor_movimentado'] ?? 0);
        $clienteTelefone = trim((string) ($closure['cliente_telefone'] ?? ''));
        $clienteEmail = trim((string) ($closure['cliente_email'] ?? ($order['cliente_email'] ?? '')));
        $retornoPadrao = (string) ($closure['retorno_padrao'] ?? now()->addDays(180)->toDateString());
        // "status_sem_reparo" = encerramentos SEM cobrança (o backend hoje inclui
        // devolvido/descartado + reparo entregue sem custo/garantia). A chave
        // mantém o nome histórico; funcionalmente é "esconder campos de pagamento".
        $noRepairStatuses = $closure['status_sem_reparo'] ?? ['devolvido_sem_reparo', 'descartado', 'entregue_reparado_sem_custo', 'entregue_reparado_garantia'];
        $statusPagamentoPendente = $closure['status_pagamento_pendente'] ?? ['codigo' => 'entregue_pagamento_pendente', 'nome' => 'Entregue - Pendência Financeira'];
        $statusEntregue = $closure['status_entregue'] ?? 'entregue_reparado_pago';
        // OS com orçamento vinculado ainda não aprovado: bloqueia encerrar como
        // "Entregue - Reparado e Pago" (o backend também bloqueia — ver
        // OrderClosureService::close()). Não afeta sem custo/garantia.
        $orcamentoPendenteAprovacao = (bool) ($closure['orcamento_pendente_aprovacao'] ?? false);

        $equipamentoNome = trim((string) ($order['equipamento_nome'] ?? ($order['equipamento']['nome'] ?? '')));
        $equipamentoTipo = trim((string) ($order['equipamento_tipo_nome'] ?? ($order['equipamento']['tipo_nome'] ?? ($order['equipamento']['tipo']['nome'] ?? ''))));
        $equipamentoSerie = trim((string) ($order['equipamento_numero_serie'] ?? ($order['equipamento']['numero_serie'] ?? '')));
        $equipamentoMarca = trim((string) ($order['equipamento']['marca_nome'] ?? ''));
        $equipamentoModelo = trim((string) ($order['equipamento']['modelo_nome'] ?? ''));
        $equipamentoFoto = $order['equipamento_foto'] ?? null;
        $photoViewerGroup = 'order-closure-' . $orderId . '-photos';

        // Mesmas condições de orders/show.blade.php para o dropdown "Mais ações"
        // (o item "Baixa/Adiantamento" fica de fora aqui pois já estamos nessa página).
        $isEncerradaHeader = (bool) ($order['is_encerrada'] ?? false);
        $canEditOrderHeader = \App\Support\DesktopSession::can('os', 'editar');
        $canCreateBudgetHeader = \App\Support\DesktopSession::can('orcamentos', 'criar');
        $orcamentoHeader = $order['orcamento'] ?? null;
        $hasOrcamentoHeader = $orcamentoHeader !== null;
        $selectableStatusesHeader = (array) ($order['proximas_etapas'] ?? []);
        foreach ((array) ($order['status_disponiveis'] ?? []) as $option) {
            if (($option['codigo'] ?? '') === ($order['status'] ?? '')) {
                array_unshift($selectableStatusesHeader, $option);
                break;
            }
        }
    @endphp

    {{-- Cabeçalho --}}
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div></div>

        <div class="dropdown os-actions-dropdown align-self-start">
            <button type="button"
                class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                data-bs-toggle="dropdown"
                aria-expanded="false">
                Mais ações
            </button>

            <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                <a href="{{ route('orders.show', $orderId) }}" class="dropdown-item">
                    <i class="bi bi-eye me-2"></i>Ver OS
                </a>

                <a href="{{ route('orders.documents.center', $orderId) }}" class="dropdown-item">
                    <i class="bi bi-folder-symlink me-2"></i>Documentos da OS
                </a>

                @if ($canEditOrderHeader)
                    <a href="{{ route('orders.edit', $orderId) }}" class="dropdown-item">
                        <i class="bi bi-pencil me-2"></i>Editar
                    </a>
                @endif

                @if ($canEditOrderHeader && ! $isEncerradaHeader && $selectableStatusesHeader !== [])
                    <button type="button"
                        class="dropdown-item"
                        data-bs-toggle="modal"
                        data-bs-target="#orderStatusModal"
                        data-order-id="{{ $orderId }}"
                        data-order-numero="{{ $order['numero_os'] ?? ('#' . $orderId) }}">
                        <i class="bi bi-arrow-left-right me-2"></i>Alterar status
                    </button>
                @endif

                @if ($hasOrcamentoHeader)
                    <a href="{{ route('orcamentos.show', $orcamentoHeader['id']) }}" class="dropdown-item">
                        <i class="bi bi-receipt me-2"></i>Ver orçamento
                    </a>
                @elseif ($canCreateBudgetHeader)
                    <a href="{{ route('orcamentos.create', ['os_id' => $orderId]) }}" class="dropdown-item">
                        <i class="bi bi-receipt me-2"></i>Gerar orçamento
                    </a>
                @endif

                <a href="{{ route('orders.preview', $orderId) }}" target="_blank" rel="noreferrer" class="dropdown-item">
                    <i class="bi bi-printer me-2"></i>Imprimir
                </a>

                @if ($isEncerradaHeader)
                    <div class="dropdown-divider"></div>
                    <button type="button"
                        class="dropdown-item text-danger"
                        data-bs-toggle="modal"
                        data-bs-target="#cancelClosureModal"
                        data-order-id="{{ $orderId }}"
                        data-order-numero="{{ $order['numero_os'] ?? ('#' . $orderId) }}">
                        <i class="bi bi-arrow-counterclockwise me-2"></i>Cancelar baixa
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Cards de contexto: cliente + equipamento --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="closure-context-card">
                <div class="closure-panel-title">Cliente</div>
                <div class="closure-equipment-name">{{ $order['cliente_nome'] ?? 'Cliente não informado' }}</div>
                <div class="closure-equipment-meta">Telefone: {{ $clienteTelefone !== '' ? $clienteTelefone : '—' }}</div>
                <div class="closure-equipment-meta">E-mail: {{ $clienteEmail !== '' ? $clienteEmail : '—' }}</div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="closure-context-card">
                <div class="closure-panel-title">Equipamento</div>
                <div class="closure-equipment-context-body">
                    <div>
                        @if ($equipamentoFoto && ($equipamentoFoto['id'] ?? 0) > 0)
                            <a href="{{ route('equipments.photos.show', [$equipamentoFoto['equipamento_id'], $equipamentoFoto['id']]) }}"
                                class="closure-equipment-photo-frame"
                                target="_blank"
                                rel="noreferrer"
                                data-photo-viewer-trigger
                                data-photo-viewer-group="{{ $photoViewerGroup }}"
                                data-photo-viewer-title="Foto do equipamento da {{ $order['numero_os'] ?? ('#' . $orderId) }}">
                                <img src="{{ route('equipments.photos.show', [$equipamentoFoto['equipamento_id'], $equipamentoFoto['id']]) }}" alt="Foto do equipamento">
                            </a>
                        @else
                            <div class="closure-equipment-photo-frame">
                                <div class="closure-equipment-photo-empty">
                                    <i class="bi bi-camera"></i>
                                </div>
                            </div>
                        @endif
                        <div class="closure-equipment-serial">
                            <i class="bi bi-upc-scan"></i>
                            SN: {{ $equipamentoSerie !== '' ? $equipamentoSerie : '—' }}
                        </div>
                    </div>
                    <div class="closure-equipment-lines">
                        <div class="closure-equipment-line">
                            <span class="closure-equipment-label mb-0">Tipo de equipamento</span>
                            <strong>{{ $equipamentoTipo !== '' ? $equipamentoTipo : '—' }}</strong>
                        </div>
                        <div class="closure-equipment-line closure-equipment-line-split">
                            <div>
                                <span class="closure-equipment-label mb-0">Marca</span>
                                <strong>{{ $equipamentoMarca !== '' ? $equipamentoMarca : '—' }}</strong>
                            </div>
                            <div>
                                <span class="closure-equipment-label mb-0">Modelo</span>
                                <strong>{{ $equipamentoModelo !== '' ? $equipamentoModelo : '—' }}</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="desktop-form-card">
        <div class="surface-card-header mb-3">
            <div>
                <h1 class="surface-title">Encerramento da {{ $order['numero_os'] ?? ('#' . $orderId) }}</h1>
                <p class="surface-subtitle"></p>
            </div>
        </div>

        {{-- Barra de etapas --}}
        <div class="closure-tabs-row">
            <ul class="closure-tabs">
                <li>
                    <button type="button" class="closure-tab-btn is-active" data-step-indicator="1">
                        <span class="closure-tab-step">1</span>
                        <i class="bi bi-clipboard-check"></i>
                        <span>Encerramento</span>
                    </button>
                </li>
                <li>
                    <button type="button" class="closure-tab-btn" data-step-indicator="2">
                        <span class="closure-tab-step">2</span>
                        <i class="bi bi-cash-stack"></i>
                        <span>Financeiro</span>
                    </button>
                </li>
                <li>
                    <button type="button" class="closure-tab-btn" data-step-indicator="3">
                        <span class="closure-tab-step">3</span>
                        <i class="bi bi-shield-check"></i>
                        <span>Confirmação</span>
                    </button>
                </li>
            </ul>
        </div>

        <form method="post" action="{{ route('orders.closure.store', $orderId) }}" id="closureForm" novalidate>
            @csrf
            <input type="hidden" name="current_step" id="closureCurrentStepInput" value="{{ old('current_step', 1) }}">

            <div id="closureStepError" class="alert alert-danger d-none mb-3"></div>

            {{-- Etapa 1: Encerramento operacional --}}
            <div class="closure-step-panel" data-step-panel="1">
                <div class="row g-3">
                    <div class="col-12 col-lg-7">
                        <div class="closure-pdv-panel">
                            <div class="closure-panel-title">Fechamento operacional</div>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="closure-equipment-label mb-0">Status atual</span>
                                @include('layouts.partials.status-pill', [
                                    'label' => $order['status_nome'] ?? 'Sem status',
                                    'color' => $order['status_cor'] ?? '#64748b',
                                ])
                            </div>
                            <p class="form-text mb-3">Defina se esta ação encerra a OS de verdade ou só registra um adiantamento/sinal do cliente.</p>

                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="classificacaoBaixa">Classificação</label>
                                    <select id="classificacaoBaixa" name="classificacao_baixa" class="form-select">
                                        <option value="baixa" @selected(old('classificacao_baixa', 'baixa') === 'baixa')>Baixa (encerra a OS)</option>
                                        <option value="adiantamento" @selected(old('classificacao_baixa') === 'adiantamento')>Adiantamento</option>
                                        <option value="sinal" @selected(old('classificacao_baixa') === 'sinal')>Sinal</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Visível só quando Classificação = Baixa --}}
                            <div class="row g-3 mt-0" id="closureBaixaFields">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="encerrarComo">Encerrar como</label>
                                    <select id="encerrarComo" name="encerrar_como" class="form-select @error('encerrar_como') is-invalid @enderror" required>
                                        <option value="">Selecione</option>
                                        @foreach ($opcoesEncerramento as $opcao)
                                            @php
                                                $opcaoCodigo = $opcao['codigo'] ?? '';
                                                $bloqueiaPorOrcamento = $orcamentoPendenteAprovacao && $opcaoCodigo === $statusEntregue;
                                            @endphp
                                            <option
                                                value="{{ $opcaoCodigo }}"
                                                @selected(old('encerrar_como') === $opcaoCodigo)
                                                @disabled($bloqueiaPorOrcamento)
                                            >
                                                {{ $opcao['nome'] ?? $opcaoCodigo }}{{ $bloqueiaPorOrcamento ? ' (orçamento pendente de aprovação)' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @if ($orcamentoPendenteAprovacao)
                                        <div class="form-text text-warning">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            Esta OS tem um orçamento ainda não aprovado — "Entregue - Reparado e Pago" fica bloqueado até a aprovação.
                                        </div>
                                    @endif
                                    @error('encerrar_como')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-md-6" id="dataEntregaWrapper">
                                    <label class="form-label" for="dataEntrega">Data da entrega</label>
                                    <input type="date" id="dataEntrega" name="data_entrega" class="form-control @error('data_entrega') is-invalid @enderror"
                                        value="{{ old('data_entrega', now()->toDateString()) }}" required>
                                    @error('data_entrega')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            {{-- Visível só quando Classificação = Adiantamento/Sinal --}}
                            <div class="row g-3 mt-0 d-none" id="closureAdvanceFields">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="equipamentoEntregue" name="equipamento_entregue" value="1" @checked(old('equipamento_entregue'))>
                                        <label class="form-check-label" for="equipamentoEntregue">Equipamento foi entregue?</label>
                                    </div>
                                    <p class="form-text mb-0">
                                        Se marcado e a data de entrega for preenchida, a OS vira "{{ $statusPagamentoPendente['nome'] ?? 'Entregue - Pendência Financeira' }}" — continua aberta, por ainda ter pendência financeira.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-5">
                        <div class="closure-pdv-panel">
                            <label class="form-label" for="observacao">Observações da baixa</label>
                            <textarea id="observacao" name="observacao" class="form-control" rows="7"
                                placeholder="Registre resumo da entrega, ressalvas, forma de acerto com o cliente ou qualquer observação importante.">{{ old('observacao') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Etapa 2: Financeiro --}}
            <div class="closure-step-panel d-none" data-step-panel="2">
                <div class="row g-3">
                    <div class="col-12 col-lg-5">
                        <div class="closure-pdv-panel">
                            <div class="closure-panel-title">Resumo financeiro e lucro</div>
                            <div class="closure-summary-list">
                                <div class="closure-summary-item">
                                    <span>Tipo de encerramento</span>
                                    <strong id="closureFinSummaryType">—</strong>
                                </div>
                                <div class="closure-summary-item">
                                    <span>Status final da OS</span>
                                    <strong id="closureFinSummaryStatus">—</strong>
                                </div>
                                <div class="closure-summary-item">
                                    <span>Recebido antes desta baixa</span>
                                    <strong id="closureFinSummaryReceived">R$ {{ number_format($valorMovimentado, 2, ',', '.') }}</strong>
                                </div>
                                <div class="closure-summary-item">
                                    <span>Recebido nesta baixa</span>
                                    <strong id="closureFinSummaryAction">R$ 0,00</strong>
                                </div>
                                <div class="closure-summary-item">
                                    <span>Saldo restante após concluir</span>
                                    <strong id="closureFinSummaryBalance">R$ {{ number_format($valorAberto, 2, ',', '.') }}</strong>
                                </div>
                                <div class="closure-summary-item">
                                    <span>Custo estimado de peças/serviços</span>
                                    <strong id="closureFinSummaryCost">R$ {{ number_format((float) ($custoSummary['total'] ?? 0), 2, ',', '.') }}</strong>
                                </div>
                                <div class="closure-summary-item">
                                    <span>Taxas de cartão nesta ação</span>
                                    <strong id="closureFinSummaryFee">R$ 0,00</strong>
                                </div>
                                <div class="closure-summary-item">
                                    <span>Recebimento líquido previsto</span>
                                    <strong id="closureFinSummaryNet">R$ 0,00</strong>
                                </div>
                                <div class="closure-summary-item is-highlight">
                                    <span>Lucro estimado desta OS</span>
                                    <strong id="closureFinSummaryProfit">R$ 0,00</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-7">
                        <div class="closure-pdv-panel">
                            <div class="closure-panel-title">Recebimentos e adiantamentos</div>
                            <div class="closure-payment-actions">
                                <button type="button" class="btn btn-outline-primary btn-sm" data-action="receber-saldo-total" @disabled(! $temSaldoAberto) aria-disabled="{{ $temSaldoAberto ? 'false' : 'true' }}" title="{{ $temSaldoAberto ? 'Preencher o saldo em aberto' : 'Nao ha saldo em aberto para receber' }}">
                                    <i class="bi bi-cash-coin me-1"></i>Receber saldo total
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" data-action="adicionar-recebimento">
                                    <i class="bi bi-plus-lg me-1"></i>Adicionar recebimento
                                </button>
                            </div>

                            <div class="closure-metric-grid">
                                <article class="summary-card">
                                    <span class="summary-card-eyebrow">Valor final da OS</span>
                                    <div class="summary-card-value" id="closureMetricValorOs">R$ {{ number_format($valorFinal, 2, ',', '.') }}</div>
                                    <div class="summary-card-meta">Total consolidado da OS considerado nesta baixa.</div>
                                </article>
                                <article class="summary-card">
                                    <span class="summary-card-eyebrow">Recebido antes desta baixa</span>
                                    <div class="summary-card-value" id="closureMetricValorRecebido">R$ {{ number_format($valorMovimentado, 2, ',', '.') }}</div>
                                    <div class="summary-card-meta">Valores já registrados anteriormente no financeiro da OS.</div>
                                </article>
                                <article class="summary-card">
                                    <span class="summary-card-eyebrow">Saldo em aberto</span>
                                    <div class="summary-card-value" id="closureMetricValorAberto">R$ {{ number_format($valorAberto, 2, ',', '.') }}</div>
                                    <div class="summary-card-meta">Diferença restante antes de concluir o fechamento financeiro.</div>
                                </article>
                                <article class="summary-card">
                                    <span class="summary-card-eyebrow">Lançado nesta ação</span>
                                    <div class="summary-card-value" id="closureMetricValorBaixa">R$ 0,00</div>
                                    <div class="summary-card-meta">Soma dos recebimentos e adiantamentos adicionados agora.</div>
                                </article>
                            </div>

                            <div id="closureReceiptsEmptyHint" class="closure-muted-box">
                                Nenhum recebimento ou adiantamento foi adicionado nesta ação. Se a OS tiver saldo financeiro em aberto, ela será concluída com pagamento pendente e entrará na régua automática de cobrança.
                            </div>
                            <div id="closureReceiptsList" class="d-flex flex-column gap-3 mb-3"></div>
                            <div class="alert alert-info mb-0 d-none" id="closureCollectionsSummary">
                                Ao fechar a baixa com saldo pendente, o sistema agenda cobranças automáticas em 1, 3 e 5 dias.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Etapa 3: Confirmação --}}
            <div class="closure-step-panel d-none" data-step-panel="3">
                <div class="row g-3">
                    <div class="col-12 col-lg-7">
                        <div class="closure-pdv-panel">
                            <div class="closure-panel-title">O que vai acontecer ao concluir</div>
                            <p class="closure-confirm-intro">Esta etapa existe para reduzir erro operacional. Revise o estado final da OS, do financeiro e da comunicação antes de confirmar.</p>

                            <div class="closure-confirm-grid">
                                <div class="closure-confirm-card">
                                    <span class="closure-confirm-label">Encerramento</span>
                                    <strong id="closureConfirmType">—</strong>
                                    <small id="closureConfirmDelivery">Data da entrega: —</small>
                                </div>
                                <div class="closure-confirm-card">
                                    <span class="closure-confirm-label">Status final</span>
                                    <strong id="closureConfirmStatus">—</strong>
                                    <small id="closureConfirmPaymentState">Situação financeira: —</small>
                                </div>
                                <div class="closure-confirm-card">
                                    <span class="closure-confirm-label">Recebimentos</span>
                                    <strong id="closureConfirmActionValue">R$ 0,00</strong>
                                    <small id="closureConfirmBalance">Saldo restante: R$ 0,00</small>
                                </div>
                                <div class="closure-confirm-card">
                                    <span class="closure-confirm-label">Resultado financeiro</span>
                                    <strong id="closureConfirmProfit">R$ 0,00</strong>
                                    <small id="closureConfirmNet">Líquido previsto: R$ 0,00</small>
                                </div>
                            </div>

                            <div class="alert alert-info" id="closureConfirmWarning">Revise os dados antes de concluir.</div>

                            <div class="closure-review-check">
                                <input type="checkbox" id="confirmacaoBaixa" class="form-check-input">
                                <label for="confirmacaoBaixa" class="mb-0">Confirmo que revisei os dados desta baixa antes de concluir.</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-5">
                        <div class="closure-pdv-panel">
                            <div class="closure-panel-title">Comunicação e acompanhamento</div>

                            <div class="closure-choice-card">
                                <div class="closure-choice-head">
                                    <div>
                                        <strong>WhatsApp com PDF da OS</strong>
                                        <div class="form-text mt-1">
                                            @if ($clienteTelefone !== '')
                                                Usa o telefone {{ $clienteTelefone }} e envia o PDF consolidado da OS.
                                            @else
                                                Cliente sem telefone cadastrado.
                                            @endif
                                        </div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                            id="notificarCliente" name="notificar_cliente" value="1"
                                            @checked(old('notificar_cliente'))
                                            @disabled($clienteTelefone === '')>
                                    </div>
                                </div>
                                <div class="closure-choice-state" id="closureNotifyState">Não enviar</div>
                            </div>

                            <div class="closure-choice-card" id="closureReturnCard">
                                <div class="closure-choice-head">
                                    <div>
                                        <strong>Retorno pós-serviço</strong>
                                        <div class="form-text mt-1">Cria um acompanhamento futuro para revisar a experiência do cliente ou um ponto pendente.</div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                            id="agendarRetorno" name="agendar_retorno" value="1"
                                            @checked(old('agendar_retorno'))>
                                    </div>
                                </div>
                                <div class="closure-choice-state" id="closureReturnState">Não agendar</div>
                                <div class="closure-return-date d-none" id="closureRetornoDataWrapper">
                                    <label class="form-label small mb-1" for="retornoData">Data prevista do retorno</label>
                                    <input type="date" id="retornoData" name="retorno_data" class="form-control form-control-sm"
                                        value="{{ old('retorno_data', $retornoPadrao) }}">
                                </div>
                            </div>

                            <div class="closure-confirm-checklist">
                                <div class="closure-confirm-checklist-title">Checklist rápido</div>
                                <ul>
                                    <li>cliente e equipamento conferidos;</li>
                                    <li>encerramento compatível com a entrega real;</li>
                                    <li>recebimentos revisados e saldo entendido;</li>
                                    <li>comunicação ao cliente decidida antes da conclusão.</li>
                                </ul>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 d-none" id="closureSubmitButton" disabled>
                                <i class="bi bi-shield-check me-1"></i>Concluir baixa
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        {{-- Rodapé de navegação --}}
        <div class="closure-footer-strip">
            <div class="closure-footer-overview">
                <div class="closure-footer-pill">
                    <span>Status final</span>
                    <strong id="closureFooterStatus">—</strong>
                </div>
                <div class="closure-footer-pill">
                    <span>Saldo após concluir</span>
                    <strong id="closureFooterBalance">R$ {{ number_format($valorAberto, 2, ',', '.') }}</strong>
                </div>
                <div class="closure-footer-pill">
                    <span>Comunicação</span>
                    <strong id="closureFooterNotify">Não enviar</strong>
                </div>
            </div>
            <div class="closure-footer-actions">
                <a href="{{ route('orders.show', $orderId) }}" class="btn btn-outline-light">Cancelar</a>
                <button type="button" class="btn btn-outline-light d-none" id="closureFooterPrevBtn">
                    <i class="bi bi-arrow-left me-1"></i>Voltar
                </button>
                <button type="button" class="btn btn-primary" id="closureFooterNextBtn">
                    Continuar<i class="bi bi-arrow-right ms-1"></i>
                </button>
            </div>
        </div>

        {{-- Template de linha de recebimento --}}
        <template id="closureReceiptRowTemplate">
            <div class="closure-receipt-row" data-receipt-row>
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <strong class="small text-secondary">Recebimento</strong>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-action="remover-recebimento">Remover</button>
                </div>
                <div class="desktop-grid desktop-grid-two">
                    <div>
                        <label class="form-label">Valor <span class="text-danger">*</span></label>
                        <input type="text" inputmode="decimal" autocomplete="off" class="form-control" data-field="valor" placeholder="R$ 0,00" required>
                    </div>
                    <div>
                        <label class="form-label">Forma de pagamento <span class="text-danger">*</span></label>
                        {{-- Opções vêm do cadastro em Configurações Financeiras > Formas de Pagamento. --}}
                        <select class="form-select" data-field="forma_pagamento" required>
                            <option value="">Selecione</option>
                            @foreach (($closure['formas_pagamento'] ?? []) as $forma)
                                <option value="{{ $forma['codigo'] }}">{{ $forma['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Data do pagamento</label>
                        <input type="date" class="form-control" data-field="data_pagamento">
                    </div>
                    @if ($financialAccounts !== [])
                        <div>
                            <label class="form-label">Conta financeira <span class="text-danger">*</span></label>
                            <select class="form-select" data-field="conta_financeira_id" required>
                                <option value="">Selecione onde o valor entra</option>
                                @foreach ($financialAccounts as $account)
                                    <option value="{{ (int) $account['id'] }}">{{ $account['nome'] }}{{ !(bool) ($account['considera_disponivel'] ?? true) ? ' (reserva)' : '' }}</option>
                                @endforeach
                            </select>
                            <small class="text-secondary d-block mt-1">A forma informa como pagou; a conta informa onde o dinheiro ficou.</small>
                        </div>
                    @endif
                    <div class="desktop-grid-span-2">
                        <label class="form-label">Observações</label>
                        <input type="text" class="form-control" data-field="observacoes" maxlength="2000">
                    </div>
                </div>
                <div class="closure-card-fields d-none mt-2 pt-2 border-top" data-card-fields>
                    <div class="desktop-grid desktop-grid-two">
                        <div>
                            <label class="form-label">Operadora</label>
                            <select class="form-select" data-field="operadora_id">
                                <option value="">Selecione</option>
                                @foreach ($cartaoDataset['operadoras'] ?? [] as $operadora)
                                    <option value="{{ $operadora['id'] }}">{{ $operadora['nome'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Bandeira (opcional)</label>
                            <select class="form-select" data-field="bandeira_id">
                                <option value="">Genérica (qualquer bandeira)</option>
                                @foreach ($cartaoDataset['bandeiras'] ?? [] as $bandeira)
                                    <option value="{{ $bandeira['id'] }}">{{ $bandeira['nome'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Modalidade</label>
                            <select class="form-select" data-field="modalidade">
                                <option value="credito">Crédito</option>
                                <option value="debito">Débito</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Parcelas</label>
                            <input type="number" min="1" max="99" step="1" class="form-control" data-field="parcelas" value="1">
                            <small class="text-secondary d-block mt-1" data-parcelas-hint></small>
                        </div>
                    </div>
                    <p class="small text-secondary mt-2 mb-0" data-card-preview>Selecione operadora e parcelas para estimar a taxa.</p>
                </div>
            </div>
        </template>
    </section>
@endsection

@push('modals')
    @include('layouts.partials.photo-viewer-modal')
    @include('orders._status_modal')
    @include('orders._cancel_closure_modal')
@endpush

@section('scripts')
    <script>
        window.__DESKTOP_ORDER_CLOSURE = {!! json_encode([
            'orderId' => $orderId,
            'valorFinal' => $valorFinal,
            'valorAberto' => $valorAberto,
            'valorMovimentado' => $valorMovimentado,
            'custoTotal' => (float) ($custoSummary['total'] ?? 0),
            'dataEntregaDefault' => now()->toDateString(),
            'noRepairStatuses' => $noRepairStatuses,
            'statusPagamentoPendente' => $statusPagamentoPendente,
            'deliveredStatusCode' => $statusEntregue,
            'orcamentoPendenteAprovacao' => $orcamentoPendenteAprovacao,
            'statusAtualNome' => $order['status_nome'] ?? 'Sem status',
            'statusAtualCodigo' => $order['status'] ?? '',
            // Códigos marcados como cartão no cadastro de formas de pagamento.
            // A tela usa esta lista (em vez de adivinhar pelo nome) para decidir
            // quando pedir operadora/bandeira/parcelas e calcular a taxa.
            'cardPaymentCodes' => collect($closure['formas_pagamento'] ?? [])
                ->filter(fn ($forma) => (bool) ($forma['is_cartao'] ?? false))
                ->pluck('codigo')
                ->values(),
            'cartao' => $cartaoDataset,
            'contasFinanceiras' => $accountDataset,
            'clienteTelefone' => $clienteTelefone,
            'initialStep' => (int) old('current_step', 1),
            'recebimentoErrors' => collect($errors->keys())
                ->filter(fn ($key) => str_starts_with($key, 'recebimentos.'))
                ->mapWithKeys(fn ($key) => [$key => $errors->first($key)])
                ->all(),
            'old' => [
                'encerrar_como' => old('encerrar_como'),
                'classificacao_baixa' => old('classificacao_baixa', 'baixa'),
                'recebimentos' => old('recebimentos', []),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
    </script>
    <script src="{{ asset('assets/js/orders-closure.js') }}?v={{ filemtime(public_path('assets/js/orders-closure.js')) }}"></script>

    {{-- Dropdown "Mais ações" reaproveita os mesmos modais/JS de orders/show.blade.php --}}
    <script>
        window.__DESKTOP_STATUS_MODAL = {
            statusContextUrlTemplate: '{{ route('orders.status.context', ['order' => '__ORDER__']) }}',
            statusUpdateUrlTemplate: '{{ route('orders.status.update', ['order' => '__ORDER__']) }}',
            proceduresUrlTemplate: '{{ route('orders.procedures.store', ['order' => '__ORDER__']) }}',
            csrfToken: '{{ csrf_token() }}',
        };
        window.__DESKTOP_CANCEL_CLOSURE_MODAL = {
            cancelUrlTemplate: '{{ route('orders.closure.cancel', ['order' => '__ORDER__']) }}',
            csrfToken: '{{ csrf_token() }}',
        };
    </script>
    <script src="{{ asset('assets/js/orders-status-modal.js') }}"></script>
    <script src="{{ asset('assets/js/orders-cancel-closure-modal.js') }}"></script>
@endsection
