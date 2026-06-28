@extends('layouts.app')

@section('content')
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Base de Defeitos</h2>
                <p class="surface-subtitle">
                    Catálogo interno de defeitos comuns por tipo de equipamento, com procedimentos de reparo.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                @if (\App\Support\DesktopSession::can('conhecimento', 'criar'))
                    <a href="{{ route('knowledge.defects.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-2"></i>
                        Novo defeito
                    </a>
                @endif
            </div>
        </div>

        <form method="get" class="desktop-filter-grid">
            <div>
                <label for="search">Busca</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    class="form-control"
                    value="{{ $filters['search'] ?? '' }}"
                    placeholder="Nome ou descrição"
                >
            </div>

            <div>
                <label for="tipo_id">Tipo de equipamento</label>
                <select id="tipo_id" name="tipo_id" class="form-select">
                    <option value="" @selected(($filters['tipo_id'] ?? '') === '' )>Todos</option>
                    @foreach ($equipmentTypes as $type)
                        <option value="{{ $type['id'] }}" @selected((string) ($filters['tipo_id'] ?? '') === (string) $type['id'])>
                            {{ $type['nome'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="classificacao">Classificação</label>
                <select id="classificacao" name="classificacao" class="form-select">
                    <option value="" @selected(($filters['classificacao'] ?? '') === '' )>Todas</option>
                    <option value="hardware" @selected(($filters['classificacao'] ?? '') === 'hardware')>Hardware</option>
                    <option value="software" @selected(($filters['classificacao'] ?? '') === 'software')>Software</option>
                </select>
            </div>

            <div>
                <label for="active">Status</label>
                <select id="active" name="active" class="form-select">
                    <option value="" @selected(($filters['active'] ?? '') === '' )>Todos</option>
                    <option value="1" @selected((string) ($filters['active'] ?? '') === '1')>Ativo</option>
                    <option value="0" @selected((string) ($filters['active'] ?? '') === '0')>Inativo</option>
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

            <div class="field-actions" style="grid-column: span 2;">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Filtrar
                </button>
                <a href="{{ route('knowledge.defects.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Catálogo de defeitos</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} defeitos retornados pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-bug-fill"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($defeitos !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Tipo de equipamento</th>
                        <th>Classificação</th>
                        <th>Procedimentos</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($defeitos as $defeito)
                        @php
                            $defeito = array_merge([
                                'id' => 0,
                                'nome' => '',
                                'tipo_id' => null,
                                'tipo_nome' => '',
                                'classificacao' => 'hardware',
                                'descricao' => '',
                                'ativo' => true,
                                'procedimentos_count' => 0,
                            ], is_array($defeito) ? $defeito : []);

                            $defeitoId = (int) ($defeito['id'] ?? 0);
                            $tipoNome = trim((string) ($defeito['tipo_nome'] ?? ''));
                            $classificacao = (string) ($defeito['classificacao'] ?? 'hardware');
                            $active = (bool) ($defeito['ativo'] ?? false);
                            $procedimentosCount = (int) ($defeito['procedimentos_count'] ?? 0);
                        @endphp
                        <tr>
                            <td data-label="Nome">{{ $defeito['nome'] !== '' ? $defeito['nome'] : 'Sem nome' }}</td>
                            <td data-label="Tipo de equipamento">{{ $tipoNome !== '' ? $tipoNome : '—' }}</td>
                            <td data-label="Classificação">
                                @if ($classificacao === 'software')
                                    <span class="badge bg-info">Software</span>
                                @else
                                    <span class="badge bg-secondary">Hardware</span>
                                @endif
                            </td>
                            <td data-label="Procedimentos">{{ $procedimentosCount }} {{ $procedimentosCount === 1 ? 'passo' : 'passos' }}</td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $active ? 'Ativo' : 'Inativo',
                                    'color' => $active ? '#29c384' : '#8b93a7',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <div class="dropdown supplier-actions-dropdown">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-light dropdown-toggle supplier-actions-toggle"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                    >
                                        <span>Ações</span>
                                        <i class="bi bi-chevron-down"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end supplier-actions-menu">
                                        @if (\App\Support\DesktopSession::can('conhecimento', 'editar'))
                                            <li>
                                                <a href="{{ route('knowledge.defects.edit', $defeitoId) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil me-2"></i>
                                                    Editar
                                                </a>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('conhecimento', 'editar'))
                                            <li>
                                                <form
                                                    method="post"
                                                    action="{{ route('knowledge.defects.toggle-active', $defeitoId) }}"
                                                    data-confirm="Deseja {{ $active ? 'desativar' : 'ativar' }} este defeito?"
                                                    data-confirm-title="{{ $active ? 'Desativar' : 'Ativar' }} defeito"
                                                    data-confirm-button="Sim, {{ $active ? 'desativar' : 'ativar' }}"
                                                >
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="dropdown-item text-warning">
                                                        <i class="bi bi-toggle2-on me-2"></i>
                                                        {{ $active ? 'Desativar' : 'Ativar' }}
                                                    </button>
                                                </form>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('conhecimento', 'excluir'))
                                            <li>
                                                <form
                                                    method="post"
                                                    action="{{ route('knowledge.defects.destroy', $defeitoId) }}"
                                                    data-confirm="Deseja excluir este defeito? Esta ação não pode ser desfeita."
                                                    data-confirm-title="Excluir defeito"
                                                    data-confirm-button="Sim, excluir"
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="dropdown-item text-danger">
                                                        <i class="bi bi-trash me-2"></i>
                                                        Excluir
                                                    </button>
                                                </form>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => $filters])
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-bug-fill',
                'title' => 'Nenhum defeito encontrado',
                'message' => 'Ajuste os filtros ou cadastre o primeiro defeito para começar a base de conhecimento.',
            ])
        @endif
    </section>
@endsection
