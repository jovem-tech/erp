@php
    $item = is_array($item ?? null) ? $item : [];
    $indexKey = (string) ($index ?? 0);
    $field = static function (string $name, mixed $default = '') use ($item, $indexKey): mixed {
        $oldValue = old('itens.' . $indexKey . '.' . $name);

        if ($oldValue !== null) {
            return $oldValue;
        }

        return $item[$name] ?? $default;
    };
@endphp
<tr data-budget-item-row data-index="{{ $indexKey }}">
    <td data-label="Tipo">
        <select name="itens[{{ $indexKey }}][tipo_item]" class="form-select" data-budget-item-type>
            <option value="servico" @selected((string) $field('tipo_item', 'servico') === 'servico')>Serviço</option>
            <option value="peca" @selected((string) $field('tipo_item') === 'peca')>Peça</option>
        </select>
        <input type="hidden" name="itens[{{ $indexKey }}][modo_precificacao]" value="{{ $field('modo_precificacao', 'manual') }}" data-budget-item-mode>
    </td>
    <td data-label="Referência">
        <select
            name="itens[{{ $indexKey }}][referencia_id]"
            class="form-select"
            data-budget-item-reference
            data-selected-reference="{{ $field('referencia_id') }}"
        >
            <option value="">Selecione</option>
        </select>
    </td>
    <td data-label="Descrição">
        <input
            type="text"
            name="itens[{{ $indexKey }}][descricao]"
            class="form-control"
            value="{{ $field('descricao') }}"
            placeholder="Descrição do item"
            data-budget-item-description
        >
    </td>
    <td data-label="Qtd">
        <input
            type="number"
            name="itens[{{ $indexKey }}][quantidade]"
            class="form-control"
            min="0"
            step="0.01"
            value="{{ $field('quantidade', 1) }}"
            data-budget-item-quantity
        >
    </td>
    <td data-label="Valor unit.">
        <input
            type="number"
            name="itens[{{ $indexKey }}][valor_unitario]"
            class="form-control"
            min="0"
            step="0.01"
            value="{{ $field('valor_unitario', 0) }}"
            data-budget-item-unit-price
        >
    </td>
    <td data-label="Desconto">
        <input
            type="number"
            name="itens[{{ $indexKey }}][desconto]"
            class="form-control"
            min="0"
            step="0.01"
            value="{{ $field('desconto', 0) }}"
            data-budget-item-discount
        >
    </td>
    <td data-label="Acréscimo">
        <input
            type="number"
            name="itens[{{ $indexKey }}][acrescimo]"
            class="form-control"
            min="0"
            step="0.01"
            value="{{ $field('acrescimo', 0) }}"
            data-budget-item-addition
        >
    </td>
    <td data-label="Total">
        <input
            type="text"
            class="form-control"
            value="{{ number_format((float) $field('total', 0), 2, ',', '.') }}"
            readonly
            data-budget-item-total
        >
    </td>
    <td data-label="Observações">
        <textarea
            name="itens[{{ $indexKey }}][observacoes]"
            class="form-control"
            rows="2"
            placeholder="Observações do pacote"
            data-budget-item-notes
        >{{ $field('observacoes') }}</textarea>
    </td>
    <td data-label="Ações" class="text-end">
        <button type="button" class="btn btn-outline-danger btn-sm" data-budget-item-remove>
            <i class="bi bi-trash"></i>
        </button>
    </td>
</tr>
