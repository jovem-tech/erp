@extends('layouts.app')

@section('content')
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Modelos PDF</h2>
                <p class="surface-subtitle">
                    Modelos de documento HTML usados para gerar PDFs de OS, laudos e comprovantes.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                @if (\App\Support\DesktopSession::can('conhecimento', 'criar'))
                    <a href="{{ route('knowledge.pdf-templates.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-2"></i>
                        Novo modelo de PDF
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
                    placeholder="Código ou nome"
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
                <a href="{{ route('knowledge.pdf-templates.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Modelos de PDF cadastrados</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} modelos retornados pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-file-earmark-pdf-fill"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($templates !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nome</th>
                        <th>Ordem</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($templates as $template)
                        @php
                            $template = array_merge([
                                'id' => 0,
                                'codigo' => '',
                                'nome' => '',
                                'ordem' => 0,
                                'ativo' => true,
                            ], is_array($template) ? $template : []);

                            $templateId = (int) ($template['id'] ?? 0);
                            $active = (bool) ($template['ativo'] ?? false);
                        @endphp
                        <tr>
                            <td data-label="Código"><code>{{ $template['codigo'] !== '' ? $template['codigo'] : '—' }}</code></td>
                            <td data-label="Nome">{{ $template['nome'] !== '' ? $template['nome'] : '—' }}</td>
                            <td data-label="Ordem">{{ (int) ($template['ordem'] ?? 0) }}</td>
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
                                                <a href="{{ route('knowledge.pdf-templates.edit', $templateId) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil me-2"></i>
                                                    Editar
                                                </a>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('conhecimento', 'editar'))
                                            <li>
                                                <form
                                                    method="post"
                                                    action="{{ route('knowledge.pdf-templates.toggle-active', $templateId) }}"
                                                    data-confirm="Deseja {{ $active ? 'desativar' : 'ativar' }} este modelo de PDF?"
                                                    data-confirm-title="{{ $active ? 'Desativar' : 'Ativar' }} modelo de PDF"
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
                                                    action="{{ route('knowledge.pdf-templates.destroy', $templateId) }}"
                                                    data-confirm="Deseja excluir este modelo de PDF? Esta ação não pode ser desfeita."
                                                    data-confirm-title="Excluir modelo de PDF"
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
                'icon' => 'bi-file-earmark-pdf-fill',
                'title' => 'Nenhum modelo de PDF encontrado',
                'message' => 'Ajuste os filtros ou cadastre o primeiro modelo de PDF para começar a gerar documentos.',
            ])
        @endif
    </section>
@endsection
