@extends('layouts.guest')

@section('content')
    <div class="desktop-login-wrapper">
        <section class="desktop-login-copy">
            <div class="desktop-login-copy-card">
                <span class="desktop-chip mb-3">Fase 7 · Frontend desktop Laravel</span>
                <h1>O legado continua como referência visual, mas o acesso agora passa pelo backend central.</h1>
                <p>
                    Esta aplicação preserva a estrutura do <code>sistema-hml</code>, porém toda regra de negócio,
                    autenticação e autorização agora vêm exclusivamente da API Laravel em <code>backend/</code>.
                </p>

                <div class="desktop-kpis-inline mt-4">
                    <span class="desktop-chip">Sessão server-side</span>
                    <span class="desktop-chip">RBAC pelo <code>auth/me</code></span>
                    <span class="desktop-chip">Sem acesso direto ao banco</span>
                </div>

                <ul>
                    <li>O navegador nunca recebe o token Bearer diretamente.</li>
                    <li>Os módulos do desktop respeitam as permissões efetivas do usuário.</li>
                    <li>A mesma API pode ser reutilizada por mobile, desktop, TV ou novos canais.</li>
                </ul>
            </div>
        </section>

        <section class="desktop-login-panel-wrap">
            <div class="desktop-login-panel">
                <div class="desktop-login-icon">
                    <i class="bi bi-shield-lock"></i>
                </div>
                <h2>Acesso ao desktop</h2>
                <p>Use as mesmas credenciais válidas no backend central do novo ERP.</p>

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
                            placeholder="voce@empresa.com"
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
            </div>
        </section>
    </div>
@endsection
