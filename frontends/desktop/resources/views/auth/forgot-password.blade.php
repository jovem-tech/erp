@extends('layouts.guest')

@section('content')
    <div class="desktop-login-wrapper">
        <section class="desktop-login-copy">
            <div class="desktop-login-copy-card">
                <span class="desktop-chip mb-3">Recuperação de acesso</span>
                <h1>Vamos enviar um link de redefinição para o e-mail cadastrado.</h1>
                <p>
                    Informe o endereço de e-mail vinculado à sua conta. Se ele existir no backend central,
                    você receberá um link seguro para criar uma nova senha no desktop.
                </p>

                <div class="desktop-kpis-inline mt-4">
                    <span class="desktop-chip">Envio por e-mail</span>
                    <span class="desktop-chip">Link com token temporário</span>
                    <span class="desktop-chip">Senha nova no desktop</span>
                </div>

                <ul>
                    <li>O link expira em 60 minutos.</li>
                    <li>Não revelamos se o e-mail existe ou não.</li>
                    <li>Após redefinir, faça login novamente para continuar.</li>
                </ul>
            </div>
        </section>

        <section class="desktop-login-panel-wrap">
            <div class="desktop-login-panel">
                <div class="desktop-login-icon">
                    <i class="bi bi-envelope-paper-heart"></i>
                </div>
                <h2>Recuperar senha</h2>
                <p>Digite o e-mail cadastrado para receber o link de redefinição.</p>

                <form method="post" action="{{ route('password.email') }}" class="d-grid gap-3">
                    @csrf

                    <div>
                        <label for="email">E-mail</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            value="{{ old('email', $email) }}"
                            placeholder="voce@empresa.com"
                            autocomplete="email"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-3">
                        <i class="bi bi-send me-2"></i>
                        Enviar link de redefinição
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
