@extends('layouts.app')

@section('content')
    <section class="desktop-grid desktop-grid-two">
        <article class="desktop-form-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Configurações do perfil</h2>
                    <p class="surface-subtitle">Atualize o nome exibido no desktop.</p>
                </div>
            </div>

            <form method="post" action="{{ route('profile.update') }}" class="desktop-form-stack">
                @csrf
                @method('patch')

                <div>
                    <label for="profileName">Nome</label>
                    <input
                        type="text"
                        id="profileName"
                        name="nome"
                        class="form-control"
                        value="{{ old('nome', $profile['nome'] ?? '') }}"
                        maxlength="100"
                        required
                    >
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        Salvar alterações
                    </button>
                </div>
            </form>
        </article>

        <article class="desktop-form-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Trocar senha</h2>
                    <p class="surface-subtitle">Após salvar, o desktop pedirá novo login.</p>
                </div>
            </div>

            <form method="post" action="{{ route('profile.password.update') }}" class="desktop-form-stack">
                @csrf
                @method('patch')

                <div>
                    <label for="currentPassword">Senha atual</label>
                    <div class="input-shell">
                        <input type="password" id="currentPassword" name="current_password" class="form-control" minlength="6" required>
                        <button type="button" class="password-toggle" data-password-toggle="currentPassword" aria-label="Mostrar senha atual">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div>
                    <label for="newPassword">Nova senha</label>
                    <div class="input-shell">
                        <input type="password" id="newPassword" name="password" class="form-control" minlength="8" required>
                        <button type="button" class="password-toggle" data-password-toggle="newPassword" aria-label="Mostrar nova senha">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div>
                    <label for="newPasswordConfirmation">Confirmar nova senha</label>
                    <div class="input-shell">
                        <input type="password" id="newPasswordConfirmation" name="password_confirmation" class="form-control" minlength="8" required>
                        <button type="button" class="password-toggle" data-password-toggle="newPasswordConfirmation" aria-label="Mostrar confirmação">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-soft">
                        Atualizar senha
                    </button>
                </div>
            </form>
        </article>
    </section>
@endsection
