@extends('layouts.app')

@section('content')
    @php
        $hasActiveFilters = trim((string) ($filters['search'] ?? '')) !== ''
            || trim((string) ($filters['active'] ?? '')) !== ''
            || (int) ($filters['tipo_equipamento_id'] ?? 0) > 0
            || (int) ($filters['estoque_categoria_id'] ?? 0) > 0
            || (int) ($filters['estoque_subcategoria_id'] ?? 0) > 0
            || trim((string) ($filters['status'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['search'] ?? '')) !== '',
            trim((string) ($filters['active'] ?? '')) !== '',
            (int) ($filters['tipo_equipamento_id'] ?? 0) > 0,
            (int) ($filters['estoque_categoria_id'] ?? 0) > 0,
            (int) ($filters['estoque_subcategoria_id'] ?? 0) > 0,
            trim((string) ($filters['status'] ?? '')) !== '',
        ]));
        // Quantidade e DECIMAL(14,4) desde specs/036-estoque-nucleo-razao:
        // mostra a fracao quando existe e apara zeros a direita, para "10" nao
        // aparecer como "10,0000".
        $qtd = static function ($valor): string {
            $numero = round((float) ($valor ?? 0), 4);

            return $numero === floor($numero)
                ? number_format($numero, 0, ',', '.')
                : rtrim(rtrim(number_format($numero, 4, ',', '.'), '0'), ',');
        };
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

            {{--
                Entrada por compra (specs/039). A porta fica no Financeiro — a
                compra É um lançamento —, mas quem pensa "chegou peça" está
                nesta tela, não naquela. Sem este botão a funcionalidade existe
                e ninguém acha.
            --}}
            @if (\App\Support\DesktopSession::can('estoque', 'editar')
                && \App\Support\DesktopSession::can('financeiro', 'criar'))
                <a href="{{ route('financeiro.create', ['tipo' => 'pagar', 'entrada_estoque' => 1]) }}"
                   class="btn btn-outline-primary">
                    <i class="bi bi-box-arrow-in-down me-2"></i>
                    Entrada por compra
                </a>
            @endif

            <x-list-actions label="Mais ações" size="" :favoritable="true">
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
                @if (\App\Support\DesktopSession::can('estoque', 'editar'))
                    <li>
                        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#estoqueCategoriasModal">
                            <i class="bi bi-diagram-3 me-2"></i>Gerenciar categorias
                        </button>
                    </li>
                @endif
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

        {{--
            Taxonomia de estoque (Grupo → Categoria → Subcategoria), em
            cascata: Categoria só mostra as do Grupo escolhido, Subcategoria
            só as da Categoria escolhida. Todas as opções vêm pré-renderizadas
            (poucos registros) com `data-parent-id` no pai — o cascateamento é
            client-side puro, ver estoque-categorias.js.
        --}}
        <div>
            <label for="tipo_equipamento_id">Grupo</label>
            <select id="tipo_equipamento_id" name="tipo_equipamento_id" class="form-select" data-select2-placeholder="Todos os grupos" data-taxonomy-select="grupo">
                <option value="">Todos os grupos</option>
                @foreach (($grupos ?? []) as $grupo)
                    <option value="{{ $grupo['id'] }}" @selected((int) ($filters['tipo_equipamento_id'] ?? 0) === (int) $grupo['id'])>
                        {{ $grupo['nome'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="estoque_categoria_id">Categoria</label>
            <select id="estoque_categoria_id" name="estoque_categoria_id" class="form-select" data-select2-placeholder="Todas as categorias" data-taxonomy-select="categoria" data-taxonomy-parent="tipo_equipamento_id">
                <option value="">Todas as categorias</option>
                @foreach (($estoqueCategorias ?? []) as $categoria)
                    <option value="{{ $categoria['id'] }}" data-parent-id="{{ $categoria['tipo_equipamento_id'] }}" @selected((int) ($filters['estoque_categoria_id'] ?? 0) === (int) $categoria['id'])>
                        {{ $categoria['nome'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="estoque_subcategoria_id">Subcategoria</label>
            <select id="estoque_subcategoria_id" name="estoque_subcategoria_id" class="form-select" data-select2-placeholder="Todas as subcategorias" data-taxonomy-select="subcategoria" data-taxonomy-parent="estoque_categoria_id">
                <option value="">Todas as subcategorias</option>
                @foreach (($estoqueSubcategorias ?? []) as $subcategoria)
                    <option value="{{ $subcategoria['id'] }}" data-parent-id="{{ $subcategoria['categoria_id'] }}" @selected((int) ($filters['estoque_subcategoria_id'] ?? 0) === (int) $subcategoria['id'])>
                        {{ $subcategoria['nome'] }}
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
            <label for="estoque_baixo">Estoque</label>
            {{-- Destino do alerta "itens abaixo do estoque mínimo" do dashboard.
                 Precisa aparecer no formulário para o usuário ver que a lista
                 chegou filtrada — e poder tirar o filtro. --}}
            <select id="estoque_baixo" name="estoque_baixo" class="form-select">
                <option value="" @selected((int) ($filters['estoque_baixo'] ?? 0) !== 1)>Todos os níveis</option>
                <option value="1" @selected((int) ($filters['estoque_baixo'] ?? 0) === 1)>Somente abaixo do mínimo</option>
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
                <h2 class="surface-title">Cadastro de peças <x-favorite-toggle /></h2>
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
                                'tipo_equipamento_efetivo' => '',
                                'categoria_efetiva' => '',
                                'preco_custo' => 0,
                                'preco_venda' => 0,
                                'quantidade_atual' => 0,
                                'estoque_minimo' => 0,
                                'status' => 'ativo',
                                'ativo' => true,
                            ], is_array($part) ? $part : []);
                            $partId = (int) ($part['id'] ?? 0);
                            $isActive = (string) ($part['status'] ?? 'ativo') === 'ativo' && (bool) ($part['ativo'] ?? true);
                            $lowStock = (float) ($part['quantidade_atual'] ?? 0) <= (float) ($part['estoque_minimo'] ?? 0);
                        @endphp
                        <tr>
                            <td data-label="Código">{{ trim((string) ($part['codigo'] ?? '')) !== '' ? $part['codigo'] : '-' }}</td>
                            <td data-label="Nome">
                                <div class="fw-semibold">{{ trim((string) ($part['nome'] ?? '')) !== '' ? $part['nome'] : 'Sem nome' }}</div>
                                @if ($lowStock)
                                    <small class="text-danger d-block mt-1">Estoque mínimo atingido</small>
                                @endif
                            </td>
                            {{-- categoria_efetiva/tipo_equipamento_efetivo: nome da árvore nova
                                 quando a peça foi classificada, ou o texto legado (categoria/
                                 tipo_equipamento) para quem nunca foi reclassificado. --}}
                            <td data-label="Categoria">{{ trim((string) ($part['categoria_efetiva'] ?? '')) !== '' ? $part['categoria_efetiva'] : '-' }}</td>
                            <td data-label="Tipo de equipamento">{{ trim((string) ($part['tipo_equipamento_efetivo'] ?? '')) !== '' ? $part['tipo_equipamento_efetivo'] : '-' }}</td>
                            <td data-label="Custo">R$ {{ number_format((float) ($part['preco_custo'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Venda">R$ {{ number_format((float) ($part['preco_venda'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Qtd.">{{ $qtd($part['quantidade_atual'] ?? 0) }}</td>
                            <td data-label="Mín.">{{ $qtd($part['estoque_minimo'] ?? 0) }}</td>
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

    @include('estoque.partials.categorias-modal')
@endpush

@section('scripts')
    <script>
        window.__DESKTOP_ESTOQUE_INDEX = {
            filterCascade: {
                grupoSelect: 'tipo_equipamento_id',
                categoriaSelect: 'estoque_categoria_id',
                subcategoriaSelect: 'estoque_subcategoria_id',
            },
        };
    </script>
    <script src="{{ asset('assets/js/estoque-categorias.js') }}?v={{ filemtime(public_path('assets/js/estoque-categorias.js')) }}-{{ filesize(public_path('assets/js/estoque-categorias.js')) }}"></script>
@endsection
