@php
    $marcas = is_array($marcas ?? null) ? $marcas : [];
@endphp

<section class="surface-table">
    <div class="surface-table-header">
        <div>
            <h2 class="surface-title">Marcas <x-favorite-toggle /></h2>
            <p class="surface-subtitle">{{ number_format(count($marcas), 0, ',', '.') }} marcas cadastradas no catálogo.</p>
        </div>

        <span class="desktop-chip">
            <i class="bi bi-tag"></i>
            {{ number_format(count($marcas), 0, ',', '.') }} registros
        </span>
    </div>

    @if ($marcas !== [])
        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                <tr>
                    <th style="width: 5rem;">ID</th>
                    <th>Marca</th>
                    <th>Tipos</th>
                    <th>Modelos ativos</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($marcas as $marca)
                    @php
                        $isActive = (bool) ($marca['ativo'] ?? true);
                        $tipos = is_array($marca['tipos'] ?? null) ? $marca['tipos'] : [];
                    @endphp
                    <tr>
                        <td data-label="ID" class="text-secondary">{{ (int) ($marca['id'] ?? 0) }}</td>
                        <td data-label="Marca">
                            <div class="fw-semibold">{{ $marca['nome'] ?? '' }}</div>
                        </td>
                        <td data-label="Tipos">
                            @forelse ($tipos as $tipo)
                                <span class="desktop-chip me-1 mb-1">{{ $tipo['nome'] ?? '' }}</span>
                            @empty
                                <span class="text-secondary">—</span>
                            @endforelse
                        </td>
                        <td data-label="Modelos ativos">
                            @if ((int) ($marca['modelos_ativos'] ?? 0) > 0)
                                <a href="{{ route('equipments.catalog.index', ['aba' => 'modelos', 'marca_id' => $marca['id'] ?? 0]) }}">
                                    {{ (int) ($marca['modelos_ativos'] ?? 0) }}
                                </a>
                            @else
                                0
                            @endif
                        </td>
                        <td data-label="Status">
                            @include('layouts.partials.status-pill', [
                                'label' => $isActive ? 'Ativo' : 'Inativo',
                                'color' => $isActive ? '#29c384' : '#8b93a7',
                                'small' => true,
                            ])
                        </td>
                        <td data-label="Ações" class="text-end">
                            <x-list-actions>
                                <li>
                                    <a class="dropdown-item" href="{{ route('equipments.catalog.index', ['aba' => 'modelos', 'marca_id' => $marca['id'] ?? 0]) }}">
                                        <i class="bi bi-phone me-2"></i>Ver modelos
                                    </a>
                                </li>
                                @if (\App\Support\DesktopSession::can('equipamentos', 'editar'))
                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#catalogMarcaModal"
                                            data-catalog-edit="marca"
                                            data-catalog-item="{{ json_encode(['id' => $marca['id'] ?? 0, 'nome' => $marca['nome'] ?? '']) }}"
                                        >
                                            <i class="bi bi-pencil me-2"></i>Renomear
                                        </button>
                                    </li>
                                    <li>
                                        <form
                                            method="post"
                                            action="{{ route('equipments.catalog.brands.toggle', $marca['id'] ?? 0) }}"
                                            data-confirm="{{ $isActive ? 'Desativar esta marca? Ela deixa de aparecer no cadastro de novos equipamentos.' : 'Ativar esta marca novamente?' }}"
                                            data-confirm-title="{{ $isActive ? 'Desativar marca' : 'Ativar marca' }}"
                                            data-confirm-button="{{ $isActive ? 'Sim, desativar' : 'Sim, ativar' }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="ativo" value="{{ $isActive ? '0' : '1' }}">
                                            <button type="submit" class="dropdown-item {{ $isActive ? 'text-warning' : 'text-success' }}">
                                                <i class="bi {{ $isActive ? 'bi-slash-circle' : 'bi-check-circle' }} me-2"></i>
                                                {{ $isActive ? 'Desativar' : 'Ativar' }}
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
    @else
        @include('layouts.partials.empty-state', [
            'icon' => 'bi-tag',
            'title' => 'Nenhuma marca encontrada',
            'message' => 'Ajuste os filtros ou cadastre a primeira marca.',
        ])
    @endif
</section>
