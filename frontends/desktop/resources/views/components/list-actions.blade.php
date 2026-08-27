@props([
    'label' => 'Ações',
    'align' => 'end',
    'variant' => 'outline-light',
    'size' => 'sm',
    // Liga o item "Adicionar aos favoritos" no topo do menu. Fica desligado por
    // padrão porque este mesmo componente monta os dropdowns "Ações" de LINHA
    // das tabelas — favoritar é uma ação da página, não de um registro.
    'favoritable' => false,
])

@php
    $toggleClasses = trim('btn ' . ($size !== '' ? 'btn-' . $size . ' ' : '') . 'btn-' . $variant . ' dropdown-toggle list-actions-toggle');
@endphp

{{--
    Dropdown de ações padrão das listagens do desktop — mesma moldura usada tanto
    para as ações de linha ("Ações") quanto para os botões secundários do
    cabeçalho ("Mais ações"). Substitui as variações por-módulo
    (client-actions-*, equipment-actions-*, supplier-actions-* etc.) por uma
    única classe list-actions-* no CSS.
--}}
<div class="dropdown list-actions-dropdown">
    <button
        type="button"
        class="{{ $toggleClasses }}"
        data-bs-toggle="dropdown"
        aria-expanded="false"
    >
        <span>{{ $label }}</span>
        <i class="bi bi-chevron-down"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-{{ $align }} list-actions-menu">
        @if ($favoritable)
            <x-favorite-toggle variant="dropdown-item" />

            @if (($desktopFavoriteRoute ?? null) && trim((string) $slot) !== '')
                <li><hr class="dropdown-divider"></li>
            @endif
        @endif
        {{ $slot }}
    </ul>
</div>
