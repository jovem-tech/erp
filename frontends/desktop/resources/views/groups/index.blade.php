@extends('layouts.app')

@section('content')
    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Grupos e níveis de acesso</h2>
                <p class="surface-subtitle">A gestão do RBAC continua centralizada no backend. O desktop apenas consome a API administrativa.</p>
            </div>

            @if (\App\Support\DesktopSession::can('grupos', 'criar'))
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#groupCreateModal">
                    <i class="bi bi-plus-lg me-2"></i>
                    Novo grupo
                </button>
            @endif
        </div>

        @if ($groups !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Grupo</th>
                        <th>Descrição</th>
                        <th>Usuários</th>
                        <th>Tipo</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($groups as $group)
                        <tr>
                            <td data-label="Grupo" class="fw-semibold">{{ $group['nome'] !== '' ? $group['nome'] : 'Sem nome' }}</td>
                            <td data-label="Descrição">{{ $group['descricao'] !== '' ? $group['descricao'] : 'Sem descrição' }}</td>
                            <td data-label="Usuários">{{ number_format((int) ($group['users_count'] ?? 0), 0, ',', '.') }}</td>
                            <td data-label="Tipo">
                                @include('layouts.partials.status-pill', [
                                    'label' => !empty($group['sistema']) ? 'Sistema' : 'Customizável',
                                    'color' => !empty($group['sistema']) ? '#ffb84d' : '#4da4ff',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <div class="d-flex flex-wrap gap-2 justify-content-end">
                                    <a href="{{ route('groups.permissions.edit', $group['id']) }}" class="btn btn-sm btn-outline-light">
                                        Permissões
                                    </a>

                                    @if (!empty($group['sistema']))
                                        <button type="button" class="btn btn-sm btn-outline-light" disabled>Protegido</button>
                                    @else
                                        @if (\App\Support\DesktopSession::can('grupos', 'editar'))
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-light"
                                                data-bs-toggle="modal"
                                                data-bs-target="#groupEditModal"
                                                data-modal-fill="#groupEditModal"
                                                data-form-action="{{ route('groups.update', $group['id']) }}"
                                                data-field-nome="{{ $group['nome'] }}"
                                                data-field-descricao="{{ $group['descricao'] }}"
                                            >
                                                Editar
                                            </button>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('grupos', 'excluir'))
                                            <form
                                                method="post"
                                                action="{{ route('groups.destroy', $group['id']) }}"
                                                data-confirm="Esta ação remove o grupo e revoga o vínculo dos usuários afetados. Deseja continuar?"
                                                data-confirm-title="Excluir grupo"
                                                data-confirm-button="Sim, excluir"
                                            >
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-light">Excluir</button>
                                            </form>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-shield-lock',
                'title' => 'Nenhum grupo disponível',
                'message' => 'A API não retornou grupos para o contexto atual.',
            ])
        @endif
    </section>
@endsection

@push('modals')
    @if (\App\Support\DesktopSession::can('grupos', 'criar'))
        <div class="modal fade" id="groupCreateModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-shell">
                        <div class="modal-header">
                            <h2 class="modal-title">Novo grupo</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <form method="post" action="{{ route('groups.store') }}">
                            @csrf
                            <div class="modal-body d-grid gap-3">
                                <div>
                                    <label for="group_nome">Nome</label>
                                    <input type="text" id="group_nome" name="nome" class="form-control" required>
                                </div>
                                <div>
                                    <label for="group_descricao">Descrição</label>
                                    <textarea id="group_descricao" name="descricao" class="form-control" rows="4"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-primary">Criar grupo</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if (\App\Support\DesktopSession::can('grupos', 'editar'))
        <div class="modal fade" id="groupEditModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-shell">
                        <div class="modal-header">
                            <h2 class="modal-title">Editar grupo</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <form method="post" action="#">
                            @csrf
                            <div class="modal-body d-grid gap-3">
                                <div>
                                    <label>Nome</label>
                                    <input type="text" name="nome" class="form-control" data-field="nome" required>
                                </div>
                                <div>
                                    <label>Descrição</label>
                                    <textarea name="descricao" class="form-control" rows="4" data-field="descricao"></textarea>
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
