@extends('layouts.app')

@section('content')
    @php
        $filters = is_array($filters ?? null) ? $filters : [];
        $types = is_array($types ?? null) ? $types : [];
        $allBrands = is_array($allBrands ?? null) ? $allBrands : [];
        $aba = in_array($aba ?? 'modelos', ['modelos', 'marcas', 'tipos'], true) ? $aba : 'modelos';

        $hasActiveFilters = trim((string) ($filters['search'] ?? '')) !== ''
            || (int) ($filters['tipo_id'] ?? 0) > 0
            || (int) ($filters['marca_id'] ?? 0) > 0
            || trim((string) ($filters['ativo'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['search'] ?? '')) !== '',
            (int) ($filters['tipo_id'] ?? 0) > 0,
            (int) ($filters['marca_id'] ?? 0) > 0,
            trim((string) ($filters['ativo'] ?? '')) !== '',
        ]));

        $totalRegistros = match ($aba) {
            'modelos' => (int) ($pagination['total'] ?? 0),
            'marcas' => count($marcas ?? []),
            default => count($tipos ?? []),
        };

        $novoLabel = match ($aba) {
            'modelos' => 'Novo modelo',
            'marcas' => 'Nova marca',
            default => 'Novo tipo',
        };
    @endphp

    <x-list-filters
        form-id="equipmentsCatalogFilterPanel"
        search-name="search"
        :search-value="$filters['search'] ?? ''"
        search-placeholder="Nome do modelo ou da marca"
        :results-count="$totalRegistros"
        results-label="registros"
        :clear-url="route('equipments.catalog.index', ['aba' => $aba])"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if (\App\Support\DesktopSession::can('equipamentos', 'criar'))
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#catalog{{ ucfirst(rtrim($aba, 's')) }}Modal" data-catalog-new="{{ $aba }}">
                    <i class="bi bi-plus-lg me-2"></i>
                    {{ $novoLabel }}
                </button>
            @endif

            <x-list-actions label="Mais ações" size="" :favoritable="true">
                <li>
                    <a href="{{ route('equipments.catalog.help') }}" class="dropdown-item">
                        <i class="bi bi-question-circle me-2"></i>Ajuda
                    </a>
                </li>
                @if (\App\Support\DesktopSession::can('equipamentos', 'exportar'))
                    <li>
                        <a href="{{ route('equipments.catalog.export.csv') }}" class="dropdown-item" data-no-page-loader="true">
                            <i class="bi bi-download me-2"></i>Exportar CSV
                        </a>
                    </li>
                @endif
                @if (\App\Support\DesktopSession::can('equipamentos', 'importar'))
                    <li>
                        <a href="{{ route('equipments.catalog.download-template') }}" class="dropdown-item" data-no-page-loader="true">
                            <i class="bi bi-filetype-csv me-2"></i>Modelo CSV
                        </a>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#equipmentsCatalogImportModal">
                            <i class="bi bi-upload me-2"></i>Importar em lote
                        </button>
                    </li>
                @endif
                <li><hr class="dropdown-divider"></li>
                @if (\App\Support\DesktopSession::can('equipamentos', 'visualizar'))
                    <li>
                        <a href="{{ route('equipments.index') }}" class="dropdown-item">
                            <i class="bi bi-laptop me-2"></i>Aparelhos de clientes
                        </a>
                    </li>
                @endif
            </x-list-actions>
        </x-slot:actions>

        <input type="hidden" name="aba" value="{{ $aba }}">

        <div>
            <label for="tipo_id">Tipo</label>
            <select id="tipo_id" name="tipo_id" class="form-select" data-select2-placeholder="Todos os tipos">
                <option value="">Todos os tipos</option>
                @foreach ($types as $type)
                    <option value="{{ $type['id'] ?? 0 }}" @selected((int) ($filters['tipo_id'] ?? 0) === (int) ($type['id'] ?? 0))>
                        {{ $type['nome'] ?? '' }}{{ ($type['ativo'] ?? true) ? '' : ' (inativo)' }}
                    </option>
                @endforeach
            </select>
        </div>

        @if ($aba === 'modelos')
            <div>
                <label for="marca_id">Marca</label>
                <select id="marca_id" name="marca_id" class="form-select" data-select2-placeholder="Todas as marcas">
                    <option value="">Todas as marcas</option>
                    @foreach ($allBrands as $brand)
                        <option value="{{ $brand['id'] ?? 0 }}" @selected((int) ($filters['marca_id'] ?? 0) === (int) ($brand['id'] ?? 0))>
                            {{ $brand['nome'] ?? '' }}{{ ($brand['ativo'] ?? true) ? '' : ' (inativa)' }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div>
            <label for="ativo">Status</label>
            <select id="ativo" name="ativo" class="form-select">
                <option value="" @selected(($filters['ativo'] ?? '') === '')>Todos</option>
                <option value="1" @selected(($filters['ativo'] ?? '') === '1')>Ativos</option>
                <option value="0" @selected(($filters['ativo'] ?? '') === '0')>Inativos</option>
            </select>
        </div>

        @if ($aba === 'modelos')
            <div>
                <label for="per_page">Itens por página</label>
                <select id="per_page" name="per_page" class="form-select">
                    @foreach ([15, 30, 50] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 15) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </x-list-filters>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link {{ $aba === 'modelos' ? 'active' : '' }}" href="{{ route('equipments.catalog.index', ['aba' => 'modelos']) }}">
                <i class="bi bi-phone me-1"></i>Modelos
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $aba === 'marcas' ? 'active' : '' }}" href="{{ route('equipments.catalog.index', ['aba' => 'marcas']) }}">
                <i class="bi bi-tag me-1"></i>Marcas
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $aba === 'tipos' ? 'active' : '' }}" href="{{ route('equipments.catalog.index', ['aba' => 'tipos']) }}">
                <i class="bi bi-diagram-2 me-1"></i>Tipos
            </a>
        </li>
    </ul>

    @if (session('catalog_import_report'))
        @include('equipments.catalog.partials.import-report', ['report' => session('catalog_import_report')])
    @endif

    @if ($aba === 'modelos')
        @include('equipments.catalog.partials.tab-modelos', [
            'modelos' => $modelos ?? [],
            'pagination' => $pagination ?? [],
            'filters' => $filters,
            'aba' => $aba,
        ])
    @elseif ($aba === 'marcas')
        @include('equipments.catalog.partials.tab-marcas', ['marcas' => $marcas ?? []])
    @else
        @include('equipments.catalog.partials.tab-tipos', ['tipos' => $tipos ?? []])
    @endif
@endsection

@push('modals')
    <x-bulk-import-modal
        id="equipmentsCatalogImportModal"
        title="Importação em lote do catálogo de equipamentos"
        :action="route('equipments.catalog.import')"
        description="Envie um CSV com colunas tipo;marca;modelo;ativo para atualizar o catálogo (upsert aditivo: nunca desativa o que ficou fora do arquivo — use ativo=0 na linha para isso)."
    />

    @include('equipments.catalog.partials.modals', ['types' => $types, 'allBrands' => $allBrands])
@endpush

@section('scripts')
    <script src="{{ asset('assets/js/equipments-catalog.js') }}?v={{ filemtime(public_path('assets/js/equipments-catalog.js')) }}"></script>
@endsection
