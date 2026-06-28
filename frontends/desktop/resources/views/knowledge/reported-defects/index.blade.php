@extends('layouts.app')

@section('content')
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Defeitos Relatados</h2>
                <p class="surface-subtitle">
                    Catálogo de relatos de defeito exibido para o cliente na abertura de OS.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                @if (\App\Support\DesktopSession::can('conhecimento', 'criar'))
                    <a href="{{ route('knowledge.reported-defects.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-2"></i>
                        Novo defeito relatado
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
                    placeholder="Relato, categoria ou subcategoria"
                >
            </div>

            <div>
                <label for="tipo_equipamento_id">Tipo de equipamento</label>
                <select id="tipo_equipamento_id" name="tipo_equipamento_id" class="form-select">
                    <option value="" @selected(($filters['tipo_equipamento_id'] ?? '') === '' )>Todos</option>
                    @foreach ($equipmentTypes as $type)
                        <option value="{{ $type['id'] }}" @selected((string) ($filters['tipo_equipamento_id'] ?? '') === (string) $type['id'])>
                            {{ $type['nome'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="categoria">Categoria</label>
                <input
                    type="text"
                    id="categoria"
                    name="categoria"
                    class="form-control"
                    value="{{ $filters['categoria'] ?? '' }}"
                    placeholder="Categoria exata"
                >
            </div>

            <div>
                <label for="subcategoria">Subcategoria</label>
                <input
                    type="text"
                    id="subcategoria"
                    name="subcategoria"
                    class="form-control"
                    value="{{ $filters['subcategoria'] ?? '' }}"
                    placeholder="Subcategoria exata"
                >
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
                <a href="{{ route('knowledge.reported-defects.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Catálogo de defeitos relatados</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} defeitos relatados retornados pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-chat-square-text-fill"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($defeitos !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Tipo de equipamento</th>
                        <th>Categoria</th>
                        <th>Subcategoria</th>
                        <th>Relato</th>
                        <th>Ícone</th>
                        <th>Ordem</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($defeitos as $defeito)
                        @php
                            $defeito = array_merge([
                                'id' => 0,
                                'tipo_equipamento_id' => null,
                                'tipo_equipamento_nome' => '',
                                'categoria' => '',
                                'subcategoria' => '',
                                'texto_relato' => '',
                                'icone' => '',
                                'ordem_exibicao' => 0,
                                'ativo' => true,
                            ], is_array($defeito) ? $defeito : []);

                            $defeitoId = (int) ($defeito['id'] ?? 0);
                            $tipoNome = trim((string) ($defeito['tipo_equipamento_nome'] ?? ''));
                            $icone = trim((string) ($defeito['icone'] ?? ''));
                            $active = (bool) ($defeito['ativo'] ?? false);
                        @endphp
                        <tr>
                            <td data-label="Tipo de equipamento">{{ $tipoNome !== '' ? $tipoNome : 'Sem tipo específico' }}</td>
                            <td data-label="Categoria">{{ $defeito['categoria'] !== '' ? $defeito['categoria'] : '—' }}</td>
                            <td data-label="Subcategoria">{{ $defeito['subcategoria'] !== '' ? $defeito['subcategoria'] : '—' }}</td>
                            <td data-label="Relato">{{ $defeito['texto_relato'] !== '' ? $defeito['texto_relato'] : '—' }}</td>
                            <td data-label="Ícone">
                                @if ($icone !== '')
                                    <i class="bi {{ $icone }}"></i>
                                    <code>{{ $icone }}</code>
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="Ordem">{{ (int) ($defeito['ordem_exibicao'] ?? 0) }}</td>
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
                                                <a href="{{ route('knowledge.reported-defects.edit', $defeitoId) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil me-2"></i>
                                                    Editar
                                                </a>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('conhecimento', 'editar'))
                                            <li>
                                                <form
                                                    method="post"
                                                    action="{{ route('knowledge.reported-defects.toggle-active', $defeitoId) }}"
                                                    data-confirm="Deseja {{ $active ? 'desativar' : 'ativar' }} este defeito relatado?"
                                                    data-confirm-title="{{ $active ? 'Desativar' : 'Ativar' }} defeito relatado"
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
                                                    action="{{ route('knowledge.reported-defects.destroy', $defeitoId) }}"
                                                    data-confirm="Deseja excluir este defeito relatado? Esta ação não pode ser desfeita."
                                                    data-confirm-title="Excluir defeito relatado"
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
                'icon' => 'bi-chat-square-text-fill',
                'title' => 'Nenhum defeito relatado encontrado',
                'message' => 'Ajuste os filtros ou cadastre o primeiro defeito relatado para começar o catálogo exibido ao cliente.',
            ])
        @endif
    </section>
@endsection
