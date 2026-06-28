@extends('layouts.app')

@section('content')
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Estoque de Peças</h2>
                <p class="surface-subtitle">
                    Cadastro operacional de peças, com exportação, importação, movimentações e controle de estoque mínimo.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('estoque.help') }}" class="btn btn-outline-info">
                    <i class="bi bi-question-circle me-2"></i>
                    Ajuda
                </a>

                <a href="{{ route('estoque.export.csv') }}" class="btn btn-outline-primary">
                    <i class="bi bi-download me-2"></i>
                    Exportar CSV
                </a>

                <a href="{{ route('estoque.download-template') }}" class="btn btn-outline-primary">
                    <i class="bi bi-filetype-csv me-2"></i>
                    Modelo CSV
                </a>

                @if (\App\Support\DesktopSession::can('estoque', 'criar'))
                    <a href="{{ route('estoque.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-2"></i>
                        Nova peça
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
                    placeholder="Código, nome, categoria, fornecedor ou localização"
                >
            </div>

            <div>
                <label for="active">Status operacional</label>
                <select id="active" name="active" class="form-select">
                    <option value="" @selected(($filters['active'] ?? '') === '')>Todos</option>
                    <option value="1" @selected((string) ($filters['active'] ?? '') === '1')>Ativo</option>
                    <option value="0" @selected((string) ($filters['active'] ?? '') === '0')>Inativo</option>
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
                    placeholder="Insumos, componentes, periféricos..."
                >
            </div>

            <div>
                <label for="tipo_equipamento">Tipo de equipamento</label>
                <input
                    type="text"
                    id="tipo_equipamento"
                    name="tipo_equipamento"
                    class="form-control"
                    value="{{ $filters['tipo_equipamento'] ?? '' }}"
                    placeholder="Notebook, Desktop, Smartphone..."
                >
            </div>

            <div>
                <label for="status">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="" @selected(($filters['status'] ?? '') === '')>Todos</option>
                    <option value="ativo" @selected((string) ($filters['status'] ?? '') === 'ativo')>Ativo</option>
                    <option value="encerrado" @selected((string) ($filters['status'] ?? '') === 'encerrado')>Encerrado</option>
                    <option value="inativo" @selected((string) ($filters['status'] ?? '') === 'inativo')>Inativo</option>
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
                <a href="{{ route('estoque.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>
        </form>
    </section>

    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h3 class="surface-title">Importação em lote</h3>
                <p class="surface-subtitle">Envie um CSV com a mesma estrutura do modelo para cadastrar várias peças de uma vez.</p>
            </div>
        </div>

        <form method="post" action="{{ route('estoque.import') }}" enctype="multipart/form-data" class="desktop-filter-grid">
            @csrf
            <div class="col-span-full">
                <label for="arquivo">Arquivo CSV</label>
                <input type="file" id="arquivo" name="arquivo" class="form-control" accept=".csv,.txt">
            </div>

            <div class="field-actions" style="grid-column: span 2;">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-upload me-2"></i>
                    Importar lote
                </button>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Cadastro de peças</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} peças retornadas pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-box-seam"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($parts !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nome</th>
                        <th>Categoria</th>
                        <th>Tipo de equipamento</th>
                        <th>Custo</th>
                        <th>Venda</th>
                        <th>Qtd.</th>
                        <th>Mín.</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($parts as $part)
                        @php
                            $part = array_merge([
                                'id' => 0,
                                'codigo' => '',
                                'nome' => '',
                                'categoria' => '',
                                'tipo_equipamento' => '',
                                'preco_custo' => 0,
                                'preco_venda' => 0,
                                'quantidade_atual' => 0,
                                'estoque_minimo' => 0,
                                'status' => 'ativo',
                                'ativo' => true,
                            ], is_array($part) ? $part : []);
                            $partId = (int) ($part['id'] ?? 0);
                            $isActive = (string) ($part['status'] ?? 'ativo') === 'ativo' && (bool) ($part['ativo'] ?? true);
                            $lowStock = (int) ($part['quantidade_atual'] ?? 0) <= (int) ($part['estoque_minimo'] ?? 0);
                        @endphp
                        <tr>
                            <td data-label="Código">{{ trim((string) ($part['codigo'] ?? '')) !== '' ? $part['codigo'] : '-' }}</td>
                            <td data-label="Nome">
                                <div class="fw-semibold">{{ trim((string) ($part['nome'] ?? '')) !== '' ? $part['nome'] : 'Sem nome' }}</div>
                                @if ($lowStock)
                                    <small class="text-danger d-block mt-1">Estoque mínimo atingido</small>
                                @endif
                            </td>
                            <td data-label="Categoria">{{ trim((string) ($part['categoria'] ?? '')) !== '' ? $part['categoria'] : '-' }}</td>
                            <td data-label="Tipo de equipamento">{{ trim((string) ($part['tipo_equipamento'] ?? '')) !== '' ? $part['tipo_equipamento'] : '-' }}</td>
                            <td data-label="Custo">R$ {{ number_format((float) ($part['preco_custo'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Venda">R$ {{ number_format((float) ($part['preco_venda'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Qtd.">{{ (int) ($part['quantidade_atual'] ?? 0) }}</td>
                            <td data-label="Mín.">{{ (int) ($part['estoque_minimo'] ?? 0) }}</td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $isActive ? 'Ativo' : 'Inativo',
                                    'color' => $isActive ? '#29c384' : '#8b93a7',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                        Ações
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        @if (\App\Support\DesktopSession::can('estoque', 'editar'))
                                            <li>
                                                <a href="{{ route('estoque.edit', $partId) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil me-2"></i>Editar
                                                </a>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('estoque', 'visualizar'))
                                            <li>
                                                <a href="{{ route('estoque.movements', $partId) }}" class="dropdown-item">
                                                    <i class="bi bi-arrow-left-right me-2"></i>Movimentações
                                                </a>
                                            </li>
                                        @endif

                                        @if ($isActive && \App\Support\DesktopSession::can('estoque', 'encerrar'))
                                            <li>
                                                <form method="post" action="{{ route('estoque.close', $partId) }}" data-confirm="Deseja encerrar esta peça?" data-confirm-title="Encerrar peça" data-confirm-button="Sim, encerrar">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="dropdown-item text-warning">
                                                        <i class="bi bi-archive me-2"></i>Encerrar
                                                    </button>
                                                </form>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('estoque', 'excluir'))
                                            <li>
                                                <form method="post" action="{{ route('estoque.destroy', $partId) }}" data-confirm="Deseja desativar esta peça? Esta ação não pode ser desfeita." data-confirm-title="Desativar peça" data-confirm-button="Sim, desativar">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="dropdown-item text-danger">
                                                        <i class="bi bi-trash me-2"></i>Desativar
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
                'icon' => 'bi-box-seam',
                'title' => 'Nenhuma peça encontrada',
                'message' => 'Ajuste os filtros ou cadastre a primeira peça para começar o controle do estoque.',
            ])
        @endif
    </section>
@endsection
