@php
    $searchScopeValue = request('scope', 'tudo');
    $searchScopeLabel = 'Busca completa';
    foreach (($desktopSearchScopes ?? []) as $scope) {
        if (($scope['value'] ?? '') === $searchScopeValue) {
            $searchScopeLabel = (string) ($scope['label'] ?? $searchScopeLabel);
            break;
        }
    }

    $notifications = $desktopNotifications['items'] ?? [];
    $unreadCount = (int) ($desktopNotifications['unread_count'] ?? 0);
    $profileName = (string) ($desktopUser['nome'] ?? 'Usuário');
    $profileGroup = (string) ($desktopUser['group']['nome'] ?? ($desktopUser['perfil'] ?? 'Acesso administrativo'));
    $profileEmail = (string) ($desktopUser['email'] ?? '');
    $profileInitial = mb_strtoupper(mb_substr($profileName !== '' ? $profileName : 'U', 0, 1));
@endphp

<header class="desktop-topbar">
    <div class="desktop-topbar-left">
        <button type="button" class="mobile-sidebar-toggle d-lg-none" id="mobileSidebarToggle" aria-label="Abrir menu">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <form
        class="desktop-topbar-search"
        method="get"
        action="{{ route('search.index') }}"
        data-desktop-search-form
        data-suggest-url="{{ route('search.suggest') }}"
    >
        <input type="hidden" name="scope" value="{{ $searchScopeValue }}" data-desktop-search-scope-value>

        <div class="dropdown">
            <button
                type="button"
                class="desktop-search-scope dropdown-toggle"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <span data-desktop-search-scope-label>{{ $searchScopeLabel }}</span>
                <i class="bi bi-chevron-down"></i>
            </button>

            <div class="dropdown-menu desktop-search-scope-menu">
                @foreach (($desktopSearchScopes ?? []) as $scope)
                    <button
                        type="button"
                        class="dropdown-item {{ ($scope['value'] ?? '') === $searchScopeValue ? 'active' : '' }}"
                        data-desktop-search-scope-option="{{ $scope['value'] ?? 'tudo' }}"
                    >
                        <i class="bi {{ $scope['icon'] ?? 'bi-grid' }} me-2"></i>
                        {{ $scope['label'] ?? 'Busca completa' }}
                    </button>
                @endforeach
            </div>
        </div>

        <i class="bi bi-search desktop-search-icon"></i>
        <input
            type="search"
            class="desktop-search-input"
            name="q"
            value="{{ request('q') }}"
            placeholder="O que você procura?"
            aria-label="Pesquisar"
            autocomplete="off"
            data-desktop-search-input
        >

        <button type="submit" class="desktop-search-submit" aria-label="Executar busca">
            <i class="bi bi-arrow-right-short"></i>
        </button>

        <div class="desktop-search-results" data-desktop-search-results hidden></div>
    </form>

    <div class="desktop-topbar-actions">
        @if (\App\Support\DesktopSession::can('os', 'criar'))
            <a href="{{ route('orders.create') }}" class="btn btn-primary desktop-action-button">
                <i class="bi bi-plus-lg me-1"></i>
                OS
            </a>
        @endif

        <div class="dropdown desktop-notification-dropdown">
            <button
                type="button"
                class="desktop-icon-button position-relative"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Notificações"
            >
                <i class="bi bi-bell"></i>
                @if ($unreadCount > 0)
                    <span class="desktop-notification-badge">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
                @endif
            </button>

            <div class="dropdown-menu dropdown-menu-end desktop-notification-menu">
                <div class="desktop-dropdown-head">
                    <div>
                        <strong>Notificações</strong>
                        <small>{{ $unreadCount }} não lidas</small>
                    </div>

                    <form method="post" action="{{ route('notifications.mark-all') }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-soft">
                            Marcar todas
                        </button>
                    </form>
                </div>

                <div class="desktop-notification-list">
                    @forelse ($notifications as $notification)
                        <a
                            href="{{ route('notifications.open', $notification['id']) }}"
                            class="desktop-notification-item {{ empty($notification['lida_em']) ? 'is-unread' : 'is-read' }}"
                        >
                            <span class="desktop-notification-icon">
                                <i class="{{ $notification['icone'] ?? 'bi bi-bell' }}"></i>
                            </span>

                            <span class="desktop-notification-copy">
                                <strong>{{ $notification['titulo'] ?? 'Notificação' }}</strong>
                                <small>{{ $notification['corpo'] ?? '' }}</small>
                                <span>{{ $notification['criada_em_humano'] ?? 'Agora' }}</span>
                            </span>
                        </a>
                    @empty
                        <div class="desktop-notification-empty">
                            Nenhuma notificação disponível.
                        </div>
                    @endforelse
                </div>

                <a href="{{ route('notifications.index') }}" class="desktop-notification-footer">
                    Ver todas
                </a>
            </div>
        </div>

        <div class="dropdown desktop-profile-dropdown">
            <button
                type="button"
                class="desktop-profile-chip dropdown-toggle"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Menu do perfil: {{ $profileName }}"
                title="{{ $profileName }}"
            >
                <div class="desktop-profile-avatar">
                    {{ $profileInitial }}
                </div>
            </button>

            <div class="dropdown-menu dropdown-menu-end desktop-profile-menu">
                <div class="desktop-profile-menu-head">
                    <strong>{{ $profileName }}</strong>
                    <small>{{ $profileEmail !== '' ? $profileEmail : 'Conta do desktop' }}</small>
                    <span>{{ $profileGroup }}</span>
                </div>

                <a href="{{ route('profile.show') }}" class="dropdown-item">
                    <i class="bi bi-person me-2"></i>
                    Meu Perfil
                </a>

                <a href="{{ route('profile.edit') }}" class="dropdown-item">
                    <i class="bi bi-gear me-2"></i>
                    Configurações do perfil
                </a>

                <div class="dropdown-divider"></div>

                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger">
                        <i class="bi bi-box-arrow-right me-2"></i>
                        Sair
                    </button>
                </form>

                <form method="post" action="{{ route('logout.forget') }}">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger">
                        <i class="bi bi-person-x me-2"></i>
                        Sair e Esquecer Login
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
