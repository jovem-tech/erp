@extends('layouts.app')

@section('content')
    @php
        $hasActiveFilters = trim((string) ($filters['search'] ?? '')) !== ''
            || trim((string) ($filters['status'] ?? '')) !== ''
            || trim((string) ($filters['tipo_equipamento'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['search'] ?? '')) !== '',
            trim((string) ($filters['status'] ?? '')) !== '',
            trim((string) ($filters['tipo_equipamento'] ?? '')) !== '',
        ]));
    @endphp

    <x-list-filters
        form-id="servicosFilterPanel"
        search-name="search"
        :search-value="$filters['search'] ?? ''"
        search-placeholder="Nome, descrição, equipamento ou status"
        :results-count="$pagination['total'] ?? 0"
        results-label="serviços"
        :clear-url="route('servicos.index')"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if (\App\Support\DesktopSession::can('servicos', 'criar'))
                <a href="{{ route('servicos.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Novo serviço
                </a>
            @endif

            <x-list-actions label="Mais ações" size="">
                <li>
                    <a href="{{ route('servicos.help') }}" class="dropdown-item">
                        <i class="bi bi-question-circle me-2"></i>Ajuda
                    </a>
                </li>
                <li>
                    <a href="{{ route('servicos.export.csv') }}" class="dropdown-item">
                        <i class="bi bi-download me-2"></i>Exportar CSV
                    </a>
                </li>
                <li>
                    <a href="{{ route('servicos.download-template') }}" class="dropdown-item">
                        <i class="bi bi-filetype-csv me-2"></i>Modelo CSV
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#servicosImportModal">
                        <i class="bi bi-upload me-2"></i>Importar em lote
                    </button>
                </li>
            </x-list-actions>
        </x-slot:actions>
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
                <h2 class="surface-title">Cadastro de serviços</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} serviços retornados pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-gear-fill"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($services !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Descrição</th>
                        <th>Tipo de equipamento</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($services as $service)
                        @php
                            $service = array_merge([
                                'id' => 0,
                                'nome' => '',
                                'descricao' => '',
                                'tipo_equipamento' => '',
                                'valor' => 0,
                                'status' => 'ativo',
                            ], is_array($service) ? $service : []);
                            $serviceId = (int) ($service['id'] ?? 0);
                            $isActive = (string) ($service['status'] ?? 'ativo') === 'ativo';
                        @endphp
                        <tr>
                            <td data-label="ID">{{ $serviceId > 0 ? $serviceId : '-' }}</td>
                            <td data-label="Nome">
                                <div class="fw-semibold">{{ trim((string) ($service['nome'] ?? '')) !== '' ? $service['nome'] : 'Sem nome' }}</div>
                                @if (trim((string) ($service['descricao'] ?? '')) !== '')
                                    <small class="text-secondary d-block mt-1">{{ $service['descricao'] }}</small>
                                @endif
                            </td>
                            <td data-label="Descrição">{{ trim((string) ($service['descricao'] ?? '')) !== '' ? $service['descricao'] : '-' }}</td>
                            <td data-label="Tipo de equipamento">{{ trim((string) ($service['tipo_equipamento'] ?? '')) !== '' ? $service['tipo_equipamento'] : '-' }}</td>
                            <td data-label="Valor">R$ {{ number_format((float) ($service['valor'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $isActive ? 'Ativo' : 'Encerrado',
                                    'color' => $isActive ? '#29c384' : '#8b93a7',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <x-list-actions>
                                    @if (\App\Support\DesktopSession::can('servicos', 'editar'))
                                        <li>
                                            <a href="{{ route('servicos.edit', $serviceId) }}" class="dropdown-item">
                                                <i class="bi bi-pencil me-2"></i>Editar
                                            </a>
                                        </li>
                                    @endif

                                    @if ($isActive && \App\Support\DesktopSession::can('servicos', 'encerrar'))
                                        <li>
                                            <form method="post" action="{{ route('servicos.close', $serviceId) }}" data-confirm="Deseja encerrar este serviço?" data-confirm-title="Encerrar serviço" data-confirm-button="Sim, encerrar">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="dropdown-item text-warning">
                                                    <i class="bi bi-archive me-2"></i>Encerrar
                                                </button>
                                            </form>
                                        </li>
                                    @endif

                                    @if (\App\Support\DesktopSession::can('servicos', 'excluir'))
                                        <li>
                                            <form method="post" action="{{ route('servicos.destroy', $serviceId) }}" data-confirm="Deseja excluir este serviço? Esta ação não pode ser desfeita." data-confirm-title="Excluir serviço" data-confirm-button="Sim, excluir">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="bi bi-trash me-2"></i>Excluir
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
                'icon' => 'bi-gear-fill',
                'title' => 'Nenhum serviço encontrado',
                'message' => 'Ajuste os filtros ou cadastre o primeiro serviço para começar o acompanhamento operacional.',
            ])
        @endif
    </section>
@endsection

@push('modals')
    <x-bulk-import-modal
        id="servicosImportModal"
        title="Importação em lote de serviços"
        :action="route('servicos.import')"
        description="Envie um CSV com a mesma estrutura do modelo para cadastrar vários serviços de uma vez."
    />
@endpush
