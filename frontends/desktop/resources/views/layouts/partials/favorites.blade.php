@php
    $desktopFavoritesList = $desktopFavorites ?? [];
@endphp

{{--
    Menu pessoal de atalhos. Copia a moldura do dropdown de notificacoes
    (.desktop-notification-dropdown / .desktop-notification-menu), que ja' e' o
    padrao de dropdown de icone da topbar. Ao contrario das notificacoes, a
    lista vem renderizada do servidor: sao no maximo 12 itens ja' resolvidos
    pelo View::composer, entao nao vale a pena carregar sob demanda.
--}}
<div
    class="dropdown desktop-notification-dropdown desktop-favorites-dropdown"
    data-desktop-favorites-root
    data-desktop-favorites-toggle-url="{{ route('favorites.toggle') }}"
>
    <button
        type="button"
        class="desktop-icon-button position-relative"
        data-bs-toggle="dropdown"
        data-bs-boundary="viewport"
        aria-expanded="false"
        aria-label="Favoritos"
        title="Favoritos"
    >
        <i class="bi bi-bookmark-star"></i>
        <span
            class="desktop-notification-badge {{ $desktopFavoritesList === [] ? 'd-none' : '' }}"
            data-desktop-favorites-badge
        >{{ count($desktopFavoritesList) }}</span>
    </button>

    <div class="dropdown-menu dropdown-menu-start desktop-notification-menu desktop-favorites-menu">
        <div class="desktop-dropdown-head">
            <div>
                <strong>Favoritos</strong>
                <small>Suas páginas fixadas, no seu login.</small>
            </div>
        </div>

        <div class="desktop-favorites-list" data-desktop-favorites-list>
            @forelse ($desktopFavoritesList as $favorite)
                <a href="{{ $favorite['url'] }}" class="desktop-favorites-link">
                    <i class="bi {{ $favorite['icon'] }}"></i>
                    <span class="desktop-favorites-link-copy">
                        <strong>{{ $favorite['label'] }}</strong>
                        <small>{{ $favorite['section'] }}</small>
                    </span>
                </a>
            @empty
                <div class="desktop-notification-empty" data-desktop-favorites-empty>
                    Nenhum favorito ainda. Use a estrela ao lado do título da página para fixá-la aqui.
                </div>
            @endforelse
        </div>
    </div>
</div>
