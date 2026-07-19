@php
    $searchScopeValue = request('scope', 'tudo');
    $searchScopeLabel = 'Busca completa';
    foreach (($desktopSearchScopes ?? []) as $scope) {
        if (($scope['value'] ?? '') === $searchScopeValue) {
            $searchScopeLabel = (string) ($scope['label'] ?? $searchScopeLabel);
            break;
        }
    }

    $profileName = (string) ($desktopUser['nome'] ?? 'Usuário');
    $profileGroup = (string) ($desktopUser['group']['nome'] ?? ($desktopUser['perfil'] ?? 'Acesso administrativo'));
    $profileEmail = (string) ($desktopUser['email'] ?? '');
    $profileInitial = mb_strtoupper(mb_substr($profileName !== '' ? $profileName : 'U', 0, 1));
@endphp

<header class="desktop-topbar">
    <div class="desktop-topbar-left">
        <button
            type="button"
            class="mobile-sidebar-toggle {{ ($desktopSidebarHidden ?? false) ? '' : 'd-lg-none' }}"
            id="mobileSidebarToggle"
            aria-label="Abrir menu"
            aria-expanded="false"
            aria-controls="desktopSidebar"
        >
            <i class="bi bi-list"></i>
        </button>

        <a href="{{ route('dashboard') }}" class="desktop-icon-button" aria-label="Ir para o início">
            <i class="bi bi-house-door"></i>
        </a>

        <div
            class="dropdown desktop-notification-dropdown"
            data-desktop-notification-root
            data-desktop-notification-summary-url="{{ route('notifications.summary', ['box' => 'operational']) }}"
        >
            <button
                type="button"
                class="desktop-icon-button position-relative"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Notificações"
                data-desktop-notification-toggle
            >
                <i class="bi bi-bell"></i>
                <span class="desktop-notification-badge d-none" data-desktop-notification-badge></span>
            </button>

            <div class="dropdown-menu dropdown-menu-end desktop-notification-menu" data-desktop-notification-menu>
                <div class="desktop-dropdown-head">
                    <div>
                        <strong>Notificações</strong>
                        <small data-desktop-notification-unread>Resumo carregado sob demanda.</small>
                    </div>

                    <div class="d-flex gap-1">
                        <form method="post" action="{{ route('notifications.mark-all') }}">
                            @csrf
                            <input type="hidden" name="box" value="operational">
                            <button type="submit" class="btn btn-sm btn-soft" title="Marcar todas como lidas">
                                Marcar todas
                            </button>
                        </form>

                        <form method="post" action="{{ route('notifications.clear-read') }}">
                            @csrf
                            <input type="hidden" name="box" value="operational">
                            <button type="submit" class="btn btn-sm btn-soft" title="Remover as notificações já lidas">
                                <i class="bi bi-trash3 me-1"></i>Limpar lidas
                            </button>
                        </form>
                    </div>
                </div>

                <div class="desktop-notification-list" data-desktop-notification-list>
                    <div class="desktop-notification-empty" data-desktop-notification-placeholder>
                        Abra este menu para carregar as notificações mais recentes.
                    </div>
                </div>

                <a href="{{ route('notifications.index', ['box' => 'operational']) }}" class="desktop-notification-footer" data-desktop-notification-footer>
                    Ver todas
                </a>
            </div>
        </div>

        <div
            class="dropdown desktop-notification-dropdown desktop-correspondence-dropdown"
            data-desktop-correspondence-root
            data-desktop-correspondence-summary-url="{{ route('notifications.summary', ['box' => 'correspondence']) }}"
        >
            <button
                type="button"
                class="desktop-icon-button position-relative"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Mensagens e documentos"
                title="Mensagens e documentos"
                data-desktop-correspondence-toggle
            >
                <i class="bi bi-envelope"></i>
                <span class="desktop-notification-badge d-none" data-desktop-correspondence-badge></span>
            </button>

            <div class="dropdown-menu dropdown-menu-end desktop-notification-menu" data-desktop-correspondence-menu>
                <div class="desktop-dropdown-head">
                    <div>
                        <strong>Mensagens e documentos</strong>
                        <small data-desktop-correspondence-unread>Resumo carregado sob demanda.</small>
                    </div>

                    <div class="d-flex gap-1">
                        <form method="post" action="{{ route('notifications.mark-all') }}">
                            @csrf
                            <input type="hidden" name="box" value="correspondence">
                            <button type="submit" class="btn btn-sm btn-soft" title="Marcar todas como lidas">
                                Marcar todas
                            </button>
                        </form>

                        <form method="post" action="{{ route('notifications.clear-read') }}">
                            @csrf
                            <input type="hidden" name="box" value="correspondence">
                            <button type="submit" class="btn btn-sm btn-soft" title="Remover mensagens e documentos já lidos">
                                <i class="bi bi-trash3 me-1"></i>Limpar lidas
                            </button>
                        </form>
                    </div>
                </div>

                <div class="desktop-notification-list" data-desktop-correspondence-list>
                    <div class="desktop-notification-empty" data-desktop-correspondence-placeholder>
                        Abra este menu para carregar mensagens e documentos recentes.
                    </div>
                </div>

                <a href="{{ route('notifications.index', ['box' => 'correspondence']) }}" class="desktop-notification-footer" data-desktop-correspondence-footer>
                    Ver mensagens e documentos
                </a>
            </div>
        </div>
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
