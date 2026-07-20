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

        <form method="post" action="{{ route('groups.permissions.update', $group['id']) }}" data-permissions-matrix>
            @csrf
            @php
                $canEditMatrix = \App\Support\DesktopSession::can('grupos', 'editar') && empty($group['sistema']);
            @endphp
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th>Módulo</th>
                        @foreach ($permissions as $permission)
                            @php
                                $columnAllSelected = !empty($modules) && collect($modules)
                                    ->every(static fn (array $module): bool => in_array(
                                        $permission['slug'],
                                        $groupPermissions[$module['slug']] ?? [],
                                        true
                                    ));
                            @endphp
                            <th class="text-center">
                                <div class="d-flex flex-column align-items-center gap-2">
                                    <span>{{ $permission['nome'] }}</span>
                                    <button
                                        type="button"
                                        class="btn btn-sm {{ $columnAllSelected ? 'btn-outline-secondary' : 'btn-outline-primary' }} text-nowrap"
                                        data-permission-column-toggle="{{ $permission['slug'] }}"
                                        aria-pressed="{{ $columnAllSelected ? 'true' : 'false' }}"
                                        title="{{ $columnAllSelected ? 'Desmarcar' : 'Marcar' }} a coluna {{ $permission['nome'] }}"
                                        @disabled(!$canEditMatrix)
                                    >
                                        <i class="bi {{ $columnAllSelected ? 'bi-square' : 'bi-check2-square' }} me-1" aria-hidden="true"></i>
                                        <span data-column-toggle-label>{{ $columnAllSelected ? 'Desmarcar coluna' : 'Marcar coluna' }}</span>
                                    </button>
                                </div>
                            </th>
                        @endforeach
                        <th class="text-center text-nowrap">Seleção do módulo</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($modules as $module)
                        @php
                            $selected = $groupPermissions[$module['slug']] ?? [];
                            $allSelected = !empty($permissions) && collect($permissions)
                                ->every(static fn (array $permission): bool => in_array($permission['slug'], $selected, true));
                        @endphp
                        <tr data-permission-row="{{ $module['slug'] }}">
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
                                        data-permission-slug="{{ $permission['slug'] }}"
                                        aria-label="{{ $permission['nome'] }} no módulo {{ $module['nome'] }}"
                                        @checked(in_array($permission['slug'], $selected, true))
                                        @disabled(!$canEditMatrix)
                                    >
                                </td>
                            @endforeach
                            <td class="text-center text-nowrap">
                                <button
                                    type="button"
                                    class="btn btn-sm {{ $allSelected ? 'btn-outline-secondary' : 'btn-outline-primary' }}"
                                    data-module-permission-toggle
                                    aria-pressed="{{ $allSelected ? 'true' : 'false' }}"
                                    @disabled(!$canEditMatrix)
                                >
                                    <i class="bi {{ $allSelected ? 'bi-square' : 'bi-check2-square' }} me-1" aria-hidden="true"></i>
                                    <span data-toggle-label>{{ $allSelected ? 'Desmarcar todas' : 'Selecionar todas' }}</span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if (\App\Support\DesktopSession::can('grupos', 'editar') && empty($group['sistema']))
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4">
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-primary" data-select-all-permissions>
                            <i class="bi bi-check2-square me-2" aria-hidden="true"></i>
                            Marcar todas as permissões
                        </button>
                        <button type="button" class="btn btn-outline-danger" data-clear-all-permissions>
                            <i class="bi bi-x-square me-2" aria-hidden="true"></i>
                            Desmarcar todas as permissões
                        </button>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>
                        Salvar matriz de permissões
                    </button>
                </div>
            @endif
        </form>
    </section>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const matrix = document.querySelector('[data-permissions-matrix]');
            if (!matrix) {
                return;
            }

            const rowCheckboxes = function (row) {
                return Array.from(row.querySelectorAll('input[type="checkbox"][name^="permissions["]'))
                    .filter(function (checkbox) { return !checkbox.disabled; });
            };
            const columnButtons = Array.from(matrix.querySelectorAll('[data-permission-column-toggle]'));
            const columnCheckboxes = function (button) {
                const permissionSlug = button.dataset.permissionColumnToggle || '';

                return editableCheckboxes().filter(function (checkbox) {
                    return checkbox.dataset.permissionSlug === permissionSlug;
                });
            };

            const synchronizeToggle = function (row) {
                const button = row.querySelector('[data-module-permission-toggle]');
                if (!button) {
                    return;
                }

                const checkboxes = rowCheckboxes(row);
                if (checkboxes.length === 0) {
                    button.disabled = true;
                    return;
                }

                const allSelected = checkboxes.every(function (checkbox) { return checkbox.checked; });
                const label = button.querySelector('[data-toggle-label]');
                const icon = button.querySelector('i');

                button.setAttribute('aria-pressed', allSelected ? 'true' : 'false');
                button.classList.toggle('btn-outline-secondary', allSelected);
                button.classList.toggle('btn-outline-primary', !allSelected);
                if (label) {
                    label.textContent = allSelected ? 'Desmarcar todas' : 'Selecionar todas';
                }
                if (icon) {
                    icon.classList.toggle('bi-square', allSelected);
                    icon.classList.toggle('bi-check2-square', !allSelected);
                }
            };

            const synchronizeColumnToggle = function (button) {
                const checkboxes = columnCheckboxes(button);
                const allSelected = checkboxes.length > 0 && checkboxes.every(function (checkbox) {
                    return checkbox.checked;
                });
                const label = button.querySelector('[data-column-toggle-label]');
                const icon = button.querySelector('i');

                button.disabled = checkboxes.length === 0;
                button.setAttribute('aria-pressed', allSelected ? 'true' : 'false');
                button.setAttribute('title', (allSelected ? 'Desmarcar' : 'Marcar') + ' toda esta coluna');
                button.classList.toggle('btn-outline-secondary', allSelected);
                button.classList.toggle('btn-outline-primary', !allSelected);
                if (label) {
                    label.textContent = allSelected ? 'Desmarcar coluna' : 'Marcar coluna';
                }
                if (icon) {
                    icon.classList.toggle('bi-square', allSelected);
                    icon.classList.toggle('bi-check2-square', !allSelected);
                }
            };
            const synchronizeAllColumnToggles = function () {
                columnButtons.forEach(synchronizeColumnToggle);
            };

            const selectAllButton = matrix.querySelector('[data-select-all-permissions]');
            const clearAllButton = matrix.querySelector('[data-clear-all-permissions]');
            const editableCheckboxes = function () {
                return Array.from(matrix.querySelectorAll('input[type="checkbox"][name^="permissions["]'))
                    .filter(function (checkbox) { return !checkbox.disabled; });
            };
            const synchronizeGlobalButtons = function () {
                const checkboxes = editableCheckboxes();
                const hasSelected = checkboxes.some(function (checkbox) { return checkbox.checked; });
                const allSelected = checkboxes.length > 0 && checkboxes.every(function (checkbox) {
                    return checkbox.checked;
                });

                if (selectAllButton) {
                    selectAllButton.disabled = checkboxes.length === 0 || allSelected;
                }
                if (clearAllButton) {
                    clearAllButton.disabled = !hasSelected;
                }
            };

            matrix.querySelectorAll('[data-permission-row]').forEach(synchronizeToggle);
            synchronizeAllColumnToggles();
            synchronizeGlobalButtons();

            matrix.addEventListener('click', function (event) {
                const button = event.target.closest('[data-module-permission-toggle]');
                if (!button || button.disabled || !matrix.contains(button)) {
                    return;
                }

                const row = button.closest('[data-permission-row]');
                const checkboxes = row ? rowCheckboxes(row) : [];
                const shouldSelect = !checkboxes.every(function (checkbox) { return checkbox.checked; });

                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = shouldSelect;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                });
                if (row) {
                    synchronizeToggle(row);
                }
                synchronizeAllColumnToggles();
                synchronizeGlobalButtons();
            });

            matrix.addEventListener('change', function (event) {
                if (!event.target.matches('input[type="checkbox"][name^="permissions["]')) {
                    return;
                }

                const row = event.target.closest('[data-permission-row]');
                if (row) {
                    synchronizeToggle(row);
                }
                synchronizeAllColumnToggles();
                synchronizeGlobalButtons();
            });

            columnButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    if (button.disabled) {
                        return;
                    }

                    const checkboxes = columnCheckboxes(button);
                    const shouldSelect = !checkboxes.every(function (checkbox) { return checkbox.checked; });
                    const affectedRows = new Set();

                    checkboxes.forEach(function (checkbox) {
                        checkbox.checked = shouldSelect;
                        const row = checkbox.closest('[data-permission-row]');
                        if (row) {
                            affectedRows.add(row);
                        }
                    });
                    affectedRows.forEach(synchronizeToggle);
                    synchronizeColumnToggle(button);
                    synchronizeGlobalButtons();
                });
            });

            if (selectAllButton) {
                selectAllButton.addEventListener('click', function () {
                    editableCheckboxes().forEach(function (checkbox) {
                        checkbox.checked = true;
                    });
                    matrix.querySelectorAll('[data-permission-row]').forEach(synchronizeToggle);
                    synchronizeAllColumnToggles();
                    synchronizeGlobalButtons();
                });
            }

            if (clearAllButton) {
                clearAllButton.addEventListener('click', function () {
                    if (clearAllButton.disabled) {
                        return;
                    }

                    const clearMatrix = function () {
                        editableCheckboxes().forEach(function (checkbox) {
                            checkbox.checked = false;
                        });
                        matrix.querySelectorAll('[data-permission-row]').forEach(synchronizeToggle);
                        synchronizeAllColumnToggles();
                        synchronizeGlobalButtons();
                    };

                    if (window.Swal && typeof window.Swal.fire === 'function') {
                        window.Swal.fire({
                            icon: 'warning',
                            title: 'Desmarcar todas as permissões?',
                            text: 'A matriz será limpa. A alteração só será aplicada depois de salvar.',
                            showCancelButton: true,
                            confirmButtonText: 'Sim, desmarcar todas',
                            cancelButtonText: 'Cancelar',
                            confirmButtonColor: '#dc3545',
                        }).then(function (result) {
                            if (result.isConfirmed) {
                                clearMatrix();
                            }
                        });

                        return;
                    }

                    if (window.confirm('Desmarcar todas as permissões desta matriz?')) {
                        clearMatrix();
                    }
                });
            }
        });
    </script>
@endsection
