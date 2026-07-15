@php
    $guestBrandingName = trim((string) ($branding['name'] ?? 'Sistema ERP'));
    $desktopFlash = [
        'success' => session('success'),
        'error' => session('error'),
        'warning' => session('warning'),
        'info' => session('info'),
    ];
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Acesso' }} | {{ $guestBrandingName !== '' ? $guestBrandingName : 'Sistema ERP' }}</title>
    <meta name="description" content="Acesso ao {{ $guestBrandingName !== '' ? $guestBrandingName : 'Sistema ERP' }}, o sistema de gestão de ordens de serviço, financeiro e estoque para assistências técnicas.">
    <link href="{{ asset('assets/fonts/plus-jakarta-sans/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap-icons/bootstrap-icons.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/desktop.css') }}?v={{ filemtime(public_path('assets/css/desktop.css')) }}" rel="stylesheet">
    @yield('styles')
</head>
<body class="desktop-login-body">
    @include('layouts.partials.flash')
    <main>
        @yield('content')
    </main>

    <script>
        window.__DESKTOP_FLASH = {{ \Illuminate\Support\Js::from($desktopFlash) }};
    </script>
    <script src="{{ asset('assets/libs/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/libs/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/desktop.js') }}?v={{ filemtime(public_path('assets/js/desktop.js')) }}"></script>
    @yield('scripts')
</body>
</html>
