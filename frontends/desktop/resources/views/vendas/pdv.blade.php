@extends('layouts.app')

@section('styles')
    {{-- PDV é tela de operação: cabe inteira na janela, sem rolagem de página.
         Só o carrinho e a coluna de fechamento rolam por dentro. A sidebar
         entra recolhida (ver $desktopSidebarHidden em layouts/app.blade.php). --}}
    <style>
        /* A altura mora na própria grade, não no .desktop-content: assim o
           layout não depende de :has(), que nem todo navegador do balcão
           suporta. O desconto cobre topbar + padding do conteúdo + rodapé. */
        .pdv-grid {
            display: grid;
            /* Esquerda e direita com largura contida; o carrinho no centro fica
               com todo o resto, porque é a coluna que cresce durante a venda. */
            grid-template-columns:
                clamp(17rem, 24vw, 22rem)
                minmax(0, 1fr)
                clamp(18rem, 25vw, 23rem);
            gap: .75rem;
            height: calc(100vh - var(--desktop-topbar-height) - 6rem);
            min-height: 26rem;
        }

        .pdv-col-esquerda,
        .pdv-col-centro,
        .pdv-col-lateral {
            display: flex;
            flex-direction: column;
            gap: .75rem;
            min-height: 0;
        }

        /* Válvula de segurança: em janela baixa as colunas laterais rolam por
           dentro em vez de estourar a tela. O centro nunca rola inteiro — quem
           rola lá é só a lista de itens.
           IMPORTANTE: overflow aqui não pode voltar a existir sem cuidado — foi
           isso que escondia os resultados da busca (ver comentário em
           .pdv-resultados). Por isso o dropdown usa position: fixed, imune a
           este overflow. */
        .pdv-col-esquerda,
        .pdv-col-lateral {
            overflow-y: auto;
        }

        .pdv-bloco-cliente,
        .pdv-bloco-busca,
        .pdv-bloco-desconto,
        .pdv-bloco-fechar {
            flex: 0 0 auto;
            padding: .75rem .9rem;
        }

        .pdv-bloco-busca .form-label {
            margin-bottom: .25rem;
        }

        .pdv-bloco-busca small {
            font-size: .75rem;
            line-height: 1.3;
        }

        /* Um campo por linha, com respiro entre eles. */
        .pdv-campo {
            margin-bottom: .6rem;
        }

        .pdv-campo-ultimo {
            margin-bottom: 0;
        }

        .pdv-campo .form-label {
            margin-bottom: .2rem;
            font-size: .82rem;
            color: var(--bs-secondary-color, #6c757d);
        }

        .pdv-campo .form-label.fw-semibold {
            color: inherit;
            font-size: .875rem;
        }

        /* Rótulo e atalho "Consumidor final" na mesma linha, sem quebrar. */
        .pdv-campo-titulo {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: .5rem;
            margin-bottom: .2rem;
        }

        .pdv-campo-titulo .btn-link {
            font-size: .8rem;
            white-space: nowrap;
        }

        /* O carrinho ocupa toda a altura que sobrar. */
        .pdv-bloco-itens {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        .pdv-bloco-itens .surface-table-header {
            padding-bottom: .5rem;
        }

        .pdv-itens-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
        }

        /* Cabeçalho do carrinho fixo enquanto as linhas rolam. */
        .pdv-itens-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--desktop-surface, #fff);
        }

        .pdv-bloco-busca {
            position: relative;
        }

        /* position: fixed de propósito, não absolute. A coluna esquerda tem
           overflow-y: auto (válvula de segurança acima), e um "position:
           absolute" dentro de um ancestral com overflow fica CORTADO por ele
           — foi exatamente isso que escondia a lista de resultados. Fixed usa
           o viewport como referência e ignora o overflow dos pais; a posição é
           calculada em JS (posicionarResultados) porque, sem ancestral
           posicionado relevante, não dá para usar apenas CSS aqui. Também
           transborda a largura da coluna de propósito: o resultado traz nome,
           código, saldo e preço, e não caberia legível em ~18rem. */
        .pdv-resultados {
            position: fixed;
            width: min(34rem, 52vw);
            z-index: 1080;
            /* Fallback antes do JS calcular: top/bottom/max-height reais vêm
               inline de posicionarResultados(), que escolhe abrir para cima
               ou para baixo conforme o espaço livre na janela (campo perto do
               rodapé numa tela baixa não pode empurrar a caixa para fora da
               viewport). */
            max-height: 20rem;
            overflow-y: auto;
            box-shadow: 0 .75rem 1.5rem rgba(15, 55, 100, .18);
        }

        .pdv-pagamento-topo {
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .pdv-pagamento-topo .form-select {
            flex: 1 1 auto;
            min-width: 0;
        }

        .pdv-pagamento .pdv-campo {
            margin-bottom: .45rem;
        }

        /* ------------------------------------------------------------------
           Modo terminal (F3, botão "Tela cheia" ou entrada automática ao
           abrir/recarregar o PDV): esconde topbar, sidebar e rodapé. A classe
           no <body> é a fonte da verdade — a Fullscreen API real do
           navegador (sem barra alguma) é só um complemento de melhor
           esforço, aplicado por cima quando há gesto do usuário.
           ------------------------------------------------------------------ */
        .pdv-modo-terminal .desktop-topbar,
        .pdv-modo-terminal .desktop-system-footer,
        .pdv-modo-terminal .desktop-sidebar,
        .pdv-modo-terminal .desktop-sidebar-overlay {
            display: none !important;
        }

        .pdv-modo-terminal .desktop-main {
            margin-left: 0 !important;
        }

        .pdv-modo-terminal .desktop-content {
            padding: .6rem;
        }

        /* Cabeçalho de marca: só existe em modo terminal — fora dele a topbar
           do sistema já mostra a marca, duplicar seria redundante. */
        .pdv-terminal-header {
            display: none;
            align-items: center;
            gap: .75rem;
            padding: .35rem .5rem .75rem;
            flex: 0 0 auto;
        }

        .pdv-modo-terminal .pdv-terminal-header {
            display: flex;
        }

        .pdv-terminal-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.75rem;
            height: 2.75rem;
            border-radius: .6rem;
            background: var(--desktop-surface, #fff);
            box-shadow: 0 .2rem .5rem rgba(15, 55, 100, .12);
            flex: 0 0 auto;
            font-size: 1.4rem;
            color: var(--bs-primary, #0d6efd);
        }

        .pdv-terminal-logo.has-logo {
            background: #fff;
            padding: .3rem;
        }

        .pdv-terminal-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .pdv-terminal-empresa {
            font-size: 1.25rem;
            font-weight: 600;
            letter-spacing: .01em;
        }

        .pdv-modo-terminal .pdv-grid {
            /* Sem topbar nem rodapé: sobra a janela inteira menos o padding e
               a faixa do cabeçalho de marca. */
            height: calc(100vh - 1.2rem - 3.9rem);
        }

        /* Calendário + relógio: fixados no rodapé da coluna de fechamento,
           abaixo do botão Finalizar venda, só em modo terminal. margin-top:
           auto empurra o bloco para o fim mesmo com espaço sobrando. */
        .pdv-terminal-agenda {
            display: none;
            margin-top: auto;
            flex: 0 0 auto;
            text-align: center;
        }

        .pdv-modo-terminal .pdv-terminal-agenda {
            display: block;
        }

        .pdv-terminal-calendario {
            width: 100%;
            border-collapse: collapse;
            font-size: .78rem;
        }

        .pdv-terminal-calendario caption {
            caption-side: top;
            font-weight: 600;
            font-size: .85rem;
            padding-bottom: .35rem;
            text-transform: capitalize;
        }

        .pdv-terminal-calendario th,
        .pdv-terminal-calendario td {
            width: 14.28%;
            text-align: center;
            padding: .15rem 0;
            color: var(--bs-secondary-color, #6c757d);
        }

        .pdv-terminal-calendario th {
            font-weight: 500;
            font-size: .68rem;
        }

        /* Círculo de verdade: aplicado num span de tamanho fixo dentro da
           célula, não na célula em si (que é retangular e viraria elipse). */
        .pdv-terminal-calendario td span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.4rem;
            height: 1.4rem;
        }

        .pdv-terminal-calendario td.pdv-dia-atual span {
            color: #fff;
            background: var(--bs-primary, #0d6efd);
            border-radius: 50%;
            font-weight: 600;
        }

        .pdv-terminal-relogio {
            margin-top: .5rem;
            font-size: 1.6rem;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            letter-spacing: .04em;
        }

        /* Em terminal o operador está de longe: fontes e alvos maiores. */
        .pdv-modo-terminal #pdvBusca {
            font-size: 1.35rem;
            padding: .75rem 1rem;
        }

        .pdv-modo-terminal .pdv-bloco-itens .table {
            font-size: 1.05rem;
        }

        .pdv-modo-terminal #pdvTotal {
            font-size: 1.6rem;
        }

        .pdv-modo-terminal #pdvAbrirPagamento {
            padding: .85rem 1rem;
            font-size: 1.15rem;
        }

        .pdv-terminal-sair {
            display: none;
        }

        .pdv-modo-terminal .pdv-terminal-sair {
            display: inline-flex;
        }

        .pdv-modo-terminal .pdv-terminal-entrar {
            display: none;
        }

        /* Abaixo de 1200px as três colunas não cabem sem espremer o carrinho,
           então a página empilha e volta a rolar. Em 1366px — a largura mais
           comum aqui — as três entram com folga. */
        @media (max-width: 1199.98px) {
            .pdv-grid {
                grid-template-columns: minmax(0, 1fr);
                height: auto;
            }

            .pdv-col-esquerda,
            .pdv-col-lateral,
            .pdv-itens-scroll {
                max-height: none;
                overflow-y: visible;
            }
        }

        /* A largura fixa do dropdown só cabe com folga a partir daqui; abaixo
           disso ele ocupa a largura livre calculada em JS. */
        @media (max-width: 767.98px) {
            .pdv-resultados {
                width: auto;
            }
        }
    </style>
@endsection

@section('content')
    {{-- F2/F3/F4 já têm dono aqui (confirmar venda, tela cheia, abrir cliente,
         em vendas-pdv.js), então os atalhos globais do "+ Novo" cedem essas três
         — e o F3 de "Nova venda" seria mesmo redundante onde a venda está sendo
         feita. F1 fica de fora da lista de propósito: abrir uma OS a partir do
         balcão é caso real, e o PDV não usa essa tecla. --}}
    <div data-desktop-fkeys-owner="F2 F3 F4" hidden aria-hidden="true"></div>

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
        data-quick-client-store-url="{{ route('clients.quick.store') }}"
        data-quick-client-show-url-template="{{ route('clients.quick.show', ['client' => '__CLIENT_ID__']) }}"
        data-quick-client-update-url-template="{{ route('clients.quick.update', ['client' => '__CLIENT_ID__']) }}"
        data-client-full-edit-url-template="{{ route('clients.edit', ['client' => '__CLIENT_ID__']) }}"
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

        {{-- Só aparece em tela cheia (F3): fora dela a topbar do sistema já
             mostra a marca, então duplicar aqui seria redundante. Em tela
             cheia a topbar some, e "a tela é o PDV da loja" pede uma
             identidade visual própria. Mesma fonte da sidebar
             ($desktopCompanyBranding, injetado globalmente em
             DesktopAppServiceProvider). --}}
        <header class="pdv-terminal-header" aria-hidden="true">
            <span class="pdv-terminal-logo {{ ($desktopCompanyBranding['has_logo'] ?? false) ? 'has-logo' : '' }}">
                @if ($desktopCompanyBranding['has_logo'] ?? false)
                    <img src="{{ route('configurations.company.logo') }}" alt="Logo da empresa">
                @else
                    <i class="bi bi-houses-fill"></i>
                @endif
            </span>
            <span class="pdv-terminal-empresa">{{ $desktopCompanyBranding['name'] ?? 'Sistema ERP' }}</span>
        </header>

        <div class="pdv-grid">
            {{-- Coluna 1: quem está comprando. --}}
            <div class="pdv-col-esquerda">
                {{-- Empilhado de propósito: as classes col-lg-* do Bootstrap
                     respondem à largura da JANELA, não da coluna, e espremiam
                     os três campos lado a lado dentro de ~290px. --}}
                <section class="surface-card pdv-bloco-cliente">
                    <div class="pdv-campo">
                        <div class="pdv-campo-titulo">
                            <label class="form-label fw-semibold mb-0" for="pdvCliente">Cliente</label>
                            <button type="button" class="btn btn-link btn-sm p-0" id="pdvConsumidorFinal">
                                Consumidor final
                            </button>
                        </div>
                        <div class="d-flex gap-2 align-items-start">
                            <select id="pdvCliente" name="cliente_id" class="form-select" data-select2-placeholder="Consumidor final">
                                <option value=""></option>
                            </select>
                            @if ($canQuickClient)
                                <button type="button" class="btn btn-soft flex-shrink-0" id="pdvNovoCliente"
                                        title="Cadastrar novo cliente" aria-label="Cadastrar novo cliente">
                                    <i class="bi bi-person-plus"></i>
                                </button>
                            @endif
                            @if ($canEditClient)
                                <button type="button" class="btn btn-soft flex-shrink-0 d-none" id="pdvEditarCliente"
                                        title="Editar cliente" aria-label="Editar cliente">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Nome e CPF só fazem sentido em consumidor final; o JS
                         esconde o bloco quando um cliente é escolhido. --}}
                    <div id="pdvBlocoAvulso">
                        <div class="pdv-campo">
                            <label class="form-label" for="pdvClienteNome">Nome</label>
                            <input type="text" id="pdvClienteNome" name="cliente_nome_avulso" class="form-control"
                                   placeholder="Opcional" maxlength="160">
                        </div>

                        <div class="pdv-campo">
                            <label class="form-label" for="pdvClienteDocumento">CPF/CNPJ na nota</label>
                            <input type="text" id="pdvClienteDocumento" name="cliente_documento_avulso" class="form-control"
                                   placeholder="Opcional" maxlength="20">
                        </div>
                    </div>

                    <div class="pdv-campo pdv-campo-ultimo">
                        <label for="pdvVendedor" class="form-label fw-semibold">Vendedor</label>
                        <select id="pdvVendedor" name="vendedor_id" class="form-select">
                            @foreach ($vendedores as $vendedor)
                                <option value="{{ $vendedor['id'] ?? '' }}" @selected((int) ($vendedor['id'] ?? 0) === $usuarioId)>
                                    {{ $vendedor['nome'] ?? '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </section>

            </div>

            {{-- Coluna 2: busca + carrinho. Fica no centro porque é onde o
                 operador olha o tempo todo — busca o item, vê ele cair na
                 lista logo abaixo, sem trocar de coluna. --}}
            <div class="pdv-col-centro">
                <section class="surface-card pdv-bloco-busca">
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
                    {{-- position: fixed calculado em JS: a lista flutua sobre
                         o carrinho em vez de empurrar a página e criar
                         rolagem. --}}
                    <div id="pdvResultados" class="list-group pdv-resultados d-none"></div>
                    <small class="text-secondary d-block mt-2">
                        Enter adiciona · F2 finaliza · F3 tela cheia · Esc limpa
                    </small>
                </section>

                <section class="surface-table pdv-bloco-itens">
                    <div class="surface-table-header">
                        <div>
                            <h2 class="surface-title">Itens da venda</h2>
                            <p class="surface-subtitle">Quantidade, preço e desconto podem ser ajustados por linha.</p>
                        </div>
                        <div class="d-flex gap-2">
                            {{-- Tela cheia também por clique: em tablet do balcão
                                 não há F3, e a Fullscreen API exige um gesto. --}}
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="pdvTelaCheia"
                                    title="Tela cheia (F3)">
                                <span class="pdv-terminal-entrar">
                                    <i class="bi bi-arrows-fullscreen me-1"></i>Tela cheia
                                </span>
                                <span class="pdv-terminal-sair">
                                    <i class="bi bi-fullscreen-exit me-1"></i>Sair
                                </span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="pdvAdicionarAvulso">
                                <i class="bi bi-plus-lg me-1"></i>Item avulso
                            </button>
                        </div>
                    </div>

                    {{-- Só o carrinho rola: o resto da tela fica fixo. --}}
                    <div class="table-responsive pdv-itens-scroll">
                        <table class="table table-stack align-middle" id="pdvTabelaItens">
                            <thead>
                            <tr>
                                <th>Item</th>
                                {{-- Colunas fixas enxutas: no centro sobra menos
                                     largura que na versão de duas colunas, e a
                                     descrição do item é o que precisa de espaço. --}}
                                <th style="width: 78px;">Qtd</th>
                                <th style="width: 108px;">Unitário</th>
                                <th style="width: 108px;">Desconto</th>
                                <th class="text-end" style="width: 108px;">Total</th>
                                <th style="width: 40px;"></th>
                            </tr>
                            </thead>
                            <tbody id="pdvItens"></tbody>
                        </table>

                        <div id="pdvSemItens" class="text-center text-secondary py-4">
                            <i class="bi bi-cart3 fs-3 d-block mb-2"></i>
                            Nenhum item no carrinho. Busque um produto acima para começar.
                        </div>
                    </div>
                </section>
            </div>

            {{-- Coluna 3: ajustes da venda e o gatilho de finalização. O
                 pagamento em si só aparece dentro do modal — não faz sentido
                 pedir forma de pagamento antes de saber o total fechado. --}}
            <div class="pdv-col-lateral">
                <section class="surface-card pdv-bloco-desconto">
                    <h3 class="surface-title fs-6 mb-2">Desconto geral</h3>
                    <div class="input-group">
                        <input type="text" class="form-control" id="pdvDescontoValor" name="desconto" value="0,00" inputmode="decimal">
                        <input type="text" class="form-control d-none" id="pdvDescontoPercentual" name="desconto_percentual" value="0" inputmode="decimal">
                        <button type="button" class="btn btn-outline-secondary" id="pdvDescontoToggle" data-modo="valor">R$</button>
                    </div>
                    <input type="hidden" name="desconto_tipo" id="pdvDescontoTipo" value="valor">

                    <dl class="row mb-0 mt-2">
                        <dt class="col-6 text-secondary fw-normal">Subtotal</dt>
                        <dd class="col-6 text-end mb-1" id="pdvSubtotal">R$ 0,00</dd>
                        <dt class="col-6 text-secondary fw-normal">Desconto</dt>
                        <dd class="col-6 text-end mb-1" id="pdvDesconto">R$ 0,00</dd>
                        <dt class="col-6 fs-5 fw-semibold mb-0">Total</dt>
                        <dd class="col-6 text-end fs-5 fw-semibold mb-0" id="pdvTotal">R$ 0,00</dd>
                        {{-- Só para quem tem permissão financeira: o backend nem envia
                             o custo para os demais (specs/037). --}}
                        <dt class="col-6 text-secondary fw-normal d-none" id="pdvCustoLabel">Custo</dt>
                        <dd class="col-6 text-end mb-0 d-none" id="pdvCustoTotal">R$ 0,00</dd>
                        <dt class="col-6 text-secondary fw-normal d-none" id="pdvMargemLabel">Margem</dt>
                        <dd class="col-6 text-end mb-0 d-none" id="pdvMargemTotal">R$ 0,00</dd>
                    </dl>

                    {{-- Aviso de piso: aparece quando o desconto empurra alguma linha
                         abaixo do custo. NUNCA bloqueia a venda — PDV que se recusa a
                         vender vira recibo manual. --}}
                    <div class="alert alert-warning py-2 px-3 mt-2 mb-0 d-none" id="pdvAvisoPiso"></div>
                </section>

                <section class="surface-card pdv-bloco-fechar">
                    <label for="pdvObservacoes" class="form-label mb-1">Observações</label>
                    <textarea id="pdvObservacoes" name="observacoes" class="form-control" rows="2" maxlength="2000"></textarea>

                    {{-- Não é o submit: abre o modal de pagamento. O envio real
                         acontece no botão dentro do modal. --}}
                    <button type="button" class="btn btn-primary btn-lg w-100 mt-2" id="pdvAbrirPagamento"
                            data-bs-toggle="modal" data-bs-target="#pdvPagamentoModal" disabled>
                        <i class="bi bi-check2-circle me-2"></i>
                        Finalizar venda (F2)
                    </button>
                </section>

                {{-- Só em modo terminal (F3), abaixo do botão Finalizar.
                     Gerado e atualizado via JS (não no Blade) para não ficar
                     desatualizado se o terminal ficar aberto virando o dia. --}}
                <div class="pdv-terminal-agenda" aria-hidden="true">
                    <table class="pdv-terminal-calendario" id="pdvTerminalCalendario">
                        <caption></caption>
                        <thead>
                        <tr>
                            <th>D</th><th>S</th><th>T</th><th>Q</th><th>Q</th><th>S</th><th>S</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div class="pdv-terminal-relogio" id="pdvTerminalRelogio">--:--:--</div>
                </div>
            </div>
        </div>

        {{-- Pagamento só entra em cena aqui: o operador já viu o total fechado
             (desconto incluso) antes de escolher como vai receber. --}}
        <div class="modal fade" id="pdvPagamentoModal" tabindex="-1" aria-labelledby="pdvPagamentoModalLabel"
             aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="pdvPagamentoModalLabel">Pagamento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <span class="text-secondary">Total da venda</span>
                            <strong class="fs-4" id="pdvPagamentoTotal">R$ 0,00</strong>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="surface-title fs-6 mb-0">Formas de pagamento</h3>
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
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                        {{-- Este é o submit de verdade: o form inteiro (cliente,
                             itens, desconto, observações) já está pronto; só
                             faltava a forma de pagamento. --}}
                        <button type="submit" class="btn btn-primary" id="pdvConfirmarFinalizar">
                            <i class="bi bi-check2-circle me-2"></i>Finalizar venda
                        </button>
                    </div>
                </div>
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
                <td data-label="Total" class="text-end fw-semibold">
                    <span class="pdv-item-total">R$ 0,00</span>
                    {{-- Margem da linha: entra DENTRO da celula de total, nao como
                         coluna nova — o PDV roda em tela cheia e sete colunas nao cabem. --}}
                    <small class="d-block fw-normal pdv-item-margem"></small>
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger pdv-remover-item" aria-label="Remover item">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        </template>

        <template id="pdvModeloPagamento">
            {{-- Um campo por linha, com rótulo. As classes col-* do Bootstrap
                 respondem à largura da JANELA, não da coluna, e espremiam
                 operadora/bandeira/modalidade/parcelas em ~130px cada. --}}
            <div class="pdv-pagamento border rounded p-2 mb-2">
                <div class="pdv-pagamento-topo">
                    <select class="form-select form-select-sm" data-campo="forma_pagamento" aria-label="Forma de pagamento"></select>
                    <button type="button" class="btn btn-sm btn-outline-danger pdv-remover-pagamento" aria-label="Remover pagamento">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="pdv-campo mt-2">
                    <label class="form-label">Valor</label>
                    <input type="text" class="form-control form-control-sm text-end" data-campo="valor" value="0,00" inputmode="decimal">
                </div>

                <div class="pdv-campo mt-2 d-none pdv-bloco-dinheiro">
                    <label class="form-label">Valor recebido</label>
                    <input type="text" class="form-control form-control-sm text-end" data-campo="valor_recebido" inputmode="decimal">
                </div>

                <div class="mt-2 d-none pdv-bloco-cartao">
                    <div class="pdv-campo">
                        <label class="form-label">Operadora</label>
                        <select class="form-select form-select-sm" data-campo="operadora_id"></select>
                    </div>
                    <div class="pdv-campo">
                        <label class="form-label">Bandeira</label>
                        <select class="form-select form-select-sm" data-campo="bandeira_id"></select>
                    </div>
                    <div class="pdv-campo">
                        <label class="form-label">Modalidade</label>
                        <select class="form-select form-select-sm" data-campo="modalidade">
                            <option value="credito">Crédito</option>
                            <option value="debito">Débito</option>
                        </select>
                    </div>
                    <div class="pdv-campo pdv-campo-ultimo">
                        <label class="form-label">Parcelas</label>
                        <select class="form-select form-select-sm" data-campo="parcelas"></select>
                    </div>
                    <small class="text-secondary d-block mt-1 pdv-taxa-estimada"></small>
                </div>

                <div class="pdv-campo pdv-campo-ultimo mt-2">
                    <label class="form-label">Conta que recebe</label>
                    <select class="form-select form-select-sm" data-campo="conta_financeira_id" required>
                        <option value="">Selecione a conta</option>
                        @foreach ($contas as $conta)
                            <option value="{{ $conta['id'] ?? '' }}">{{ $conta['nome'] ?? '' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </template>
    </form>

    @if ($canQuickClient)
        @push('modals')
            @include('clients.quick-modal')
        @endpush
    @endif
    @if ($canEditClient)
        @push('modals')
            @include('clients.quick-edit-modal')
        @endpush
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/pagamentos-cartao.js') }}?v={{ filemtime(public_path('assets/js/pagamentos-cartao.js')) }}-{{ filesize(public_path('assets/js/pagamentos-cartao.js')) }}"></script>
    <script src="{{ asset('assets/js/vendas-pdv.js') }}?v={{ filemtime(public_path('assets/js/vendas-pdv.js')) }}-{{ filesize(public_path('assets/js/vendas-pdv.js')) }}"></script>
    @if ($canQuickClient || $canEditClient)
        <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
    @endif
@endsection
