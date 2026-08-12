@extends('layouts.app')

@section('content')
    @php
        $formasPagamento = (array) ($form['formas_pagamento'] ?? []);
        $contas = (array) ($form['contas'] ?? []);
        $vendedores = (array) ($form['vendedores'] ?? []);
        $cartoes = (array) ($form['cartoes'] ?? []);
        $usuarioId = (int) ($form['usuario_id'] ?? 0);
        $hoje = (string) ($form['data_hoje'] ?? now()->toDateString());
    @endphp

    <form
        method="post"
        action="{{ route('vendas.store') }}"
        id="pdvForm"
        data-search-url="{{ route('vendas.items.search') }}"
        data-clients-url="{{ route('vendas.clients.search') }}"
        data-cartoes="{{ json_encode($cartoes, JSON_UNESCAPED_UNICODE) }}"
        data-formas="{{ json_encode($formasPagamento, JSON_UNESCAPED_UNICODE) }}"
        data-contas="{{ json_encode($contas, JSON_UNESCAPED_UNICODE) }}"
        data-contas-padrao="{{ json_encode((object) ($form['contas_padrao'] ?? []), JSON_UNESCAPED_UNICODE) }}"
    >
        @csrf

        {{-- Chave de idempotência: gerada uma vez por abertura da tela. É o que
             impede que duplo clique em "Finalizar" vire duas vendas. --}}
        <input type="hidden" name="creation_request_id" id="pdvRequestId" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="confirmar_estoque_insuficiente" id="pdvConfirmarEstoque" value="0">
        <input type="hidden" name="data_venda" value="{{ $hoje }}">

        <div class="row g-3 align-items-start">
            {{-- Coluna esquerda: busca e carrinho --}}
            <div class="col-12 col-xl-8">
                <section class="surface-card">
                    <label for="pdvBusca" class="form-label fw-semibold">
                        <i class="bi bi-upc-scan me-1"></i>
                        Buscar produto ou serviço
                    </label>
                    <input
                        type="text"
                        id="pdvBusca"
                        class="form-control form-control-lg"
                        placeholder="Nome, código (PC00001) ou código de barras"
                        autocomplete="off"
                        autofocus
                    >
                    <div id="pdvResultados" class="list-group mt-2 d-none"></div>
                    <small class="text-secondary d-block mt-2">
                        Enter adiciona o primeiro resultado. F2 finaliza a venda, Esc limpa o carrinho.
                    </small>
                </section>

                <section class="surface-table mt-3">
                    <div class="surface-table-header">
                        <div>
                            <h2 class="surface-title">Itens da venda</h2>
                            <p class="surface-subtitle">Quantidade, preço e desconto podem ser ajustados por linha.</p>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="pdvAdicionarAvulso">
                            <i class="bi bi-plus-lg me-1"></i>Item avulso
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-stack align-middle" id="pdvTabelaItens">
                            <thead>
                            <tr>
                                <th>Item</th>
                                <th style="width: 110px;">Qtd</th>
                                <th style="width: 140px;">Unitário</th>
                                <th style="width: 140px;">Desconto</th>
                                <th class="text-end" style="width: 130px;">Total</th>
                                <th style="width: 48px;"></th>
                            </tr>
                            </thead>
                            <tbody id="pdvItens"></tbody>
                        </table>
                    </div>

                    <div id="pdvSemItens" class="text-center text-secondary py-4">
                        <i class="bi bi-cart3 fs-3 d-block mb-2"></i>
                        Nenhum item no carrinho. Busque um produto acima para começar.
                    </div>
                </section>
            </div>

            {{-- Coluna direita: cliente, ajustes e pagamento --}}
            <div class="col-12 col-xl-4">
                <section class="surface-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-semibold mb-0" for="pdvCliente">Cliente</label>
                        <button type="button" class="btn btn-link btn-sm p-0" id="pdvConsumidorFinal">
                            Consumidor final
                        </button>
                    </div>
                    <select id="pdvCliente" name="cliente_id" class="form-select" data-select2-placeholder="Consumidor final">
                        <option value=""></option>
                    </select>

                    <div class="mt-2" id="pdvBlocoAvulso">
                        <input type="text" name="cliente_nome_avulso" class="form-control form-control-sm mb-2"
                               placeholder="Nome (opcional)" maxlength="160">
                        <input type="text" name="cliente_documento_avulso" class="form-control form-control-sm"
                               placeholder="CPF/CNPJ na nota (opcional)" maxlength="20">
                    </div>

                    <label for="pdvVendedor" class="form-label fw-semibold mt-3">Vendedor</label>
                    <select id="pdvVendedor" name="vendedor_id" class="form-select">
                        @foreach ($vendedores as $vendedor)
                            <option value="{{ $vendedor['id'] ?? '' }}" @selected((int) ($vendedor['id'] ?? 0) === $usuarioId)>
                                {{ $vendedor['nome'] ?? '' }}
                            </option>
                        @endforeach
                    </select>
                </section>

                <section class="surface-card mt-3">
                    <h3 class="surface-title fs-6 mb-3">Desconto geral</h3>
                    <div class="input-group">
                        <input type="text" class="form-control" id="pdvDescontoValor" name="desconto" value="0,00" inputmode="decimal">
                        <input type="text" class="form-control d-none" id="pdvDescontoPercentual" name="desconto_percentual" value="0" inputmode="decimal">
                        <button type="button" class="btn btn-outline-secondary" id="pdvDescontoToggle" data-modo="valor">R$</button>
                    </div>
                    <input type="hidden" name="desconto_tipo" id="pdvDescontoTipo" value="valor">

                    <dl class="row mb-0 mt-3">
                        <dt class="col-6 text-secondary fw-normal">Subtotal</dt>
                        <dd class="col-6 text-end mb-1" id="pdvSubtotal">R$ 0,00</dd>
                        <dt class="col-6 text-secondary fw-normal">Desconto</dt>
                        <dd class="col-6 text-end mb-1" id="pdvDesconto">R$ 0,00</dd>
                        <dt class="col-6 fs-5 fw-semibold">Total</dt>
                        <dd class="col-6 text-end fs-5 fw-semibold" id="pdvTotal">R$ 0,00</dd>
                    </dl>
                </section>

                <section class="surface-card mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h3 class="surface-title fs-6 mb-0">Pagamento</h3>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="pdvAdicionarPagamento">
                            <i class="bi bi-plus-lg me-1"></i>Forma
                        </button>
                    </div>

                    <div id="pdvPagamentos"></div>

                    <dl class="row mb-0 mt-2">
                        <dt class="col-6 text-secondary fw-normal">Recebido</dt>
                        <dd class="col-6 text-end mb-1" id="pdvRecebido">R$ 0,00</dd>
                        <dt class="col-6 text-secondary fw-normal" id="pdvSaldoRotulo">Falta</dt>
                        <dd class="col-6 text-end mb-1 fw-semibold" id="pdvSaldo">R$ 0,00</dd>
                    </dl>

                    <div class="alert alert-success d-none mt-2 mb-0 py-2" id="pdvTrocoBox">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Troco</span>
                            <strong class="fs-4" id="pdvTroco">R$ 0,00</strong>
                        </div>
                    </div>

                    <p class="text-secondary small mt-2 mb-0" id="pdvAvisoFiado">
                        Recebimento menor que o total deixa a venda com saldo em aberto no financeiro.
                    </p>
                </section>

                <section class="surface-card mt-3">
                    <label for="pdvObservacoes" class="form-label">Observações</label>
                    <textarea id="pdvObservacoes" name="observacoes" class="form-control" rows="2" maxlength="2000"></textarea>

                    <button type="submit" class="btn btn-primary btn-lg w-100 mt-3" id="pdvFinalizar" disabled>
                        <i class="bi bi-check2-circle me-2"></i>
                        Finalizar venda (F2)
                    </button>
                </section>
            </div>
        </div>

        {{-- Modelos de linha clonados pelo JS. Ficam fora de <tbody> para o
             navegador não tentar renderizá-los como parte da tabela. --}}
        <template id="pdvModeloItem">
            <tr class="pdv-item">
                <td data-label="Item">
                    <input type="hidden" data-campo="tipo_item">
                    <input type="hidden" data-campo="referencia_id">
                    <input type="hidden" data-campo="baixa_estoque" value="1">
                    <div class="fw-semibold pdv-item-descricao"></div>
                    <input type="text" class="form-control form-control-sm mt-1 d-none" data-campo="descricao" maxlength="255" placeholder="Descrição do item">
                    <small class="text-secondary d-block pdv-item-meta"></small>
                </td>
                <td data-label="Qtd">
                    <input type="text" class="form-control form-control-sm text-center" data-campo="quantidade" value="1" inputmode="decimal">
                </td>
                <td data-label="Unitário">
                    <input type="text" class="form-control form-control-sm text-end" data-campo="valor_unitario" value="0,00" inputmode="decimal">
                </td>
                <td data-label="Desconto">
                    <input type="text" class="form-control form-control-sm text-end" data-campo="desconto" value="0,00" inputmode="decimal">
                </td>
                <td data-label="Total" class="text-end fw-semibold pdv-item-total">R$ 0,00</td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger pdv-remover-item" aria-label="Remover item">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        </template>

        <template id="pdvModeloPagamento">
            <div class="pdv-pagamento border rounded p-2 mb-2">
                <div class="d-flex gap-2 align-items-start">
                    <select class="form-select form-select-sm" data-campo="forma_pagamento"></select>
                    <input type="text" class="form-control form-control-sm text-end" data-campo="valor" value="0,00" inputmode="decimal" style="max-width: 120px;">
                    <button type="button" class="btn btn-sm btn-outline-danger pdv-remover-pagamento" aria-label="Remover pagamento">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="mt-2 d-none pdv-bloco-dinheiro">
                    <label class="form-label form-label-sm mb-1">Valor recebido</label>
                    <input type="text" class="form-control form-control-sm text-end" data-campo="valor_recebido" inputmode="decimal">
                </div>

                <div class="mt-2 d-none pdv-bloco-cartao">
                    <div class="row g-2">
                        <div class="col-6">
                            <select class="form-select form-select-sm" data-campo="operadora_id"></select>
                        </div>
                        <div class="col-6">
                            <select class="form-select form-select-sm" data-campo="bandeira_id"></select>
                        </div>
                        <div class="col-6">
                            <select class="form-select form-select-sm" data-campo="modalidade">
                                <option value="credito">Crédito</option>
                                <option value="debito">Débito</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <select class="form-select form-select-sm" data-campo="parcelas"></select>
                        </div>
                    </div>
                    <small class="text-secondary d-block mt-1 pdv-taxa-estimada"></small>
                </div>

                <div class="mt-2">
                    <select class="form-select form-select-sm" data-campo="conta_financeira_id" required>
                        <option value="">Selecione a conta que recebe</option>
                        @foreach ($contas as $conta)
                            <option value="{{ $conta['id'] ?? '' }}">{{ $conta['nome'] ?? '' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </template>
    </form>
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/pagamentos-cartao.js') }}?v={{ filemtime(public_path('assets/js/pagamentos-cartao.js')) }}-{{ filesize(public_path('assets/js/pagamentos-cartao.js')) }}"></script>
    <script src="{{ asset('assets/js/vendas-pdv.js') }}?v={{ filemtime(public_path('assets/js/vendas-pdv.js')) }}-{{ filesize(public_path('assets/js/vendas-pdv.js')) }}"></script>
@endsection
