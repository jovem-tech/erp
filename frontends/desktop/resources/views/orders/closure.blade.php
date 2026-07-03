@extends('layouts.app')

@section('styles')
    <style>
        /* Cards de resumo compactos (sobrescreve o .summary-card padrão, que é
           dimensionado para telas com mais conteúdo, só nesta página) */
        .closure-summary-card {
            min-height: 0;
            padding: 0.75rem 1rem;
        }

        .closure-summary-card .summary-card-eyebrow {
            font-size: 0.7rem;
        }

        .closure-summary-card .summary-card-value {
            margin-top: 0.3rem;
            font-size: 1.05rem;
        }

        .closure-client-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--desktop-text-soft);
            margin-top: 0.25rem;
        }

        .closure-client-strip .fw-semibold {
            color: var(--desktop-text);
        }

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

        /* Equipment card beside tabs */
        .closure-equipment-card {
            background: var(--desktop-surface-soft);
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-md);
            padding: 0.65rem 1rem;
            font-size: 0.8rem;
            flex-shrink: 0;
            min-width: 180px;
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
            font-weight: 700;
            color: var(--desktop-text);
            line-height: 1.3;
        }

        .closure-equipment-meta {
            color: var(--desktop-text-soft);
            margin-top: 0.15rem;
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
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            color: var(--desktop-text-muted);
            margin-bottom: 0.75rem;
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
            grid-template-columns: 1fr 1fr;
            gap: 0.65rem;
            margin-bottom: 1rem;
        }

        .closure-metric-card {
            background: var(--desktop-surface);
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-sm);
            padding: 0.7rem 0.85rem;
            font-size: 0.78rem;
        }

        .closure-metric-card span {
            display: block;
            color: var(--desktop-text-soft);
            margin-bottom: 0.2rem;
            line-height: 1.3;
        }

        .closure-metric-card strong {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--desktop-text);
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
        $valorFinal = (float) ($order['valor_final'] ?? 0);
        $valorAberto = (float) ($financeiro['valor_aberto'] ?? $valorFinal);
        $valorMovimentado = (float) ($financeiro['valor_movimentado'] ?? 0);
        $clienteTelefone = trim((string) ($closure['cliente_telefone'] ?? ''));
        $clienteEmail = trim((string) ($closure['cliente_email'] ?? ($order['cliente_email'] ?? '')));
        $retornoPadrao = (string) ($closure['retorno_padrao'] ?? now()->addDays(180)->toDateString());
        $noRepairStatuses = $closure['status_sem_reparo'] ?? ['devolvido_sem_reparo', 'descartado'];
        $statusPagamentoPendente = $closure['status_pagamento_pendente'] ?? ['codigo' => 'entregue_pagamento_pendente', 'nome' => 'Entregue - Pendência Financeira'];

        $equipamentoNome = trim((string) ($order['equipamento_nome'] ?? ($order['equipamento']['nome'] ?? '')));
        $equipamentoTipo = trim((string) ($order['equipamento_tipo_nome'] ?? ($order['equipamento']['tipo_nome'] ?? ($order['equipamento']['tipo']['nome'] ?? ''))));
        $equipamentoSerie = trim((string) ($order['equipamento_numero_serie'] ?? ($order['equipamento']['numero_serie'] ?? '')));
    @endphp

    {{-- Cabeçalho --}}
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Baixa da OS</p>
            <h2 class="surface-title fs-3 mb-1">{{ $order['numero_os'] ?? ('#' . $orderId) }}</h2>
            <div class="closure-client-strip">
                <span class="fw-semibold">{{ $order['cliente_nome'] ?? 'Cliente não informado' }}</span>
                @if ($clienteTelefone !== '')
                    <span>Telefone: {{ $clienteTelefone }}</span>
                @endif
                @if ($clienteEmail !== '')
                    <span>E-mail: <a href="mailto:{{ $clienteEmail }}" class="text-primary text-decoration-none">{{ $clienteEmail }}</a></span>
                @endif
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                @include('layouts.partials.status-pill', [
                    'label' => $order['status_nome'] ?? 'Sem status',
                    'color' => $order['status_cor'] ?? '#64748b',
                ])
            </div>
        </div>
        <div class="align-self-start">
            <a href="{{ route('orders.show', $orderId) }}" class="btn btn-outline-light">Voltar para a OS</a>
        </div>
    </div>

    {{-- Cards de resumo financeiro --}}
    <section class="desktop-grid desktop-grid-three mb-3">
        <article class="summary-card closure-summary-card">
            <span class="summary-card-eyebrow">Valor total da OS</span>
            <div class="summary-card-value">R$ {{ number_format($valorFinal, 2, ',', '.') }}</div>
        </article>
        <article class="summary-card closure-summary-card">
            <span class="summary-card-eyebrow">Já recebido</span>
            <div class="summary-card-value">R$ {{ number_format($valorMovimentado, 2, ',', '.') }}</div>
        </article>
        <article class="summary-card closure-summary-card">
            <span class="summary-card-eyebrow">Saldo em aberto</span>
            <div class="summary-card-value">R$ {{ number_format($valorAberto, 2, ',', '.') }}</div>
        </article>
    </section>

    <section class="desktop-form-card">
        <div class="surface-card-header mb-3">
            <div>
                <h2 class="surface-title">Encerramento da OS</h2>
                <p class="surface-subtitle">Siga as 3 etapas para concluir a baixa.</p>
            </div>
        </div>

        {{-- Barra de etapas + card do equipamento --}}
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

            @if ($equipamentoNome !== '')
                <div class="closure-equipment-card">
                    <div class="closure-equipment-label">Equipamento</div>
                    <div class="closure-equipment-name">{{ $equipamentoNome }}</div>
                    @if ($equipamentoTipo !== '')
                        <div class="closure-equipment-meta">Tipo: {{ $equipamentoTipo }}</div>
                    @endif
                    <div class="closure-equipment-meta">Nº de série: {{ $equipamentoSerie !== '' ? $equipamentoSerie : '—' }}</div>
                </div>
            @endif
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
                            <p class="form-text mb-3">Defina como a OS será encerrada, a data da entrega e o contexto operacional que precisa ficar registrado.</p>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="encerrarComo">Encerrar como</label>
                                    <select id="encerrarComo" name="encerrar_como" class="form-select @error('encerrar_como') is-invalid @enderror" required>
                                        <option value="">Selecione</option>
                                        @foreach ($opcoesEncerramento as $opcao)
                                            <option value="{{ $opcao['codigo'] }}" @selected(old('encerrar_como') === ($opcao['codigo'] ?? ''))>
                                                {{ $opcao['nome'] ?? $opcao['codigo'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('encerrar_como')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="dataEntrega">Data da entrega</label>
                                    <input type="date" id="dataEntrega" name="data_entrega" class="form-control @error('data_entrega') is-invalid @enderror"
                                        value="{{ old('data_entrega', now()->toDateString()) }}" required>
                                    @error('data_entrega')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="closure-decision-note">
                                <i class="bi bi-info-circle"></i>
                                <div>A conclusão operacional não depende de recebimento imediato. Se houver saldo restante, a OS poderá ser concluída com pagamento pendente sem perder a baixa técnica. Quando houver cartão, o repasse previsto é mostrado no lançamento e o caixa só é reconhecido na data informada pela operadora.</div>
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
                                <button type="button" class="btn btn-outline-primary btn-sm" data-action="receber-saldo-total">
                                    <i class="bi bi-cash-coin me-1"></i>Receber saldo total
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" data-action="adicionar-recebimento">
                                    <i class="bi bi-plus-lg me-1"></i>Adicionar recebimento
                                </button>
                                <button type="button" class="btn btn-outline-light btn-sm" data-action="adicionar-adiantamento">
                                    <i class="bi bi-wallet2 me-1"></i>Adicionar adiantamento
                                </button>
                            </div>

                            <div class="closure-metric-grid">
                                <div class="closure-metric-card">
                                    <span>Valor final da OS</span>
                                    <strong id="closureMetricValorOs">R$ {{ number_format($valorFinal, 2, ',', '.') }}</strong>
                                </div>
                                <div class="closure-metric-card">
                                    <span>Recebido antes desta baixa</span>
                                    <strong id="closureMetricValorRecebido">R$ {{ number_format($valorMovimentado, 2, ',', '.') }}</strong>
                                </div>
                                <div class="closure-metric-card">
                                    <span>Saldo em aberto</span>
                                    <strong id="closureMetricValorAberto">R$ {{ number_format($valorAberto, 2, ',', '.') }}</strong>
                                </div>
                                <div class="closure-metric-card">
                                    <span>Lançado nesta ação</span>
                                    <strong id="closureMetricValorBaixa">R$ 0,00</strong>
                                </div>
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

                            <div class="closure-choice-card">
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
                        <label class="form-label">Valor</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" data-field="valor" placeholder="0,00">
                    </div>
                    <div data-classificacao-field>
                        <label class="form-label">Classificação</label>
                        <select class="form-select" data-field="classificacao_recebimento">
                            <option value="baixa">Baixa</option>
                            <option value="adiantamento">Adiantamento</option>
                            <option value="sinal">Sinal</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Forma de pagamento</label>
                        <select class="form-select" data-field="forma_pagamento">
                            <option value="">Não informado</option>
                            <option value="dinheiro">Dinheiro</option>
                            <option value="cartao_credito">Cartão de crédito</option>
                            <option value="cartao_debito">Cartão de débito</option>
                            <option value="pix">Pix</option>
                            <option value="boleto">Boleto</option>
                            <option value="transferencia">Transferência</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Data do pagamento</label>
                        <input type="date" class="form-control" data-field="data_pagamento">
                    </div>
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
                        </div>
                    </div>
                    <p class="small text-secondary mt-2 mb-0" data-card-preview>Selecione operadora e parcelas para estimar a taxa.</p>
                </div>
            </div>
        </template>
    </section>
@endsection

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
            'cartao' => $cartaoDataset,
            'clienteTelefone' => $clienteTelefone,
            'initialStep' => (int) old('current_step', 1),
            'recebimentoErrors' => collect($errors->keys())
                ->filter(fn ($key) => str_starts_with($key, 'recebimentos.'))
                ->mapWithKeys(fn ($key) => [$key => $errors->first($key)])
                ->all(),
            'old' => [
                'encerrar_como' => old('encerrar_como'),
                'recebimentos' => old('recebimentos', []),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
    </script>
    <script src="{{ asset('assets/js/orders-closure.js') }}"></script>
@endsection
