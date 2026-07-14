@php
    $usersClearUrl = $usersClearUrl ?? route('users.index');
    $usersTabValue = $usersTabValue ?? null;
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
    :clear-url="$usersClearUrl"
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

    @if ($usersTabValue !== null)
        <input type="hidden" name="tab" value="{{ $usersTabValue }}">
    @endif

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
                        $profileLabel = (string) ($user['group']['nome'] ?? ($user['perfil'] ?? ''));
                    @endphp
                    <tr>
                        <td data-label="Nome">
                            <div class="fw-semibold">{{ $user['nome'] !== '' ? $user['nome'] : 'Sem nome' }}</div>
                            <small class="text-secondary">{{ $user['email'] !== '' ? $user['email'] : 'Sem e-mail' }}</small>
                        </td>
                        <td data-label="Perfil">{{ $profileLabel !== '' ? $profileLabel : 'Não informado' }}</td>
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
                                            data-field-perfil="{{ $profileLabel }}"
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
                                            @if ($usersTabValue !== null)
                                                <input type="hidden" name="tab" value="{{ $usersTabValue }}">
                                            @endif
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
