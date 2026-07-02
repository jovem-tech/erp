@php
    $desktopSidebarStateClass = ($desktopSidebarHidden ?? false)
        ? 'is-hidden'
        : (($desktopSidebarCollapsed ?? false) ? 'is-collapsed' : '');
@endphp
<aside class="desktop-sidebar {{ $desktopSidebarStateClass }}" id="desktopSidebar" aria-label="Navegacao principal do sistema">
    <div class="desktop-sidebar-header">
        <a href="{{ route(\App\Support\DesktopNavigation::firstAllowedRouteName()) }}" class="desktop-brand">
            <span class="desktop-brand-mark">
                <i class="bi bi-houses-fill"></i>
            </span>
            <span class="desktop-brand-copy">
                <strong>Sistema ERP</strong>
                <small>Painel administrativo</small>
            </span>
        </a>

        <button type="button" class="sidebar-toggle d-none d-lg-inline-flex" id="sidebarToggle" aria-label="Recolher navegacao">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>

    @php
        // Compara pelo caminho da URL (com limite de segmento "/"), em vez do
        // nome da rota, porque rotas de features diferentes podem compartilhar
        // o mesmo primeiro segmento de nome (ex.: knowledge.defects.index e
        // knowledge.reported-defects.index ambas comecam com "knowledge.") sem
        // serem a mesma secao. Comparar por path evita que todos os itens de
        // um modulo com nomes de rota aninhados fiquem "ativos" ao mesmo tempo.
        $isRouteActive = static function (string $routeName): bool {
            if ($routeName === '' || ! \Illuminate\Support\Facades\Route::has($routeName)) {
                return false;
            }

            $targetPath = '/' . trim((string) parse_url(route($routeName), PHP_URL_PATH), '/');
            $currentPath = '/' . trim(request()->path(), '/');

            return $currentPath === $targetPath || str_starts_with($currentPath, $targetPath . '/');
        };
    @endphp

    <nav class="desktop-nav">
        @foreach ($desktopNavigation as $section)
            <div class="desktop-nav-section">
                <span class="desktop-nav-section-label">{{ $section['label'] }}</span>

                <ul class="desktop-nav-list">
                    @foreach ($section['items'] as $item)
                        @php
                            $children = $item['children'] ?? [];
                            $hasChildren = is_array($children) && $children !== [];
                            $childRoutes = collect($children)
                                ->pluck('route')
                                ->filter()
                                ->map(static fn ($route): string => (string) $route)
                                ->values();
                            $isGroupActive = false;

                            foreach ($childRoutes as $childRoute) {
                                if ($isRouteActive($childRoute)) {
                                    $isGroupActive = true;
                                    break;
                                }
                            }
                        @endphp

                        @if ($hasChildren)
                            <li class="desktop-nav-group {{ $isGroupActive ? 'is-open is-active' : '' }}">
                                <button
                                    type="button"
                                    class="desktop-nav-group-head"
                                    data-desktop-nav-group-toggle
                                    data-label="{{ $item['label'] }}"
                                    aria-label="{{ $item['label'] }}"
                                    aria-expanded="{{ $isGroupActive ? 'true' : 'false' }}"
                                >
                                    <span class="desktop-nav-link {{ $isGroupActive ? 'is-active' : '' }}" data-label="{{ $item['label'] }}">
                                        <i class="bi {{ $item['icon'] }}"></i>
                                        <span>{{ $item['label'] }}</span>
                                    </span>
                                    <i class="bi bi-chevron-down desktop-nav-group-chevron"></i>
                                </button>

                                <ul class="desktop-nav-sublist {{ $isGroupActive ? 'is-open' : '' }}">
                                    @foreach ($children as $child)
                                        @php
                                            $childRoute = (string) ($child['route'] ?? '');
                                            $childRouteExists = $childRoute !== '' && \Illuminate\Support\Facades\Route::has($childRoute);
                                            $childActive = $isRouteActive($childRoute);
                                        @endphp
                                        @if ($childRouteExists)
                                        <li class="desktop-nav-subitem">
                                            <a href="{{ route($childRoute) }}" class="desktop-nav-sublink {{ $childActive ? 'is-active' : '' }}" data-label="{{ $child['label'] ?? '' }}" aria-label="{{ $child['label'] ?? '' }}">
                                                <i class="bi {{ $child['icon'] ?? 'bi-dot' }}"></i>
                                                <span>{{ $child['label'] ?? '' }}</span>
                                            </a>
                                        </li>
                                        @endif
                                    @endforeach
                                </ul>
                            </li>
                        @else
                            @php
                                $routeExists = \Illuminate\Support\Facades\Route::has($item['route'] ?? '');
                                $isActive = $isRouteActive((string) ($item['route'] ?? ''));
                            @endphp
                            @if ($routeExists)
                                <li>
                                    <a href="{{ route($item['route']) }}" class="desktop-nav-link {{ $isActive ? 'is-active' : '' }}" data-label="{{ $item['label'] }}" aria-label="{{ $item['label'] }}">
                                        <i class="bi {{ $item['icon'] }}"></i>
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endif
                        @endif
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
</aside>

<div class="desktop-sidebar-overlay" id="desktopSidebarOverlay"></div>
