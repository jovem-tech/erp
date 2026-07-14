@if (\App\Support\DesktopSession::can('usuarios', 'criar'))
    <div class="modal fade" id="userCreateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-shell">
                    <div class="modal-header">
                        <h2 class="modal-title">Novo usuário</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <form method="post" action="{{ route('users.store') }}">
                        @csrf
                        @if (($usersTabValue ?? null) !== null)
                            <input type="hidden" name="tab" value="{{ $usersTabValue }}">
                        @endif
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="create_nome">Nome</label>
                                    <input type="text" id="create_nome" name="nome" class="form-control" value="{{ old('nome') }}" data-person-name-input required>
                                </div>
                                <div class="col-md-6">
                                    <label for="create_email">E-mail</label>
                                    <input type="email" id="create_email" name="email" class="form-control" value="{{ old('email') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="create_telefone">Telefone</label>
                                    <input type="text" id="create_telefone" name="telefone" class="form-control" value="{{ old('telefone') }}">
                                </div>
                                <div class="col-md-3">
                                    <label for="create_perfil">Perfil</label>
                                    <input
                                        type="text"
                                        id="create_perfil"
                                        class="form-control"
                                        value=""
                                        placeholder="Selecione um grupo"
                                        data-user-profile-display
                                        readonly
                                        tabindex="-1"
                                    >
                                </div>
                                <div class="col-md-3">
                                    <label for="create_grupo_id">Grupo</label>
                                    <select id="create_grupo_id" name="grupo_id" class="form-select" data-user-group-select data-user-profile-target="#create_perfil" required>
                                        <option value="">Selecione o grupo</option>
                                        @foreach ($groups as $group)
                                            <option value="{{ $group['id'] }}" @selected((string) old('grupo_id') === (string) $group['id'])>{{ $group['nome'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="create_password">Senha inicial</label>
                                    <input type="password" id="create_password" name="password" class="form-control" required>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" value="1" id="create_ativo" name="ativo" @checked(old('ativo', '1') === '1')>
                                        <label class="form-check-label" for="create_ativo">Usuário ativo</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Criar usuário</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endif

@if (\App\Support\DesktopSession::can('usuarios', 'editar'))
    <div class="modal fade" id="userEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-shell">
                    <div class="modal-header">
                        <h2 class="modal-title">Editar usuário</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <form method="post" action="#">
                        @csrf
                        @if (($usersTabValue ?? null) !== null)
                            <input type="hidden" name="tab" value="{{ $usersTabValue }}">
                        @endif
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label>Nome</label>
                                    <input type="text" name="nome" class="form-control" data-field="nome" data-person-name-input required>
                                </div>
                                <div class="col-md-6">
                                    <label>E-mail</label>
                                    <input type="email" name="email" class="form-control" data-field="email" required>
                                </div>
                                <div class="col-md-6">
                                    <label>Telefone</label>
                                    <input type="text" name="telefone" class="form-control" data-field="telefone">
                                </div>
                                <div class="col-md-3">
                                    <label>Perfil</label>
                                    <input
                                        type="text"
                                        id="edit_perfil"
                                        class="form-control"
                                        data-field="perfil"
                                        data-user-profile-display
                                        readonly
                                        tabindex="-1"
                                    >
                                </div>
                                <div class="col-md-3">
                                    <label>Grupo</label>
                                    <select name="grupo_id" class="form-select" data-field="grupoId" data-user-group-select data-user-profile-target="#edit_perfil" required>
                                        <option value="">Selecione o grupo</option>
                                        @foreach ($groups as $group)
                                            <option value="{{ $group['id'] }}">{{ $group['nome'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button type="button" class="btn btn-outline-light btn-sm" data-user-password-toggle>
                                        <i class="bi bi-key me-2"></i>
                                        Alterar senha
                                    </button>
                                </div>
                                <div class="col-md-6 d-none" data-user-password-fields>
                                    <label>Nova senha</label>
                                    <input type="password" name="password" class="form-control" placeholder="Digite a nova senha" disabled autocomplete="new-password">
                                </div>
                                <div class="col-md-6 d-none" data-user-password-fields>
                                    <label>Confirmar nova senha</label>
                                    <input type="password" name="password_confirmation" class="form-control" placeholder="Repita a nova senha" disabled autocomplete="new-password">
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" value="1" id="edit_ativo" name="ativo" data-field="ativo">
                                        <label class="form-check-label" for="edit_ativo">Usuário ativo</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Salvar alterações</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endif
