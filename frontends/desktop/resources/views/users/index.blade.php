@extends('layouts.app')

@section('content')
    @php
        $hasActiveFilters = trim((string) ($filters['search'] ?? '')) !== ''
            || trim((string) ($filters['active'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['search'] ?? '')) !== '',
            trim((string) ($filters['active'] ?? '')) !== '',
        ]));
    @endphp

    <x-list-filters
        form-id="usersFilterPanel"
        search-name="search"
        :search-value="$filters['search'] ?? ''"
        search-placeholder="Nome ou e-mail"
        :results-count="$pagination['total'] ?? 0"
        results-label="usuários"
        :clear-url="route('users.index')"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if (\App\Support\DesktopSession::can('usuarios', 'criar'))
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userCreateModal">
                    <i class="bi bi-plus-lg me-2"></i>
                    Novo usuário
                </button>
            @endif
        </x-slot:actions>

        <div>
            <label for="active">Status</label>
            <select id="active" name="active" class="form-select">
                <option value="">Todos</option>
                <option value="1" @selected(($filters['active'] ?? '') === '1')>Ativos</option>
                <option value="0" @selected(($filters['active'] ?? '') === '0')>Inativos</option>
            </select>
        </div>

        <div>
            <label for="per_page">Itens por página</label>
            <select id="per_page" name="per_page" class="form-select">
                @foreach ([15, 30, 50] as $size)
                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 15) === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
    </x-list-filters>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Usuários</h2>
                <p class="surface-subtitle">{{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} usuários retornados pela API administrativa.</p>
            </div>
        </div>

        @if ($users !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Perfil</th>
                        <th>Grupo</th>
                        <th>Contato</th>
                        <th>Status</th>
                        <th>Último acesso</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($users as $user)
                        @php
                            $isActive = !empty($user['ativo']);
                        @endphp
                        <tr>
                            <td data-label="Nome">
                                <div class="fw-semibold">{{ $user['nome'] !== '' ? $user['nome'] : 'Sem nome' }}</div>
                                <small class="text-secondary">{{ $user['email'] !== '' ? $user['email'] : 'Sem e-mail' }}</small>
                            </td>
                            <td data-label="Perfil">{{ $user['perfil'] !== '' ? ucfirst($user['perfil']) : 'Não informado' }}</td>
                            <td data-label="Grupo">{{ $user['group']['nome'] ?? 'Sem grupo' }}</td>
                            <td data-label="Contato">{{ $user['telefone'] !== '' ? $user['telefone'] : 'Não informado' }}</td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $isActive ? 'Ativo' : 'Inativo',
                                    'color' => $isActive ? '#29c384' : '#ff6c7a',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Último acesso">{{ $user['ultimo_acesso'] ?? 'Nunca registrado' }}</td>
                            <td data-label="Ações" class="text-end">
                                <x-list-actions>
                                    @if (\App\Support\DesktopSession::can('usuarios', 'editar'))
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item"
                                                data-bs-toggle="modal"
                                                data-bs-target="#userEditModal"
                                                data-modal-fill="#userEditModal"
                                                data-form-action="{{ route('users.update', $user['id']) }}"
                                                data-field-nome="{{ $user['nome'] }}"
                                                data-field-email="{{ $user['email'] }}"
                                                data-field-telefone="{{ $user['telefone'] }}"
                                                data-field-perfil="{{ $user['perfil'] }}"
                                                data-field-grupo-id="{{ $user['grupo_id'] ?: '' }}"
                                                data-field-ativo="{{ $isActive ? '1' : '0' }}"
                                            >
                                                <i class="bi bi-pencil me-2"></i>
                                                Editar
                                            </button>
                                        </li>

                                        <li>
                                            <form
                                                method="post"
                                                action="{{ route('users.active.update', $user['id']) }}"
                                                data-confirm="Deseja alterar o status deste usuário?"
                                                data-confirm-title="Atualizar status do usuário"
                                            >
                                                @csrf
                                                <input type="hidden" name="active" value="{{ $isActive ? '0' : '1' }}">
                                                <button type="submit" class="dropdown-item {{ $isActive ? 'text-warning' : '' }}">
                                                    <i class="bi {{ $isActive ? 'bi-person-dash' : 'bi-person-check' }} me-2"></i>
                                                    {{ $isActive ? 'Desativar' : 'Ativar' }}
                                                </button>
                                            </form>
                                        </li>
                                    @endif
                                </x-list-actions>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => $filters])
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-people',
                'title' => 'Nenhum usuário encontrado',
                'message' => 'Não houve retorno de usuários para os filtros atuais.',
            ])
        @endif
    </section>
@endsection

@push('modals')
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
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="create_nome">Nome</label>
                                        <input type="text" id="create_nome" name="nome" class="form-control" value="{{ old('nome') }}" required>
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
                                        <input type="text" id="create_perfil" name="perfil" class="form-control" value="{{ old('perfil', 'atendente') }}" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="create_grupo_id">Grupo</label>
                                        <select id="create_grupo_id" name="grupo_id" class="form-select">
                                            <option value="">Sem grupo</option>
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
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label>Nome</label>
                                        <input type="text" name="nome" class="form-control" data-field="nome" required>
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
                                        <input type="text" name="perfil" class="form-control" data-field="perfil" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label>Grupo</label>
                                        <select name="grupo_id" class="form-select" data-field="grupoId">
                                            <option value="">Sem grupo</option>
                                            @foreach ($groups as $group)
                                                <option value="{{ $group['id'] }}">{{ $group['nome'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Nova senha</label>
                                        <input type="password" name="password" class="form-control" placeholder="Preencha apenas se quiser trocar">
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
@endpush
