@php
    $estoqueGrupos = is_array($estoqueGrupos ?? null) ? $estoqueGrupos : [];
    $estoqueCategorias = is_array($estoqueCategorias ?? null) ? $estoqueCategorias : [];
    $estoqueSubcategorias = is_array($estoqueSubcategorias ?? null) ? $estoqueSubcategorias : [];
@endphp

{{--
    Cadastro rápido de peça dentro do lançamento — specs/039.

    Reusa `estoque.quick.store`, a mesma rota que o orçamento usa. Enxuto de
    propósito: o que falta aqui se completa depois em Estoque > Editar peça.

    ⚠️ ARMADILHA: `quantidade_atual` é ZERO e não é editável.

    `estoque.quick.store` cai em `EstoqueController::store()`, que grava
    `quantidade_atual` DIRETO, sem gerar movimentação (um dos três "furos" da
    036). A quantidade comprada entra pela movimentação de entrada que o
    lançamento gera ao salvar. Se este modal também mandasse a quantidade, o
    saldo contaria DUAS VEZES — em silêncio, sem erro nenhum.
--}}
<div class="modal fade" id="financeiroPecaQuickModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cadastrar peça</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none py-2" data-quick-peca="erro"></div>

                <div class="mb-3">
                    <label class="form-label" for="quickPecaNome">Nome *</label>
                    <input type="text" class="form-control" id="quickPecaNome" data-quick-peca="nome" maxlength="160">
                </div>

                {{--
                    Taxonomia de estoque (Grupo → Categoria → Subcategoria),
                    obrigatória — decisão do cliente de manter a classificação
                    completa mesmo no cadastro rápido. Em cascata via
                    DesktopUi.bindOptionCascade, ver financeiro-entrada-estoque.js.
                --}}
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label" for="quickPecaGrupo">Grupo *</label>
                        <select class="form-select" id="quickPecaGrupo" data-quick-peca="tipo_equipamento_id">
                            <option value=""></option>
                            @foreach ($estoqueGrupos as $grupo)
                                <option value="{{ $grupo['id'] }}">{{ $grupo['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="quickPecaCategoria">Categoria *</label>
                        <select class="form-select" id="quickPecaCategoria" data-quick-peca="estoque_categoria_id" data-taxonomy-parent="quickPecaGrupo">
                            <option value=""></option>
                            @foreach ($estoqueCategorias as $categoria)
                                <option value="{{ $categoria['id'] }}" data-parent-id="{{ $categoria['tipo_equipamento_id'] }}">{{ $categoria['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="quickPecaSubcategoria">Subcategoria *</label>
                        <select class="form-select" id="quickPecaSubcategoria" data-quick-peca="estoque_subcategoria_id" data-taxonomy-parent="quickPecaCategoria">
                            <option value=""></option>
                            @foreach ($estoqueSubcategorias as $subcategoria)
                                <option value="{{ $subcategoria['id'] }}" data-parent-id="{{ $subcategoria['categoria_id'] }}">{{ $subcategoria['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="quickPecaCodigo">Código</label>
                        <input type="text" class="form-control" id="quickPecaCodigo" data-quick-peca="codigo" maxlength="120"
                               placeholder="Será sugerido se ficar em branco">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="quickPecaUnidade">Unidade</label>
                        <input type="text" class="form-control" id="quickPecaUnidade" data-quick-peca="unidade" maxlength="6" value="UN">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="quickPecaCusto">Custo</label>
                        <input type="text" class="form-control" id="quickPecaCusto" data-quick-peca="preco_custo"
                               inputmode="numeric" placeholder="R$ 0,00">
                    </div>
                </div>

                <small class="text-muted d-block mt-3">
                    A quantidade comprada entra pelo próprio lançamento — a peça nasce com saldo zero.
                </small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" data-quick-peca="salvar">Cadastrar e usar</button>
            </div>
        </div>
    </div>
</div>
