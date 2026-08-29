{{--
    Entrada no estoque a partir do lançamento — specs/039.

    O dono resumiu assim: "lançamento é gasto variável, e gasto variável de peça
    é entrada no estoque". Antes desta seção o operador registrava a despesa
    aqui e teria de ir ao Estoque lançar a entrada à mão, redigitando tudo — o
    mesmo padrão que na 038 deixou o CMV zerado em 2.187 OS.

    Só renderiza na criação (ver form.blade.php) e só para quem tem
    `estoque:visualizar` + `estoque:editar`.

    E só aparece na CATEGORIA DE COMPRA DE PEÇA. Antes bastava ser "a pagar", e
    a seção acompanhava despesas que nunca dão entrada em estoque — imposto,
    aluguel, folha —, poluindo a tela justamente no caminho mais comum.
--}}
@php
    $entradaLigada = $entradaLigada ?? false;
    $canQuickPeca = $canQuickPeca ?? false;
    $itensAntigos = $itensAntigos ?? [];
    // Mesmo critério de $isPecaCategoria (grupo DRE), não o nome da categoria:
    // hoje só "Compra de peças" está nesse grupo, mas o catálogo de DRE já
    // prevê "Compra emergencial de peças", e o grupo cobre as duas sem que
    // ninguém precise lembrar de mexer aqui.
    $mostraSecaoEstoque = $tipo === 'pagar' && $isPecaCategoria;
@endphp

{{-- Um `class` só: `class="..."` junto de `@class([...])` emite DOIS atributos
     e o navegador honra o primeiro — o `d-none` era ignorado e só o JS escondia
     a seção. --}}
<div id="financeiroEntradaEstoqueSection" @class(['desktop-form-section', 'd-none' => ! $mostraSecaoEstoque])>
    <div class="desktop-form-section-title">
        <i class="bi bi-box-seam"></i>
        <span>ENTRADA NO ESTOQUE</span>
    </div>

    <div class="form-check form-switch mb-3">
        <input type="hidden" name="entrada_estoque" value="0">
        <input
            type="checkbox"
            id="financeiroEntradaEstoque"
            name="entrada_estoque"
            class="form-check-input"
            value="1"
            @checked($entradaLigada)
        >
        <label class="form-check-label fw-semibold" for="financeiroEntradaEstoque">
            Esta compra dá entrada no estoque
        </label>
        <small class="text-muted d-block">
            As peças abaixo entram no estoque quando o lançamento for salvo. O custo informado
            atualiza o custo de cadastro da peça.
        </small>
    </div>

    <div id="financeiroEntradaEstoqueCorpo" @class(['d-none' => ! $entradaLigada])>
        @error('itens_estoque')
            <div class="alert alert-danger py-2">{{ $message }}</div>
        @enderror

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-2" id="financeiroEntradaEstoqueTabela">
                <thead>
                    <tr>
                        <th style="min-width: 260px;">Peça *</th>
                        <th style="width: 110px;">Qtd. *</th>
                        <th style="width: 150px;">Custo unit.</th>
                        <th style="width: 130px;">Total</th>
                        <th style="width: 60px;"></th>
                    </tr>
                </thead>
                <tbody id="financeiroEntradaEstoqueLinhas">
                    @foreach ($itensAntigos as $i => $item)
                        @include('financeiro.partials.entrada-estoque-item-row', [
                            'index' => $i,
                            'item' => $item,
                        ])
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <button type="button" class="btn btn-sm btn-outline-primary" id="financeiroEntradaEstoqueAdicionar">
                <i class="bi bi-plus-lg"></i> Adicionar peça
            </button>
            @if ($canQuickPeca)
                <button type="button" class="btn btn-sm btn-outline-secondary" id="financeiroEntradaEstoqueNovaPeca">
                    <i class="bi bi-box"></i> Cadastrar peça nova
                </button>
            @endif
        </div>

        {{--
            Rodapé vivo: a soma dos itens confrontada com o valor do lançamento.
            Soma MENOR é normal (frete, imposto, item que não é peça) e só avisa.
            Soma MAIOR é digitação errada — comprou mais do que pagou — e o
            servidor recusa (UpsertFinanceiroRequest::withValidator).
        --}}
        <div class="small" id="financeiroEntradaEstoqueResumo" aria-live="polite">
            <span class="text-muted">Itens:</span>
            <strong data-resumo="itens">R$ 0,00</strong>
            <span class="text-muted ms-2">Lançamento:</span>
            <strong data-resumo="lancamento">R$ 0,00</strong>
            <span class="ms-2" data-resumo="diferenca"></span>
        </div>
    </div>
</div>

{{-- Linha-template clonada pelo JS. `__INDEX__` vira o índice real no clone. --}}
<template id="financeiroEntradaEstoqueTemplate">
    @include('financeiro.partials.entrada-estoque-item-row', [
        'index' => '__INDEX__',
        'item' => [],
    ])
</template>
