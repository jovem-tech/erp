@extends('layouts.app')

@section('content')
    @php
        $hasActiveFilters = trim((string) ($filters['search'] ?? '')) !== ''
            || trim((string) ($filters['active'] ?? '')) !== ''
            || trim((string) ($filters['categoria'] ?? '')) !== ''
            || trim((string) ($filters['tipo_equipamento'] ?? '')) !== ''
            || trim((string) ($filters['status'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['search'] ?? '')) !== '',
            trim((string) ($filters['active'] ?? '')) !== '',
            trim((string) ($filters['categoria'] ?? '')) !== '',
            trim((string) ($filters['tipo_equipamento'] ?? '')) !== '',
            trim((string) ($filters['status'] ?? '')) !== '',
        ]));
    @endphp

    <x-list-filters
        form-id="estoqueFilterPanel"
        search-name="search"
        :search-value="$filters['search'] ?? ''"
        search-placeholder="Código, nome, categoria, fornecedor ou localização"
        :results-count="$pagination['total'] ?? 0"
        results-label="peças"
        :clear-url="route('estoque.index')"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if (\App\Support\DesktopSession::can('estoque', 'criar'))
                <a href="{{ route('estoque.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Nova peça
                </a>
            @endif

            <x-list-actions label="Mais ações" size="">
                <li>
                    <a href="{{ route('estoque.help') }}" class="dropdown-item">
                        <i class="bi bi-question-circle me-2"></i>Ajuda
                    </a>
                </li>
                <li>
                    <a href="{{ route('estoque.export.csv') }}" class="dropdown-item">
                        <i class="bi bi-download me-2"></i>Exportar CSV
                    </a>
                </li>
                <li>
                    <a href="{{ route('estoque.download-template') }}" class="dropdown-item">
                        <i class="bi bi-filetype-csv me-2"></i>Modelo CSV
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#estoqueImportModal">
                        <i class="bi bi-upload me-2"></i>Importar em lote
                    </button>
                </li>
            </x-list-actions>
        </x-slot:actions>

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
            <select id="tipo_equipamento" name="tipo_equipamento" class="form-select" data-select2-placeholder="Todos os tipos">
                <option value="">Todos os tipos</option>
                @foreach (($equipmentTypes ?? []) as $equipmentType)
                    <option value="{{ $equipmentType }}" @selected(($filters['tipo_equipamento'] ?? '') === $equipmentType)>
                        {{ $equipmentType }}
                    </option>
                @endforeach
            </select>
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
    </x-list-filters>

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
                                <x-list-actions>
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
                'icon' => 'bi-box-seam',
                'title' => 'Nenhuma peça encontrada',
                'message' => 'Ajuste os filtros ou cadastre a primeira peça para começar o controle do estoque.',
            ])
        @endif
    </section>
@endsection

@push('modals')
    <x-bulk-import-modal
        id="estoqueImportModal"
        title="Importação em lote de peças"
        :action="route('estoque.import')"
        description="Envie um CSV com a mesma estrutura do modelo para cadastrar várias peças de uma vez."
    />
@endpush
