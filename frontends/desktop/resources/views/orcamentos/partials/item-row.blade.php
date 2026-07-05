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
    $discountTypeId = $discountId . '-type';
    $additionId = 'orcamentoItemAddition-' . $indexKey;
    $additionTypeId = $additionId . '-type';
    $totalId = 'orcamentoItemTotal-' . $indexKey;
    $notesId = 'orcamentoItemNotes-' . $indexKey;
    $field = static function (string $name, mixed $default = '') use ($item, $indexKey): mixed {
        $oldValue = old('itens.' . $indexKey . '.' . $name);

        if ($oldValue !== null) {
            return $oldValue;
        }

        return $item[$name] ?? $default;
    };
    $parseDecimal = static function (mixed $value, int $scale = 4): float {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]/u', '', trim((string) $value)) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($lastDot !== false) {
            $parts = explode('.', $normalized);
            $lastPart = (string) end($parts);

            if (count($parts) > 2 || strlen($lastPart) === 3) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        return round((float) $normalized, $scale);
    };
    $formatDecimalValue = static fn (mixed $value, int $scale = 4): string => number_format($parseDecimal($value, $scale), $scale, '.', '');
    $formatPercentDisplay = static fn (mixed $value): string => number_format($parseDecimal($value, 4), 2, ',', '.');
    $resolveAdjustmentType = static function (mixed $value): string {
        $normalized = trim((string) $value);

        return in_array($normalized, ['valor', 'percentual'], true) ? $normalized : 'valor';
    };
    $quickCreateType = (string) $field('tipo_item', 'servico');
    $quickCreateType = $quickCreateType === 'peca' ? 'peca' : 'servico';
    $quickCreateLabel = $quickCreateType === 'peca' ? 'Nova peça' : 'Novo serviço';
    $quickCreateAriaLabel = $quickCreateType === 'peca' ? 'Cadastrar nova peça' : 'Cadastrar novo serviço';
    $discountType = $resolveAdjustmentType($field('desconto_tipo', 'valor'));
    $discountAmount = $field('desconto', 0);
    $discountPercent = $field('desconto_percentual', 0);
    $discountDisplay = $discountType === 'percentual' ? $formatPercentDisplay($discountPercent) : $formatMoney($discountAmount);
    $discountPreviewVisible = $discountType === 'percentual';
    $additionType = $resolveAdjustmentType($field('acrescimo_tipo', 'valor'));
    $additionAmount = $field('acrescimo', 0);
    $additionPercent = $field('acrescimo_percentual', 0);
    $additionDisplay = $additionType === 'percentual' ? $formatPercentDisplay($additionPercent) : $formatMoney($additionAmount);
    $additionPreviewVisible = $additionType === 'percentual';
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
                        type="text"
                        name="itens[{{ $indexKey }}][valor_unitario]"
                        class="form-control"
                        inputmode="decimal"
                        value="{{ $formatMoney($field('valor_unitario', 0)) }}"
                        data-budget-item-unit-price
                        data-budget-money
                    >
                </div>

                <div class="budget-item-field budget-item-field-discount">
                    <label for="{{ $discountId }}" class="budget-item-field-label">Desconto</label>
                    <div class="budget-adjustment-group" data-budget-adjustment-group>
                        <input
                            id="{{ $discountId }}"
                            type="text"
                            class="form-control budget-adjustment-input"
                            inputmode="decimal"
                            value="{{ $discountDisplay }}"
                            autocomplete="off"
                            data-budget-item-discount-display
                        >
                        <div class="budget-adjustment-toggle" role="group" aria-label="Modo do desconto">
                            <button
                                type="button"
                                class="budget-adjustment-toggle-btn {{ $discountType === 'valor' ? 'is-active' : '' }}"
                                data-budget-adjustment-option="valor"
                                aria-pressed="{{ $discountType === 'valor' ? 'true' : 'false' }}"
                            >R$</button>
                            <button
                                type="button"
                                class="budget-adjustment-toggle-btn {{ $discountType === 'percentual' ? 'is-active' : '' }}"
                                data-budget-adjustment-option="percentual"
                                aria-pressed="{{ $discountType === 'percentual' ? 'true' : 'false' }}"
                            >%</button>
                        </div>
                    </div>
                    <div class="budget-adjustment-preview" data-budget-item-discount-preview-wrapper @if (! $discountPreviewVisible) hidden @endif>
                        <label class="budget-adjustment-preview-label" for="{{ $discountId }}-preview">Valor do desconto</label>
                        <input
                            id="{{ $discountId }}-preview"
                            type="text"
                            class="form-control budget-adjustment-preview-input"
                            value="{{ $formatMoney($discountAmount) }}"
                            readonly
                            tabindex="-1"
                            data-budget-item-discount-preview
                        >
                    </div>
                    <input type="hidden" id="{{ $discountTypeId }}" name="itens[{{ $indexKey }}][desconto_tipo]" value="{{ $discountType }}" data-budget-item-discount-type>
                    <input type="hidden" name="itens[{{ $indexKey }}][desconto]" value="{{ $formatDecimalValue($discountAmount, 2) }}" data-budget-item-discount>
                    <input type="hidden" name="itens[{{ $indexKey }}][desconto_percentual]" value="{{ $formatDecimalValue($discountPercent, 4) }}" data-budget-item-discount-percent>
                </div>

                <div class="budget-item-field budget-item-field-addition">
                    <label for="{{ $additionId }}" class="budget-item-field-label">Acréscimo</label>
                    <div class="budget-adjustment-group" data-budget-adjustment-group>
                        <input
                            id="{{ $additionId }}"
                            type="text"
                            class="form-control budget-adjustment-input"
                            inputmode="decimal"
                            value="{{ $additionDisplay }}"
                            autocomplete="off"
                            data-budget-item-addition-display
                        >
                        <div class="budget-adjustment-toggle" role="group" aria-label="Modo do acréscimo">
                            <button
                                type="button"
                                class="budget-adjustment-toggle-btn {{ $additionType === 'valor' ? 'is-active' : '' }}"
                                data-budget-adjustment-option="valor"
                                aria-pressed="{{ $additionType === 'valor' ? 'true' : 'false' }}"
                            >R$</button>
                            <button
                                type="button"
                                class="budget-adjustment-toggle-btn {{ $additionType === 'percentual' ? 'is-active' : '' }}"
                                data-budget-adjustment-option="percentual"
                                aria-pressed="{{ $additionType === 'percentual' ? 'true' : 'false' }}"
                            >%</button>
                        </div>
                    </div>
                    <div class="budget-adjustment-preview" data-budget-item-addition-preview-wrapper @if (! $additionPreviewVisible) hidden @endif>
                        <label class="budget-adjustment-preview-label" for="{{ $additionId }}-preview">Valor do acréscimo</label>
                        <input
                            id="{{ $additionId }}-preview"
                            type="text"
                            class="form-control budget-adjustment-preview-input"
                            value="{{ $formatMoney($additionAmount) }}"
                            readonly
                            tabindex="-1"
                            data-budget-item-addition-preview
                        >
                    </div>
                    <input type="hidden" id="{{ $additionTypeId }}" name="itens[{{ $indexKey }}][acrescimo_tipo]" value="{{ $additionType }}" data-budget-item-addition-type>
                    <input type="hidden" name="itens[{{ $indexKey }}][acrescimo]" value="{{ $formatDecimalValue($additionAmount, 2) }}" data-budget-item-addition>
                    <input type="hidden" name="itens[{{ $indexKey }}][acrescimo_percentual]" value="{{ $formatDecimalValue($additionPercent, 4) }}" data-budget-item-addition-percent>
                </div>

                <div class="budget-item-field budget-item-field-total">
                    <label for="{{ $totalId }}" class="budget-item-field-label">Total</label>
                    <input
                        id="{{ $totalId }}"
                        type="text"
                        class="form-control"
                        value="{{ $formatMoney($field('total', 0)) }}"
                        readonly
                        data-budget-item-total
                        data-budget-money
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
