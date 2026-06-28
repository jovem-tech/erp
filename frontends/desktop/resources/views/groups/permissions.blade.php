@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">RBAC central</p>
            <h2 class="surface-title fs-3 mb-2">{{ $group['nome'] !== '' ? $group['nome'] : 'Grupo sem nome' }}</h2>
            <div class="d-flex flex-wrap gap-2">
                @include('layouts.partials.status-pill', [
                    'label' => !empty($group['sistema']) ? 'Grupo de sistema' : 'Grupo editável',
                    'color' => !empty($group['sistema']) ? '#ffb84d' : '#4da4ff',
                ])
                <span class="desktop-chip">{{ number_format((int) ($group['users_count'] ?? 0), 0, ',', '.') }} usuários vinculados</span>
            </div>
        </div>

        <a href="{{ route('groups.index') }}" class="btn btn-outline-light align-self-start">
            <i class="bi bi-arrow-left me-2"></i>
            Voltar
        </a>
    </div>

    @if (!empty($group['descricao']))
        <section class="surface-card mb-4">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Descrição do grupo</h2>
                    <p class="surface-subtitle">Contexto de negócio usado para orientar a matriz de permissões.</p>
                </div>
            </div>
            <p class="mb-0">{{ $group['descricao'] }}</p>
        </section>
    @endif

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Matriz de permissões</h2>
                <p class="surface-subtitle">O desktop apenas apresenta e envia a matriz. A regra de autorização continua no backend central.</p>
            </div>
        </div>

        @if (!empty($group['sistema']))
            <div class="alert-shell alert-shell-danger mb-4">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi bi-lock-fill"></i>
                    <div>
                        <strong>Este grupo é imutável.</strong>
                        <div>Grupos marcados com <code>sistema = 1</code> não podem ser alterados nem no backend nem no frontend desktop.</div>
                    </div>
                </div>
            </div>
        @endif

        <form method="post" action="{{ route('groups.permissions.update', $group['id']) }}">
            @csrf
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th>Módulo</th>
                        @foreach ($permissions as $permission)
                            <th class="text-center">{{ $permission['nome'] }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($modules as $module)
                        @php
                            $selected = $groupPermissions[$module['slug']] ?? [];
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $module['nome'] }}</div>
                                <small class="text-secondary">{{ $module['slug'] }}</small>
                            </td>
                            @foreach ($permissions as $permission)
                                <td class="text-center">
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        name="permissions[{{ $module['slug'] }}][]"
                                        value="{{ $permission['slug'] }}"
                                        @checked(in_array($permission['slug'], $selected, true))
                                        @disabled(!\App\Support\DesktopSession::can('grupos', 'editar') || !empty($group['sistema']))
                                    >
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if (\App\Support\DesktopSession::can('grupos', 'editar') && empty($group['sistema']))
                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>
                        Salvar matriz de permissões
                    </button>
                </div>
            @endif
        </form>
    </section>
@endsection
