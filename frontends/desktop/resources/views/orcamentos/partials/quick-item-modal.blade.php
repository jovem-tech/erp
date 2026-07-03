@php
    $quickCatalogs = is_array($quickCatalogs ?? null) ? $quickCatalogs : [];
    $serviceQuick = is_array($quickCatalogs['service'] ?? null) ? $quickCatalogs['service'] : [];
    $partQuick = is_array($quickCatalogs['part'] ?? null) ? $quickCatalogs['part'] : [];

    $serviceEnabled = (bool) ($serviceQuick['enabled'] ?? false);
    $partEnabled = (bool) ($partQuick['enabled'] ?? false);
    $defaultQuickType = $serviceEnabled ? 'servico' : ($partEnabled ? 'peca' : 'servico');
    $defaultAction = $serviceEnabled
        ? ($serviceQuick['store_url'] ?? '#')
        : ($partQuick['store_url'] ?? '#');
@endphp

<div class="modal fade" id="orcamentoQuickItemModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-budget-quick-modal>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <div>
                    <p class="desktop-eyebrow mb-1">Cadastro rápido</p>
                    <h5 class="modal-title mb-0" data-budget-quick-title>Cadastro rápido de item</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-primary border mb-4" data-budget-quick-note>
                    Cadastre a peça ou o serviço sem sair do orçamento e aplique o novo item diretamente na linha atual.
                </div>

                <form
                    id="orcamentoQuickItemForm"
                    method="post"
                    action="{{ $defaultAction }}"
                    class="desktop-grid desktop-grid-two"
                    data-budget-quick-form
                    data-budget-quick-service-url="{{ $serviceQuick['store_url'] ?? '' }}"
                    data-budget-quick-part-url="{{ $partQuick['store_url'] ?? '' }}"
                    data-budget-quick-service-label="{{ $serviceQuick['label'] ?? 'Serviço' }}"
                    data-budget-quick-part-label="{{ $partQuick['label'] ?? 'Peça' }}"
                >
                    @csrf

                    <div>
                        <label for="orcamentoQuickItemType">Tipo do cadastro</label>
                        <select id="orcamentoQuickItemType" class="form-select" name="tipo_item" data-budget-quick-type>
                            <option value="servico" @selected($defaultQuickType === 'servico') @disabled(! $serviceEnabled)>Serviço</option>
                            <option value="peca" @selected($defaultQuickType === 'peca') @disabled(! $partEnabled)>Peça</option>
                        </select>
                    </div>

                    <div>
                        <label for="orcamentoQuickItemEquipmentType">Tipo de equipamento</label>
                        <input
                            type="text"
                            id="orcamentoQuickItemEquipmentType"
                            name="tipo_equipamento"
                            class="form-control"
                            placeholder="Ex.: Notebook, smartphone, desktop..."
                            data-budget-quick-field="tipo_equipamento"
                        >
                    </div>

                    <div class="desktop-grid-span-2">
                        <label for="orcamentoQuickItemName" data-budget-quick-name-label>Nome *</label>
                        <input
                            type="text"
                            id="orcamentoQuickItemName"
                            name="nome"
                            class="form-control"
                            required
                            autocomplete="off"
                            placeholder="Ex.: Troca de conector, SSD 480GB..."
                            data-budget-quick-field="nome"
                        >
                    </div>

                    <div class="desktop-grid-span-2" data-budget-quick-group="servico" @if ($defaultQuickType !== 'servico') hidden @endif>
                        <div class="desktop-grid desktop-grid-two">
                            <div>
                                <label for="orcamentoQuickItemServiceValue">Valor</label>
                                <input
                                    type="number"
                                    id="orcamentoQuickItemServiceValue"
                                    name="valor"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    value="0"
                                    data-budget-quick-field="valor"
                                >
                            </div>

                            <div>
                                <label for="orcamentoQuickItemServiceTime">Tempo padrão (h)</label>
                                <input
                                    type="number"
                                    id="orcamentoQuickItemServiceTime"
                                    name="tempo_padrao_horas"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    value="1"
                                    data-budget-quick-field="tempo_padrao_horas"
                                >
                            </div>

                            <div>
                                <label for="orcamentoQuickItemServiceCost">Custo direto</label>
                                <input
                                    type="number"
                                    id="orcamentoQuickItemServiceCost"
                                    name="custo_direto_padrao"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    value="0"
                                    data-budget-quick-field="custo_direto_padrao"
                                >
                            </div>

                            <div class="desktop-grid-span-2">
                                <label for="orcamentoQuickItemServiceDescription">Descrição complementar</label>
                                <textarea
                                    id="orcamentoQuickItemServiceDescription"
                                    name="descricao"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Opcional: detalhes técnicos, escopo do serviço, compatibilidade..."
                                    data-budget-quick-field="descricao"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="desktop-grid-span-2" data-budget-quick-group="peca" @if ($defaultQuickType !== 'peca') hidden @endif>
                        <div class="desktop-grid desktop-grid-two">
                            <div>
                                <label for="orcamentoQuickItemPartCode">Código</label>
                                <input
                                    type="text"
                                    id="orcamentoQuickItemPartCode"
                                    name="codigo"
                                    class="form-control"
                                    autocomplete="off"
                                    placeholder="Ex.: SSD-480"
                                    data-budget-quick-field="codigo"
                                >
                            </div>

                            <div>
                                <label for="orcamentoQuickItemPartManufacturerCode">Código fabricante</label>
                                <input
                                    type="text"
                                    id="orcamentoQuickItemPartManufacturerCode"
                                    name="codigo_fabricante"
                                    class="form-control"
                                    autocomplete="off"
                                    placeholder="Ex.: MX500-480"
                                    data-budget-quick-field="codigo_fabricante"
                                >
                            </div>

                            <div>
                                <label for="orcamentoQuickItemPartCategory">Categoria</label>
                                <input
                                    type="text"
                                    id="orcamentoQuickItemPartCategory"
                                    name="categoria"
                                    class="form-control"
                                    autocomplete="off"
                                    placeholder="Ex.: Armazenamento"
                                    data-budget-quick-field="categoria"
                                >
                            </div>

                            <div>
                                <label for="orcamentoQuickItemPartSalePrice">Preço de venda</label>
                                <input
                                    type="number"
                                    id="orcamentoQuickItemPartSalePrice"
                                    name="preco_venda"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    value="0"
                                    data-budget-quick-field="preco_venda"
                                >
                            </div>

                            <div>
                                <label for="orcamentoQuickItemPartCostPrice">Preço de custo</label>
                                <input
                                    type="number"
                                    id="orcamentoQuickItemPartCostPrice"
                                    name="preco_custo"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    value="0"
                                    data-budget-quick-field="preco_custo"
                                >
                            </div>

                            <div>
                                <label for="orcamentoQuickItemPartQuantity">Quantidade atual</label>
                                <input
                                    type="number"
                                    id="orcamentoQuickItemPartQuantity"
                                    name="quantidade_atual"
                                    class="form-control"
                                    min="0"
                                    step="1"
                                    value="0"
                                    data-budget-quick-field="quantidade_atual"
                                >
                            </div>

                            <div>
                                <label for="orcamentoQuickItemPartMinStock">Estoque mínimo</label>
                                <input
                                    type="number"
                                    id="orcamentoQuickItemPartMinStock"
                                    name="estoque_minimo"
                                    class="form-control"
                                    min="0"
                                    step="1"
                                    value="0"
                                    data-budget-quick-field="estoque_minimo"
                                >
                            </div>

                            <div class="desktop-grid-span-2">
                                <label for="orcamentoQuickItemPartNotes">Observações</label>
                                <textarea
                                    id="orcamentoQuickItemPartNotes"
                                    name="observacoes"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Opcional: observações técnicas, compatibilidade, fornecedor..."
                                    data-budget-quick-field="observacoes"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="desktop-grid-span-2">
                        <div id="orcamentoQuickItemErrors" class="alert alert-danger d-none mb-0" role="alert"></div>
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">
                    Cancelar
                </button>
                <button type="button" id="orcamentoQuickItemSubmit" class="btn btn-primary" data-budget-quick-submit>
                    <i class="bi bi-plus-circle me-2"></i>
                    Salvar e aplicar
                </button>
            </div>
        </div>
    </div>
</div>
