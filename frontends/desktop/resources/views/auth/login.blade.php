@extends('layouts.guest')

@section('content')
    @php
        $brandingName = trim((string) ($branding['name'] ?? 'Sistema ERP'));
        $hasLogo = (bool) ($branding['has_logo'] ?? false);
        $version = trim((string) ($systemVersion ?? ''));
    @endphp

    <div class="desktop-login-wrapper">
        <section class="desktop-login-copy">
            <div class="desktop-login-copy-card">
                <span class="desktop-chip mb-3">
                    <i class="bi bi-tools me-1"></i>
                    Assistência técnica Jovem Tech
                </span>
                <h1>Atendimento técnico, OS e financeiro em uma central de trabalho.</h1>
                <p>
                    Organize diagnósticos, orçamentos, reparos, entregas e recebimentos com rastreabilidade
                    desde a entrada do equipamento até a baixa final da ordem de serviço.
                </p>

                <div class="desktop-login-feature-grid mt-4">
                    <div>
                        <i class="bi bi-clipboard-check"></i>
                        <strong>OS com histórico</strong>
                        <span>Status, checklist, fotos e documentos sempre no mesmo fluxo.</span>
                    </div>
                    <div>
                        <i class="bi bi-cpu"></i>
                        <strong>Equipamentos rastreados</strong>
                        <span>Tipo, marca, modelo, defeito e solução técnica com contexto.</span>
                    </div>
                    <div>
                        <i class="bi bi-cash-coin"></i>
                        <strong>Financeiro integrado</strong>
                        <span>Recebimentos, despesas, DRE e fluxo de caixa conectados à operação.</span>
                    </div>
                </div>
            </div>
        </section>

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
                    <div>
                        <span>Bem-vindo ao</span>
                        <strong>{{ $brandingName }}</strong>
                    </div>
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

                    <button type="submit" class="btn btn-primary w-100 py-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i>
                        Entrar no sistema
                    </button>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <a href="{{ route('password.request') }}" class="small fw-semibold text-decoration-none">
                            Esqueci minha senha
                        </a>
                        <span class="small text-muted">O link será enviado para o e-mail cadastrado.</span>
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
