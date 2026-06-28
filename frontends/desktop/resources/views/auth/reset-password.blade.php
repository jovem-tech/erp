@extends('layouts.guest')

@section('content')
    <div class="desktop-login-wrapper">
        <section class="desktop-login-copy">
            <div class="desktop-login-copy-card">
                <span class="desktop-chip mb-3">Redefinição segura</span>
                <h1>Crie uma nova senha para acessar o desktop novamente.</h1>
                <p>
                    O token de recuperação foi validado para o e-mail informado. Defina a nova senha
                    e entre novamente no Sistema ERP Desktop.
                </p>

                <div class="desktop-kpis-inline mt-4">
                    <span class="desktop-chip">Token temporário</span>
                    <span class="desktop-chip">Nova senha protegida</span>
                    <span class="desktop-chip">Sessão renovada</span>
                </div>

                <ul>
                    <li>A senha anterior deixa de valer após a troca.</li>
                    <li>Todos os tokens ativos do backend central são revogados.</li>
                    <li>Use uma senha forte e exclusiva.</li>
                </ul>
            </div>
        </section>

        <section class="desktop-login-panel-wrap">
            <div class="desktop-login-panel">
                <div class="desktop-login-icon">
                    <i class="bi bi-shield-lock"></i>
                </div>
                <h2>Nova senha</h2>
                <p>Confirme os dados e escolha sua nova senha de acesso.</p>

                <form method="post" action="{{ route('password.update') }}" class="d-grid gap-3">
                    @csrf

                    <input type="hidden" name="token" value="{{ $token }}">

                    <div>
                        <label for="email">E-mail</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            value="{{ old('email', $email) }}"
                            autocomplete="email"
                            required
                        >
                    </div>

                    <div>
                        <label for="password">Nova senha</label>
                        <div class="input-shell">
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Digite a nova senha"
                                autocomplete="new-password"
                                required
                            >
                            <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Mostrar senha">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label for="password_confirmation">Confirmar senha</label>
                        <div class="input-shell">
                            <input
                                type="password"
                                class="form-control"
                                id="password_confirmation"
                                name="password_confirmation"
                                placeholder="Repita a nova senha"
                                autocomplete="new-password"
                                required
                            >
                            <button type="button" class="password-toggle" data-password-toggle="password_confirmation" aria-label="Mostrar confirmação">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-3">
                        <i class="bi bi-lock-fill me-2"></i>
                        Redefinir senha
                    </button>

                    <div class="text-center">
                        <a href="{{ route('login') }}" class="small fw-semibold text-decoration-none">
                            Voltar para o login
                        </a>
                    </div>
                </form>
            </div>
        </section>
    </div>
@endsection
