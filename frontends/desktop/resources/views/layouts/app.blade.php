@php
    $desktopFlash = [
        'success' => session('success'),
        'error' => session('error'),
        'warning' => session('warning'),
        'info' => session('info'),
    ];
    $desktopSidebarHidden = $desktopSidebarHidden ?? request()->routeIs('orders.index', 'orders.create');
    $desktopSidebarCollapsed = $desktopSidebarCollapsed ?? false;
    $desktopEmbedded = (bool) ($desktopEmbedded ?? $embedded ?? request()->boolean('embedded'));
@endphp
<!DOCTYPE html>
<html lang="pt-BR" @if(session('desktop_theme') && session('desktop_theme') !== 'default') data-theme="{{ session('desktop_theme') }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Desktop ERP' }} | Sistema ERP</title>
    <link href="{{ asset('assets/fonts/plus-jakarta-sans/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap-icons/bootstrap-icons.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/select2/select2.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/select2-bootstrap-5-theme/select2-bootstrap-5-theme.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/desktop.css') }}?v={{ filemtime(public_path('assets/css/desktop.css')) }}" rel="stylesheet">
    @if (session('desktop_theme') && session('desktop_theme') !== 'default')
        <link href="{{ asset('assets/css/themes/' . e(session('desktop_theme')) . '.css') }}" rel="stylesheet">
    @endif
    @yield('styles')
</head>
<body class="desktop-body {{ $desktopEmbedded ? 'desktop-body-embedded' : '' }}">
    @if ($desktopEmbedded)
        <main class="desktop-embedded-shell">
            @include('layouts.partials.flash')
            @yield('content')
        </main>
    @else
        <div class="desktop-shell">
            @include('layouts.partials.sidebar')

            <div class="desktop-main {{ $desktopSidebarHidden ? 'is-full' : ($desktopSidebarCollapsed ? 'is-expanded' : '') }}" id="desktopMain">
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
    @endif

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
        @if (!empty($desktopUser['id']))
        // Tempo real do sino de notificações (canal privado por usuário via Reverb).
        window.__DESKTOP_REALTIME = {{ \Illuminate\Support\Js::from([
            'userId' => (int) ($desktopUser['id'] ?? 0),
            'pusherKey' => env('REVERB_APP_KEY', ''),
            'pusherHost' => env('REVERB_HOST', 'localhost'),
            'pusherPort' => (int) env('REVERB_PORT', 8090),
            'pusherScheme' => env('REVERB_SCHEME', 'http'),
            'broadcastAuthUrl' => \Illuminate\Support\Facades\Route::has('desktop.broadcasting.auth') ? route('desktop.broadcasting.auth') : '',
            'notificationOpenUrlTemplate' => route('notifications.open', ['notification' => '__ID__']),
            'csrfToken' => csrf_token(),
        ]) }};
        @endif
    </script>
    @stack('modals')
    <script src="{{ asset('assets/libs/jquery/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('assets/libs/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/libs/select2/select2.min.js') }}"></script>
    <script src="{{ asset('assets/libs/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/libs/pusher-js/pusher.min.js') }}"></script>
    <script src="{{ asset('assets/js/desktop.js') }}?v={{ filemtime(public_path('assets/js/desktop.js')) }}"></script>
    @yield('scripts')
</body>
</html>
