@extends('layouts.app')

@section('content')
    @php
        $hasActiveFilters = trim((string) ($filters['search'] ?? '')) !== ''
            || trim((string) ($filters['role'] ?? '')) !== ''
            || trim((string) ($filters['active'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['search'] ?? '')) !== '',
            trim((string) ($filters['role'] ?? '')) !== '',
            trim((string) ($filters['active'] ?? '')) !== '',
        ]));
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Pessoas</p>
            <h2 class="surface-title fs-3 mb-2">Equipe da assistência</h2>
            <p class="surface-subtitle mb-0">Cadastre técnicos, administrativo e comercial operacional separadamente dos usuários de acesso.</p>
        </div>
    </div>

    <section class="summary-grid mb-4">
        <article class="summary-card">
            <span class="summary-card-eyebrow">Fonte operacional</span>
            <div class="summary-card-value fs-5">Técnicos da OS saem daqui</div>
            <div class="summary-card-meta">A OS passa a usar a grade da equipe; para assumir OS o membro precisa estar ativo, marcado como técnico e vinculado a um usuário ativo.</div>
        </article>
        <article class="summary-card">
            <span class="summary-card-eyebrow">Usuário do sistema</span>
            <div class="summary-card-value fs-5">Separado da equipe</div>
            <div class="summary-card-meta">Um administrador do sistema também pode ser técnico da assistência, desde que esteja vinculado nesta grade.</div>
        </article>
        <article class="summary-card">
            <span class="summary-card-eyebrow">Evolução futura</span>
            <div class="summary-card-value fs-5">Vendas também virá daqui</div>
            <div class="summary-card-meta">A mesma base já aceita atuação técnica, administrativa e comercial para reaproveitamento no módulo de vendas.</div>
        </article>
    </section>

    <x-list-filters
        form-id="teamMembersFilterPanel"
        search-name="search"
        :search-value="$filters['search'] ?? ''"
        search-placeholder="Nome, cargo, telefone, e-mail ou usuário vinculado"
        :results-count="$pagination['total'] ?? 0"
        results-label="membros"
        :clear-url="route('technicians.index')"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if (\App\Support\DesktopSession::can('funcionarios', 'criar'))
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#teamMemberCreateModal">
                    <i class="bi bi-plus-lg me-2"></i>
                    Novo membro
                </button>
            @endif
        </x-slot:actions>

        <div>
            <label for="role">Atuação</label>
            <select id="role" name="role" class="form-select" data-select2-placeholder="Todas as atuações">
                <option value="">Todas</option>
                <option value="tecnico" @selected(($filters['role'] ?? '') === 'tecnico')>Técnico</option>
                <option value="administrativo" @selected(($filters['role'] ?? '') === 'administrativo')>Administrativo</option>
                <option value="vendas" @selected(($filters['role'] ?? '') === 'vendas')>Vendas</option>
            </select>
        </div>

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
                <h2 class="surface-title">Equipe da assistência</h2>
                <p class="surface-subtitle">{{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} membros operacionais retornados pela API.</p>
            </div>
        </div>

        @if ($teamMembers !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Atuações</th>
                        <th>Usuário vinculado</th>
                        <th>Contato</th>
                        <th>Uso na OS</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($teamMembers as $member)
                        @php
                            $linkedUser = is_array($member['linked_user'] ?? null) ? $member['linked_user'] : [];
                            $linkedGroup = is_array($linkedUser['group'] ?? null) ? $linkedUser['group'] : [];
                            $roles = is_array($member['roles'] ?? null) ? $member['roles'] : [];
                            $isActive = !empty($member['ativo']);
                            $canAssignOrders = !empty($member['can_assign_orders']);
                            $roleLabels = array_values(array_filter([
                                !empty($roles['tecnico']) ? 'Técnico' : null,
                                !empty($roles['administrativo']) ? 'Administrativo' : null,
                                !empty($roles['vendas']) ? 'Vendas' : null,
                            ]));
                        @endphp
                        <tr>
                            <td data-label="Nome">
                                <div class="fw-semibold">{{ $member['nome'] !== '' ? $member['nome'] : 'Sem nome' }}</div>
                                <small class="text-secondary">{{ $member['cargo'] !== '' ? $member['cargo'] : 'Cargo não informado' }}</small>
                            </td>
                            <td data-label="Atuações">
                                <div class="d-flex flex-wrap gap-2">
                                    @forelse ($roleLabels as $roleLabel)
                                        <span class="desktop-chip">{{ $roleLabel }}</span>
                                    @empty
                                        <span class="text-secondary">Sem atuação definida</span>
                                    @endforelse
                                </div>
                            </td>
                            <td data-label="Usuário vinculado">
                                @if ($linkedUser !== [])
                                    <div class="fw-semibold">{{ $linkedUser['nome'] ?? 'Usuário vinculado' }}</div>
                                    <small class="text-secondary">{{ $linkedGroup['nome'] ?? ($linkedUser['perfil'] ?? 'Sem grupo') }}</small>
                                @else
                                    <span class="text-secondary">Sem usuário vinculado</span>
                                @endif
                            </td>
                            <td data-label="Contato">
                                <div>{{ $member['telefone'] !== '' ? $member['telefone'] : 'Telefone não informado' }}</div>
                                <small class="text-secondary">{{ $member['email'] !== '' ? $member['email'] : 'E-mail não informado' }}</small>
                            </td>
                            <td data-label="Uso na OS">
                                @if ($canAssignOrders)
                                    @include('layouts.partials.status-pill', [
                                        'label' => 'Pode assumir OS',
                                        'color' => '#29c384',
                                        'small' => true,
                                    ])
                                @elseif (!empty($roles['tecnico']))
                                    <small class="text-secondary">Técnico sem usuário ativo vinculado</small>
                                @else
                                    <small class="text-secondary">Não técnico</small>
                                @endif
                            </td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $isActive ? 'Ativo' : 'Inativo',
                                    'color' => $isActive ? '#29c384' : '#ff6c7a',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <x-list-actions>
                                    @if (\App\Support\DesktopSession::can('funcionarios', 'editar'))
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item"
                                                data-bs-toggle="modal"
                                                data-bs-target="#teamMemberEditModal"
                                                data-modal-fill="#teamMemberEditModal"
                                                data-form-action="{{ route('technicians.update', $member['id']) }}"
                                                data-field-nome="{{ $member['nome'] }}"
                                                data-field-email="{{ $member['email'] }}"
                                                data-field-telefone="{{ $member['telefone'] }}"
                                                data-field-cargo="{{ $member['cargo'] }}"
                                                data-field-usuario-id="{{ $member['usuario_id'] ?? '' }}"
                                                data-field-observacoes="{{ $member['observacoes'] ?? '' }}"
                                                data-field-atua-tecnico="{{ !empty($roles['tecnico']) ? '1' : '0' }}"
                                                data-field-atua-vendas="{{ !empty($roles['vendas']) ? '1' : '0' }}"
                                                data-field-atua-administrativo="{{ !empty($roles['administrativo']) ? '1' : '0' }}"
                                                data-field-ativo="{{ $isActive ? '1' : '0' }}"
                                            >
                                                <i class="bi bi-pencil me-2"></i>
                                                Editar
                                            </button>
                                        </li>

                                        <li>
                                            <form
                                                method="post"
                                                action="{{ route('technicians.active.update', $member['id']) }}"
                                                data-confirm="Deseja alterar o status deste membro da equipe?"
                                                data-confirm-title="Atualizar status da equipe"
                                            >
                                                @csrf
                                                @method('PATCH')
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
                'title' => 'Nenhum membro encontrado',
                'message' => 'Não houve retorno de equipe para os filtros atuais.',
            ])
        @endif
    </section>
@endsection

@push('modals')
    @if (\App\Support\DesktopSession::can('funcionarios', 'criar'))
        <div class="modal fade" id="teamMemberCreateModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-shell">
                        <div class="modal-header">
                            <h2 class="modal-title">Novo membro da equipe</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <form method="post" action="{{ route('technicians.store') }}">
                            @csrf
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="create_team_nome">Nome</label>
                                        <input type="text" id="create_team_nome" name="nome" class="form-control" value="{{ old('nome') }}" data-person-name-input required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="create_team_cargo">Cargo</label>
                                        <input type="text" id="create_team_cargo" name="cargo" class="form-control" value="{{ old('cargo') }}" placeholder="Ex.: Técnico, Atendimento, Comercial">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="create_team_email">E-mail</label>
                                        <input type="email" id="create_team_email" name="email" class="form-control" value="{{ old('email') }}">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="create_team_telefone">Telefone</label>
                                        <input type="text" id="create_team_telefone" name="telefone" class="form-control" value="{{ old('telefone') }}">
                                    </div>
                                    <div class="col-12">
                                        <label for="create_team_usuario_id">Usuário do sistema vinculado (opcional)</label>
                                        <select id="create_team_usuario_id" name="usuario_id" class="form-select" data-select2-placeholder="Selecione um usuário ativo">
                                            <option value="">Sem vínculo de acesso</option>
                                            @foreach ($availableUsers as $user)
                                                @php
                                                    $group = is_array($user['group'] ?? null) ? $user['group'] : [];
                                                    $groupLabel = trim((string) ($group['nome'] ?? ($user['perfil'] ?? '')));
                                                @endphp
                                                <option value="{{ $user['id'] }}" @selected((string) old('usuario_id') === (string) $user['id'])>
                                                    {{ $user['nome'] }}{{ $groupLabel !== '' ? ' — ' . $groupLabel : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="text-secondary d-block mt-2">Se este membro for técnico da OS, o vínculo com usuário ativo habilita uso no fluxo operacional atual.</small>
                                    </div>
                                    <div class="col-12">
                                        <label class="d-block mb-2">Atuações</label>
                                        <div class="d-flex flex-wrap gap-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="create_team_atua_tecnico" name="atua_tecnico" @checked(old('atua_tecnico', '1') === '1')>
                                                <label class="form-check-label" for="create_team_atua_tecnico">Técnico</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="create_team_atua_administrativo" name="atua_administrativo" @checked(old('atua_administrativo') === '1')>
                                                <label class="form-check-label" for="create_team_atua_administrativo">Administrativo</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="create_team_atua_vendas" name="atua_vendas" @checked(old('atua_vendas') === '1')>
                                                <label class="form-check-label" for="create_team_atua_vendas">Vendas</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label for="create_team_observacoes">Observações</label>
                                        <textarea id="create_team_observacoes" name="observacoes" class="form-control" rows="3" placeholder="Observações operacionais opcionais">{{ old('observacoes') }}</textarea>
                                    </div>
                                    <div class="col-12 d-flex align-items-end">
                                        <div class="form-check mt-3">
                                            <input class="form-check-input" type="checkbox" value="1" id="create_team_ativo" name="ativo" @checked(old('ativo', '1') === '1')>
                                            <label class="form-check-label" for="create_team_ativo">Membro ativo</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-primary">Salvar membro</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if (\App\Support\DesktopSession::can('funcionarios', 'editar'))
        <div class="modal fade" id="teamMemberEditModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-shell">
                        <div class="modal-header">
                            <h2 class="modal-title">Editar membro da equipe</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <form method="post" action="#">
                            @csrf
                            @method('PATCH')
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label>Nome</label>
                                        <input type="text" name="nome" class="form-control" data-field="nome" data-person-name-input required>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Cargo</label>
                                        <input type="text" name="cargo" class="form-control" data-field="cargo">
                                    </div>
                                    <div class="col-md-6">
                                        <label>E-mail</label>
                                        <input type="email" name="email" class="form-control" data-field="email">
                                    </div>
                                    <div class="col-md-6">
                                        <label>Telefone</label>
                                        <input type="text" name="telefone" class="form-control" data-field="telefone">
                                    </div>
                                    <div class="col-12">
                                        <label>Usuário do sistema vinculado (opcional)</label>
                                        <select name="usuario_id" class="form-select" data-field="usuarioId" data-select2-placeholder="Selecione um usuário ativo">
                                            <option value="">Sem vínculo de acesso</option>
                                            @foreach ($availableUsers as $user)
                                                @php
                                                    $group = is_array($user['group'] ?? null) ? $user['group'] : [];
                                                    $groupLabel = trim((string) ($group['nome'] ?? ($user['perfil'] ?? '')));
                                                @endphp
                                                <option value="{{ $user['id'] }}">{{ $user['nome'] }}{{ $groupLabel !== '' ? ' — ' . $groupLabel : '' }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="d-block mb-2">Atuações</label>
                                        <div class="d-flex flex-wrap gap-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="edit_team_atua_tecnico" name="atua_tecnico" data-field="atuaTecnico">
                                                <label class="form-check-label" for="edit_team_atua_tecnico">Técnico</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="edit_team_atua_administrativo" name="atua_administrativo" data-field="atuaAdministrativo">
                                                <label class="form-check-label" for="edit_team_atua_administrativo">Administrativo</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="edit_team_atua_vendas" name="atua_vendas" data-field="atuaVendas">
                                                <label class="form-check-label" for="edit_team_atua_vendas">Vendas</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label>Observações</label>
                                        <textarea name="observacoes" class="form-control" rows="3" data-field="observacoes"></textarea>
                                    </div>
                                    <div class="col-12 d-flex align-items-end">
                                        <div class="form-check mt-3">
                                            <input class="form-check-input" type="checkbox" value="1" id="edit_team_ativo" name="ativo" data-field="ativo">
                                            <label class="form-check-label" for="edit_team_ativo">Membro ativo</label>
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

@section('scripts')
    <script>
        (() => {
            const lowercaseConnectors = new Set(['da', 'das', 'de', 'di', 'do', 'dos', 'du', 'e']);

            const titleCaseToken = (token) => token
                .split('-')
                .map((part) => part === ''
                    ? part
                    : part.charAt(0).toLocaleUpperCase('pt-BR') + part.slice(1)
                )
                .join('-');

            const normalizePersonName = (value) => {
                const words = String(value || '')
                    .trim()
                    .replace(/\s+/g, ' ')
                    .toLocaleLowerCase('pt-BR')
                    .split(' ')
                    .filter(Boolean);

                return words
                    .map((word, index) => index > 0 && lowercaseConnectors.has(word) ? word : titleCaseToken(word))
                    .join(' ');
            };

            const applyNameMask = (input) => {
                if (!(input instanceof HTMLInputElement)) {
                    return;
                }

                input.value = normalizePersonName(input.value);
            };

            document.querySelectorAll('[data-person-name-input]').forEach((input) => {
                input.addEventListener('blur', () => applyNameMask(input));
            });

            document.querySelectorAll('.modal form').forEach((form) => {
                form.addEventListener('submit', () => {
                    form.querySelectorAll('[data-person-name-input]').forEach(applyNameMask);
                });
            });
        })();
    </script>
@endsection
