@extends('layouts.app')

@section('styles')
    <style>
        .closure-steps {
            display: flex;
            gap: 1.5rem;
            list-style: none;
            padding: 0;
            margin: 0;
            border-bottom: 1px solid var(--desktop-border);
            padding-bottom: 1rem;
        }

        .closure-step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--desktop-text-muted);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .closure-step-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--desktop-surface-soft);
            border: 1px solid var(--desktop-border);
            color: var(--desktop-text-soft);
            font-size: 0.85rem;
        }

        .closure-step.is-active,
        .closure-step.is-done {
            color: var(--desktop-text);
        }

        .closure-step.is-active .closure-step-circle {
            background: var(--desktop-primary);
            border-color: var(--desktop-primary);
            color: #fff;
        }

        .closure-step.is-done .closure-step-circle {
            background: var(--desktop-primary-soft);
            border-color: var(--desktop-border-strong);
            color: var(--desktop-primary);
        }

        .closure-receipt-row {
            border: 1px solid var(--desktop-border);
            border-radius: var(--desktop-radius-md);
            padding: 1rem;
            background: var(--desktop-surface-soft);
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
        $retornoPadrao = (string) ($closure['retorno_padrao'] ?? now()->addDays(180)->toDateString());
        $noRepairStatuses = ['devolvido_sem_reparo', 'descartado'];
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Baixa da OS</p>
            <h2 class="surface-title fs-3 mb-2">{{ $order['numero_os'] ?? ('#' . $orderId) }}</h2>
            <div class="d-flex flex-wrap gap-2">
                @include('layouts.partials.status-pill', [
                    'label' => $order['status_nome'] ?? 'Sem status',
                    'color' => $order['status_cor'] ?? '#64748b',
                ])
                <span class="desktop-chip">{{ $order['cliente_nome'] ?? 'Cliente não informado' }}</span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('orders.show', $orderId) }}" class="btn btn-outline-light">Voltar para a OS</a>
        </div>
    </div>

    <section class="desktop-grid desktop-grid-three mb-4">
        <article class="summary-card">
            <span class="summary-card-eyebrow">Valor total da OS</span>
            <div class="summary-card-value">R$ {{ number_format($valorFinal, 2, ',', '.') }}</div>
        </article>
        <article class="summary-card">
            <span class="summary-card-eyebrow">Já recebido</span>
            <div class="summary-card-value">R$ {{ number_format($valorMovimentado, 2, ',', '.') }}</div>
        </article>
        <article class="summary-card">
            <span class="summary-card-eyebrow">Saldo em aberto</span>
            <div class="summary-card-value">R$ {{ number_format($valorAberto, 2, ',', '.') }}</div>
        </article>
    </section>

    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Encerramento da OS</h2>
                <p class="surface-subtitle">Siga as 3 etapas para concluir a baixa.</p>
            </div>
        </div>

        <ol class="closure-steps mb-4" id="closureStepsIndicator">
            <li class="closure-step is-active" data-step-indicator="1"><span class="closure-step-circle">1</span> Encerramento</li>
            <li class="closure-step" data-step-indicator="2"><span class="closure-step-circle">2</span> Financeiro</li>
            <li class="closure-step" data-step-indicator="3"><span class="closure-step-circle">3</span> Confirmação</li>
        </ol>

        <form method="post" action="{{ route('orders.closure.store', $orderId) }}" id="closureForm" novalidate>
            @csrf

            <div class="closure-step-panel" data-step-panel="1">
                <div class="desktop-grid desktop-grid-two">
                    <div>
                        <label for="encerrarComo">Encerrar como</label>
                        <select id="encerrarComo" name="encerrar_como" class="form-select" required>
                            <option value="">Selecione</option>
                            @foreach ($opcoesEncerramento as $opcao)
                                <option value="{{ $opcao['codigo'] }}" @selected(old('encerrar_como') === ($opcao['codigo'] ?? ''))>
                                    {{ $opcao['nome'] ?? $opcao['codigo'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="dataEntrega">Data de entrega</label>
                        <input type="date" id="dataEntrega" name="data_entrega" class="form-control" value="{{ old('data_entrega', now()->toDateString()) }}" required>
                    </div>
                    <div class="desktop-grid-span-2">
                        <label for="observacao">Observação da baixa</label>
                        <textarea id="observacao" name="observacao" class="form-control" rows="3">{{ old('observacao') }}</textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="{{ route('orders.show', $orderId) }}" class="btn btn-outline-light">Cancelar</a>
                    <button type="button" class="btn btn-primary" data-step-action="next">Avançar</button>
                </div>
            </div>

            <div class="closure-step-panel d-none" data-step-panel="2">
                <div class="desktop-grid desktop-grid-three mb-3">
                    <article class="summary-card">
                        <span class="summary-card-eyebrow">Custo de peças</span>
                        <div class="summary-card-value">R$ {{ number_format((float) ($custoSummary['pecas'] ?? 0), 2, ',', '.') }}</div>
                    </article>
                    <article class="summary-card">
                        <span class="summary-card-eyebrow">Custo de serviços</span>
                        <div class="summary-card-value">R$ {{ number_format((float) ($custoSummary['servicos'] ?? 0), 2, ',', '.') }}</div>
                    </article>
                    <article class="summary-card">
                        <span class="summary-card-eyebrow">Saldo em aberto após recebimentos</span>
                        <div class="summary-card-value" id="closureSaldoAbertoStep2">R$ {{ number_format($valorAberto, 2, ',', '.') }}</div>
                    </article>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h3 class="surface-title fs-6 mb-0">Recebimentos desta baixa</h3>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-soft btn-sm" data-action="receber-saldo-total">Receber saldo total</button>
                        <button type="button" class="btn btn-soft btn-sm" data-action="adicionar-adiantamento">Adicionar adiantamento</button>
                        <button type="button" class="btn btn-outline-light btn-sm" data-action="adicionar-recebimento">+ Adicionar recebimento</button>
                    </div>
                </div>

                <p class="text-secondary small" id="closureReceiptsEmptyHint">Nenhum recebimento adicionado. A OS será encerrada com o saldo em aberto, se houver.</p>

                <div id="closureReceiptsList" class="d-flex flex-column gap-3 mb-3"></div>

                <div class="desktop-grid desktop-grid-three">
                    <article class="summary-card">
                        <span class="summary-card-eyebrow">Taxas de cartão nesta ação</span>
                        <div class="summary-card-value" id="closureTotalTaxas">R$ 0,00</div>
                    </article>
                    <article class="summary-card">
                        <span class="summary-card-eyebrow">Recebimento líquido previsto</span>
                        <div class="summary-card-value" id="closureTotalLiquido">R$ 0,00</div>
                    </article>
                    <article class="summary-card">
                        <span class="summary-card-eyebrow">Lucro estimado desta OS</span>
                        <div class="summary-card-value" id="closureLucroEstimado">R$ 0,00</div>
                    </article>
                </div>

                <div class="d-flex justify-content-between gap-2 mt-4">
                    <button type="button" class="btn btn-outline-light" data-step-action="prev">Voltar</button>
                    <button type="button" class="btn btn-primary" data-step-action="next">Avançar</button>
                </div>
            </div>

            <div class="closure-step-panel d-none" data-step-panel="3">
                <div class="alert-shell mb-3" id="closureSummaryBox"></div>

                @if ($clienteTelefone !== '')
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <input type="checkbox" id="notificarCliente" name="notificar_cliente" value="1" class="form-check-input" @checked(old('notificar_cliente'))>
                        <label for="notificarCliente" class="mb-0">Notificar cliente por WhatsApp ({{ $clienteTelefone }}) agora</label>
                    </div>
                @endif

                <div class="d-flex align-items-center gap-2 mb-2">
                    <input type="checkbox" id="agendarRetorno" name="agendar_retorno" value="1" class="form-check-input" @checked(old('agendar_retorno'))>
                    <label for="agendarRetorno" class="mb-0">Agendar retorno pós-serviço</label>
                </div>
                <div class="mb-3 d-none" id="closureRetornoDataWrapper">
                    <label for="retornoData">Data prevista do retorno</label>
                    <input type="date" id="retornoData" name="retorno_data" class="form-control" value="{{ old('retorno_data', $retornoPadrao) }}">
                </div>

                <div class="mb-3">
                    <strong class="small text-secondary d-block mb-2">Checklist rápido (apoio visual, não é salvo)</strong>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="checklistTeste">
                        <label class="form-check-label" for="checklistTeste">Equipamento testado e funcionando</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="checklistLimpeza">
                        <label class="form-check-label" for="checklistLimpeza">Limpeza realizada</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="checklistAcessorios">
                        <label class="form-check-label" for="checklistAcessorios">Acessórios conferidos</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="checklistOrcamento">
                        <label class="form-check-label" for="checklistOrcamento">Valores confirmados com o cliente</label>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 mb-3">
                    <input type="checkbox" id="confirmacaoBaixa" class="form-check-input">
                    <label for="confirmacaoBaixa" class="mb-0">Confirmo que revisei as informações desta baixa.</label>
                </div>

                <div class="d-flex justify-content-between gap-2">
                    <button type="button" class="btn btn-outline-light" data-step-action="prev">Voltar</button>
                    <button type="submit" class="btn btn-primary" id="closureSubmitButton" disabled>Concluir baixa</button>
                </div>
            </div>
        </form>

        <template id="closureReceiptRowTemplate">
            <div class="closure-receipt-row" data-receipt-row>
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <strong class="small text-secondary">Recebimento</strong>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-action="remover-recebimento">Remover</button>
                </div>
                <div class="desktop-grid desktop-grid-two">
                    <div>
                        <label>Valor</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" data-field="valor" placeholder="0,00">
                    </div>
                    <div data-classificacao-field>
                        <label>Classificação</label>
                        <select class="form-select" data-field="classificacao_recebimento">
                            <option value="baixa">Baixa</option>
                            <option value="adiantamento">Adiantamento</option>
                            <option value="sinal">Sinal</option>
                        </select>
                    </div>
                    <div>
                        <label>Forma de pagamento</label>
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
                        <label>Data do pagamento</label>
                        <input type="date" class="form-control" data-field="data_pagamento">
                    </div>
                    <div class="desktop-grid-span-2">
                        <label>Observações</label>
                        <input type="text" class="form-control" data-field="observacoes" maxlength="2000">
                    </div>
                </div>
                <div class="closure-card-fields d-none mt-2 pt-2 border-top" data-card-fields>
                    <div class="desktop-grid desktop-grid-two">
                        <div>
                            <label>Operadora</label>
                            <select class="form-select" data-field="operadora_id">
                                <option value="">Selecione</option>
                                @foreach ($cartaoDataset['operadoras'] ?? [] as $operadora)
                                    <option value="{{ $operadora['id'] }}">{{ $operadora['nome'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label>Bandeira (opcional)</label>
                            <select class="form-select" data-field="bandeira_id">
                                <option value="">Genérica (qualquer bandeira)</option>
                                @foreach ($cartaoDataset['bandeiras'] ?? [] as $bandeira)
                                    <option value="{{ $bandeira['id'] }}">{{ $bandeira['nome'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label>Modalidade</label>
                            <select class="form-select" data-field="modalidade">
                                <option value="credito">Crédito</option>
                                <option value="debito">Débito</option>
                            </select>
                        </div>
                        <div>
                            <label>Parcelas</label>
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
            'custoTotal' => (float) ($custoSummary['total'] ?? 0),
            'dataEntregaDefault' => now()->toDateString(),
            'noRepairStatuses' => $noRepairStatuses,
            'cartao' => $cartaoDataset,
            'old' => [
                'encerrar_como' => old('encerrar_como'),
                'recebimentos' => old('recebimentos', []),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
    </script>
    <script src="{{ asset('assets/js/orders-closure.js') }}"></script>
@endsection
