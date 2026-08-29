{{--
    Uma linha de peça da entrada de estoque (specs/039).

    Usada duas vezes: preenchida no repopular de `old()` após erro de validação,
    e vazia dentro do <template> que o JS clona ao "Adicionar peça".
--}}
@php
    $item = is_array($item ?? null) ? $item : [];
    $index = (string) ($index ?? 0);
    $pecaId = (int) ($item['peca_id'] ?? 0);
    $pecaLabel = trim((string) ($item['peca_label'] ?? ''));
    $quantidade = (string) ($item['quantidade'] ?? '');
    $custo = (string) ($item['custo_unitario'] ?? '');
@endphp

<tr class="financeiro-entrada-linha" data-linha data-index="{{ $index }}">
    <td>
        <select
            class="form-select form-select-sm"
            name="itens_estoque[{{ $index }}][peca_id]"
            data-campo="peca"
            data-placeholder="Busque por código ou nome"
        >
            <option value=""></option>
            @if ($pecaId > 0)
                <option value="{{ $pecaId }}" selected>{{ $pecaLabel !== '' ? $pecaLabel : 'Peça #' . $pecaId }}</option>
            @endif
        </select>
        {{-- Preenchido pelo JS ao escolher a peça: "3 em estoque → 8". --}}
        <small class="text-muted d-block mt-1" data-campo="saldo"></small>
    </td>
    <td>
        {{-- step="any": quantidade é DECIMAL(14,4) desde a 036 — existe meio
             metro de cabo flat e 1,5 g de pasta térmica. --}}
        <input
            type="number"
            class="form-control form-control-sm"
            name="itens_estoque[{{ $index }}][quantidade]"
            data-campo="quantidade"
            step="any"
            min="0.0001"
            value="{{ $quantidade }}"
        >
    </td>
    <td>
        <input
            type="text"
            class="form-control form-control-sm"
            data-campo="custo-display"
            inputmode="numeric"
            placeholder="R$ 0,00"
        >
        <input
            type="hidden"
            name="itens_estoque[{{ $index }}][custo_unitario]"
            data-campo="custo"
            value="{{ $custo }}"
        >
        {{-- Divergência entre o custo da nota e o do cadastro é a regra, não a
             exceção: é assim que preço de fornecedor sobe. Tem de ser visível
             ANTES de salvar, não descoberta depois. --}}
        <small class="text-muted d-block mt-1" data-campo="custo-atual"></small>
    </td>
    <td>
        <span data-campo="total">R$ 0,00</span>
        {{-- Sugestão de preço de venda vinda do simulador de precificação
             (specs/037). Nunca escreve sozinha numa peça já cadastrada: vale a
             "regra do sujo" de estoque-form.js. --}}
        <small class="d-block mt-1" data-campo="sugestao"></small>
        <input type="hidden" name="itens_estoque[{{ $index }}][preco_venda]" data-campo="preco-venda">
    </td>
    <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger" data-acao="remover" title="Remover peça">
            <i class="bi bi-trash"></i>
        </button>
    </td>
</tr>
