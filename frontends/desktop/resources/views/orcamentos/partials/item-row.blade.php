@php
    $item = is_array($item ?? null) ? $item : [];
    $quickCatalogs = is_array($quickCatalogs ?? null) ? $quickCatalogs : [];
    $canQuickCreateService = (bool) data_get($quickCatalogs, 'service.enabled', false);
    $canQuickCreatePart = (bool) data_get($quickCatalogs, 'part.enabled', false);
    $canQuickCreateAny = $canQuickCreateService || $canQuickCreatePart;
    $indexKey = (string) ($index ?? 0);
    $typeId = 'orcamentoItemType-' . $indexKey;
    $referenceId = 'orcamentoItemReference-' . $indexKey;
    $descriptionId = 'orcamentoItemDescription-' . $indexKey;
    $quantityId = 'orcamentoItemQuantity-' . $indexKey;
    $unitPriceId = 'orcamentoItemUnitPrice-' . $indexKey;
    $discountId = 'orcamentoItemDiscount-' . $indexKey;
    $additionId = 'orcamentoItemAddition-' . $indexKey;
    $totalId = 'orcamentoItemTotal-' . $indexKey;
    $notesId = 'orcamentoItemNotes-' . $indexKey;
    $field = static function (string $name, mixed $default = '') use ($item, $indexKey): mixed {
        $oldValue = old('itens.' . $indexKey . '.' . $name);

        if ($oldValue !== null) {
            return $oldValue;
        }

        return $item[$name] ?? $default;
    };
    $quickCreateType = (string) $field('tipo_item', 'servico');
    $quickCreateType = $quickCreateType === 'peca' ? 'peca' : 'servico';
    $quickCreateLabel = $quickCreateType === 'peca' ? 'Nova peça' : 'Novo serviço';
    $quickCreateAriaLabel = $quickCreateType === 'peca' ? 'Cadastrar nova peça' : 'Cadastrar novo serviço';
@endphp
<tr data-budget-item-row data-index="{{ $indexKey }}">
    <td colspan="8" class="budget-item-card-cell">
        <div class="budget-item-layout">
            <div class="budget-item-line budget-item-line-primary">
                <div class="budget-item-field budget-item-field-type">
                    <label for="{{ $typeId }}" class="budget-item-field-label">Tipo</label>
                    <select id="{{ $typeId }}" name="itens[{{ $indexKey }}][tipo_item]" class="form-select" data-budget-item-type>
                        <option value="servico" @selected((string) $field('tipo_item', 'servico') === 'servico')>Serviço</option>
                        <option value="peca" @selected((string) $field('tipo_item') === 'peca')>Peça</option>
                    </select>
                    <input type="hidden" name="itens[{{ $indexKey }}][modo_precificacao]" value="{{ $field('modo_precificacao', 'manual') }}" data-budget-item-mode>
                </div>

                <div class="budget-item-field budget-item-field-reference">
                    <label for="{{ $referenceId }}" class="budget-item-field-label">Referência</label>
                    <select
                        id="{{ $referenceId }}"
                        name="itens[{{ $indexKey }}][referencia_id]"
                        class="form-select"
                        data-budget-item-reference
                        data-selected-reference="{{ $field('referencia_id') }}"
                    >
                        <option value="">Selecione</option>
                    </select>
                </div>

                <div class="budget-item-field budget-item-field-description">
                    <label for="{{ $descriptionId }}" class="budget-item-field-label">Descrição</label>
                    <input
                        id="{{ $descriptionId }}"
                        type="text"
                        name="itens[{{ $indexKey }}][descricao]"
                        class="form-control"
                        value="{{ $field('descricao') }}"
                        placeholder="Descrição do item"
                        data-budget-item-description
                    >
                </div>
            </div>

            <div class="budget-item-line budget-item-line-financial">
                <div class="budget-item-field budget-item-field-quantity">
                    <label for="{{ $quantityId }}" class="budget-item-field-label">Qtd</label>
                    <input
                        id="{{ $quantityId }}"
                        type="number"
                        name="itens[{{ $indexKey }}][quantidade]"
                        class="form-control"
                        min="0"
                        step="0.01"
                        value="{{ $field('quantidade', 1) }}"
                        data-budget-item-quantity
                    >
                </div>

                <div class="budget-item-field budget-item-field-unit-price">
                    <label for="{{ $unitPriceId }}" class="budget-item-field-label">Valor unit.</label>
                    <input
                        id="{{ $unitPriceId }}"
                        type="number"
                        name="itens[{{ $indexKey }}][valor_unitario]"
                        class="form-control"
                        min="0"
                        step="0.01"
                        value="{{ $field('valor_unitario', 0) }}"
                        data-budget-item-unit-price
                    >
                </div>

                <div class="budget-item-field budget-item-field-discount">
                    <label for="{{ $discountId }}" class="budget-item-field-label">Desconto</label>
                    <input
                        id="{{ $discountId }}"
                        type="number"
                        name="itens[{{ $indexKey }}][desconto]"
                        class="form-control"
                        min="0"
                        step="0.01"
                        value="{{ $field('desconto', 0) }}"
                        data-budget-item-discount
                    >
                </div>

                <div class="budget-item-field budget-item-field-addition">
                    <label for="{{ $additionId }}" class="budget-item-field-label">Acréscimo</label>
                    <input
                        id="{{ $additionId }}"
                        type="number"
                        name="itens[{{ $indexKey }}][acrescimo]"
                        class="form-control"
                        min="0"
                        step="0.01"
                        value="{{ $field('acrescimo', 0) }}"
                        data-budget-item-addition
                    >
                </div>

                <div class="budget-item-field budget-item-field-total">
                    <label for="{{ $totalId }}" class="budget-item-field-label">Total</label>
                    <input
                        id="{{ $totalId }}"
                        type="text"
                        class="form-control"
                        value="{{ number_format((float) $field('total', 0), 2, ',', '.') }}"
                        readonly
                        data-budget-item-total
                    >
                </div>

                <div class="budget-item-field budget-item-field-actions">
                    <div class="budget-item-field-label">Ações</div>
                    <div class="budget-item-actions">
                        @if ($canQuickCreateAny)
                            <button
                                type="button"
                                class="btn btn-outline-primary btn-sm budget-item-quick-action"
                                data-budget-item-quick-create
                                aria-label="{{ $quickCreateAriaLabel }}"
                                title="{{ $quickCreateAriaLabel }}"
                            >
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                                <span data-budget-item-quick-create-label>{{ $quickCreateLabel }}</span>
                            </button>
                        @endif

                        <button type="button" class="btn btn-outline-danger btn-sm budget-item-remove-action" data-budget-item-remove>
                            <i class="bi bi-trash me-1"></i>
                            Excluir
                        </button>
                    </div>
                </div>
            </div>

            <div class="budget-item-line budget-item-line-notes">
                <div class="budget-item-field budget-item-field-notes">
                    <label for="{{ $notesId }}" class="budget-item-field-label">Observações</label>
                    <textarea
                        id="{{ $notesId }}"
                        name="itens[{{ $indexKey }}][observacoes]"
                        class="form-control"
                        rows="2"
                        placeholder="Observações do item"
                        data-budget-item-notes
                    >{{ $field('observacoes') }}</textarea>
                </div>
            </div>
        </div>
    </td>
</tr>
