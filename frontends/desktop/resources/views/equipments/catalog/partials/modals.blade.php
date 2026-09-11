@php
    $types = is_array($types ?? null) ? $types : [];
    $allBrands = is_array($allBrands ?? null) ? $allBrands : [];
@endphp

{{--
    Três modais simples (novo/renomear) do catálogo de equipamentos — mesma
    ideia de "um form serve para criar e editar" de
    estoque/partials/categorias-modal.blade.php, só que cada um é seu próprio
    modal (a tela não tem um card fixo para hospedar o form como o modal de
    categorias tem). "Ativar/Desativar" é ação separada nas tabelas das abas,
    não faz parte destes modais. Nunca há exclusão — ver EquipmentCatalogController.
--}}

<div class="modal fade" id="catalogModeloModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <h5 class="modal-title" id="catalogModeloModalTitle">Novo modelo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="post" action="{{ route('equipments.catalog.models.save') }}" id="catalogModeloForm">
                @csrf
                <input type="hidden" name="id" id="catalogModeloFormId" value="">
                <div class="modal-body">
                    <div class="mb-3" data-catalog-model-scope>
                        <label for="catalogModeloFormTipo">Tipo</label>
                        <select id="catalogModeloFormTipo" name="tipo_id" class="form-select" data-select2-placeholder="Selecione o tipo...">
                            <option value="">Selecione o tipo...</option>
                            @foreach ($types as $type)
                                <option value="{{ $type['id'] ?? 0 }}">{{ $type['nome'] ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3" data-catalog-model-scope>
                        <label for="catalogModeloFormMarca">Marca</label>
                        <select id="catalogModeloFormMarca" name="marca_id" class="form-select" data-select2-placeholder="Selecione a marca...">
                            <option value="">Selecione a marca...</option>
                            @foreach ($allBrands as $brand)
                                <option value="{{ $brand['id'] ?? 0 }}">{{ $brand['nome'] ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="catalogModeloFormNome">Nome do modelo</label>
                        <input type="text" name="nome" id="catalogModeloFormNome" class="form-control" maxlength="100" required placeholder="Ex.: Galaxy S24 Ultra">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="catalogModeloFormSubmit">Salvar modelo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="catalogMarcaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <h5 class="modal-title" id="catalogMarcaModalTitle">Nova marca</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="post" action="{{ route('equipments.catalog.brands.save') }}" id="catalogMarcaForm">
                @csrf
                <input type="hidden" name="id" id="catalogMarcaFormId" value="">
                <div class="modal-body">
                    <div class="mb-3" data-catalog-brand-scope>
                        <label for="catalogMarcaFormTipo">Tipo</label>
                        <select id="catalogMarcaFormTipo" name="tipo_id" class="form-select" data-select2-placeholder="Selecione o tipo...">
                            <option value="">Selecione o tipo...</option>
                            @foreach ($types as $type)
                                <option value="{{ $type['id'] ?? 0 }}">{{ $type['nome'] ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="catalogMarcaFormNome">Nome da marca</label>
                        <input type="text" name="nome" id="catalogMarcaFormNome" class="form-control" maxlength="100" required placeholder="Ex.: Xiaomi">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="catalogMarcaFormSubmit">Salvar marca</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="catalogTipoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <h5 class="modal-title" id="catalogTipoModalTitle">Novo tipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="post" action="{{ route('equipments.catalog.types.save') }}" id="catalogTipoForm">
                @csrf
                <input type="hidden" name="id" id="catalogTipoFormId" value="">
                <div class="modal-body">
                    <label for="catalogTipoFormNome">Nome do tipo</label>
                    <input type="text" name="nome" id="catalogTipoFormNome" class="form-control" maxlength="100" required placeholder="Ex.: Smartwatch">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="catalogTipoFormSubmit">Salvar tipo</button>
                </div>
            </form>
        </div>
    </div>
</div>
