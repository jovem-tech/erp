@php
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
    <title>{{ $pageTitle ?? 'Acesso' }} | Sistema ERP</title>
    <link href="{{ asset('assets/fonts/plus-jakarta-sans/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap-icons/bootstrap-icons.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/desktop.css') }}" rel="stylesheet">
    @yield('styles')
</head>
<body class="desktop-login-body">
    @include('layouts.partials.flash')
    @yield('content')

    <script>
        window.__DESKTOP_FLASH = {{ \Illuminate\Support\Js::from($desktopFlash) }};
    </script>
    <script src="{{ asset('assets/libs/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/libs/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/desktop.js') }}"></script>
    @yield('scripts')
</body>
</html>
