@props([
    'variant' => 'star',
    'route' => null,
    // Restringe a estrela a uma rota. Partials compartilhados (por exemplo
    // users/_index-content, incluido tanto por /usuarios quanto pela aba
    // "Usuarios" das Configuracoes) tem um titulo que so' e' o titulo DA PAGINA
    // numa das telas — nas outras ele e' cabecalho de secao e nao leva estrela.
    'only' => null,
])

@php
    // $desktopFavoriteRoute vem do View::composer('*') e ja' e' null quando a
    // pagina atual nao e' favoritavel (registro individual, tela de ajuda,
    // busca...). Sem rota favoritavel o componente nao renderiza NADA — por
    // isso da' para solta-lo ao lado de qualquer titulo de pagina sem envolver
    // num @if: quem nao e' favoritavel simplesmente nao mostra estrela.
    $favoriteRoute = $route ?? ($desktopFavoriteRoute ?? null);

    if ($only !== null && ! request()->routeIs($only)) {
        $favoriteRoute = null;
    }

    $isFavorite = $favoriteRoute !== null && \App\Support\DesktopPreferences::isFavorite($favoriteRoute);
    $actionLabel = $isFavorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos';
@endphp

@if ($favoriteRoute)
    @if ($variant === 'dropdown-item')
        <li>
            <button
                type="button"
                class="dropdown-item desktop-favorite-action"
                data-desktop-favorite-toggle
                data-favorite-route="{{ $favoriteRoute }}"
                aria-pressed="{{ $isFavorite ? 'true' : 'false' }}"
            >
                <i class="bi {{ $isFavorite ? 'bi-star-fill' : 'bi-star' }} me-2" data-desktop-favorite-icon></i>
                <span data-desktop-favorite-label>{{ $actionLabel }}</span>
            </button>
        </li>
    @else
        <button
            type="button"
            class="desktop-favorite-star {{ $isFavorite ? 'is-active' : '' }}"
            data-desktop-favorite-toggle
            data-favorite-route="{{ $favoriteRoute }}"
            aria-pressed="{{ $isFavorite ? 'true' : 'false' }}"
            aria-label="{{ $actionLabel }}"
            title="{{ $actionLabel }}"
        >
            <i class="bi {{ $isFavorite ? 'bi-star-fill' : 'bi-star' }}" data-desktop-favorite-icon></i>
        </button>
    @endif
@endif
