@extends('layouts.guest')

@php
    $brandingName = trim((string) ($branding['name'] ?? 'Sistema ERP'));
    $hasLogo = (bool) ($branding['has_logo'] ?? false);
    $hasLoginBackground = (bool) ($branding['has_login_background'] ?? false);
    $version = trim((string) ($systemVersion ?? ''));
@endphp

@section('styles')
    @if ($hasLoginBackground)
        <style>
            .desktop-login-body {
                background:
                    linear-gradient(135deg, rgba(237, 245, 255, 0.88), rgba(248, 251, 255, 0.82)),
                    url('{{ route('branding.login.background') }}') center / cover no-repeat;
            }

            .desktop-login-panel {
                background:
                    linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(255, 255, 255, 0.92)),
                    radial-gradient(circle at top right, rgba(56, 104, 176, 0.12), transparent 32%);
            }
        </style>
    @endif
@endsection

@section('content')
    <div class="desktop-login-wrapper">
        <section class="desktop-login-panel-wrap">
            <div class="desktop-login-panel">
                <div class="desktop-login-brand">
                    <div class="desktop-login-logo">
                        @if ($hasLogo)
                            <img src="{{ route('branding.company.logo') }}" alt="Logo {{ $brandingName }}">
                        @else
                            <i class="bi bi-tools"></i>
                        @endif
                    </div>
                    <strong>{{ $brandingName }}</strong>
                </div>

                <form method="post" action="{{ route('login.store') }}" class="d-grid gap-3">
                    @csrf

                    <div>
                        <label for="email">E-mail</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            value="{{ old('email') }}"
                            placeholder="usuario@jovemtech.com"
                            autocomplete="email"
                            required
                        >
                    </div>

                    <div>
                        <label for="password">Senha</label>
                        <div class="input-shell">
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Digite sua senha"
                                autocomplete="current-password"
                                required
                            >
                            <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Mostrar senha">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    @if ($rememberMeEnabled ?? true)
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1" @checked(old('remember')) >
                            <label class="form-check-label" for="remember">Manter-me conectado neste dispositivo</label>
                            <small class="text-muted d-block mt-1">
                                Use apenas em computadores pessoais e de confiança. Em computadores compartilhados ou de clientes, deixe desmarcado — a sessão será encerrada assim que o navegador for fechado.
                            </small>
                        </div>
                    @endif

                    <button type="submit" class="btn btn-primary w-100 py-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i>
                        Entrar no sistema
                    </button>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <a href="{{ route('password.request') }}" class="small fw-semibold text-decoration-none">
                            Esqueci minha senha
                        </a>
                        <span class="small text-muted">O link sera enviado para o e-mail cadastrado.</span>
                    </div>
                </form>

                <div class="desktop-login-footer">
                    <span>{{ $brandingName }}</span>
                    @if ($version !== '')
                        <span>v{{ $version }}</span>
                    @endif
                </div>
            </div>
        </section>
    </div>
@endsection
