@php
    $desktopFlash = [
        'success' => session('success'),
        'error' => session('error'),
        'warning' => session('warning'),
        'info' => session('info'),
    ];
    $desktopSidebarCollapsed = $desktopSidebarCollapsed ?? request()->routeIs('orders.index');
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Desktop ERP' }} | Sistema ERP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="{{ asset('assets/css/desktop.css') }}?v={{ filemtime(public_path('assets/css/desktop.css')) }}" rel="stylesheet">
    @yield('styles')
</head>
<body class="desktop-body">
    <div class="desktop-shell">
        @include('layouts.partials.sidebar')

        <div class="desktop-main {{ $desktopSidebarCollapsed ? 'is-expanded' : '' }}" id="desktopMain">
            @include('layouts.partials.navbar')

            <main class="desktop-content">
                @include('layouts.partials.flash')
                @yield('content')
            </main>

            @php
                $footerVersion = data_get($desktopSystemFooter, 'version', config('app.version', '3.0.0'));
                $footerCopyright = data_get($desktopSystemFooter, 'copyright', '(c) ' . date('Y') . ' ' . config('app.name', 'Sistema ERP Desktop'));
                $footerDevelopedBy = data_get($desktopSystemFooter, 'developed_by', 'Jovem Tech');
            @endphp

            <footer class="desktop-system-footer" aria-label="Rodape institucional do sistema">
                <div class="desktop-system-footer-inner">
                    <div class="desktop-system-footer-meta">
                        <span class="desktop-version-pill" title="Versao atual do sistema">v{{ $footerVersion }}</span>
                        <span class="desktop-system-footer-credit">Desenvolvido por {{ $footerDevelopedBy }}</span>
                        <span class="desktop-system-footer-copyright">{{ $footerCopyright }}</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <div class="desktop-page-loader" data-desktop-page-loader hidden aria-hidden="true">
        <div class="desktop-page-loader-card" role="status" aria-live="polite" aria-busy="true">
            <span class="spinner-border text-primary desktop-page-loader-spinner" aria-hidden="true"></span>
            <div class="desktop-page-loader-copy">
                <strong>Carregando página</strong>
                <span>Preparando a próxima tela...</span>
            </div>
        </div>
    </div>

    <script>
        window.__DESKTOP_FLASH = {{ \Illuminate\Support\Js::from($desktopFlash) }};
    </script>
    @stack('modals')
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="{{ asset('assets/js/desktop.js') }}?v={{ filemtime(public_path('assets/js/desktop.js')) }}"></script>
    @yield('scripts')
</body>
</html>
