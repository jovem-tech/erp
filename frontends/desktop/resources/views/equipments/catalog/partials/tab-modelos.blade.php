@php
    $modelos = is_array($modelos ?? null) ? $modelos : [];
    $pagination = is_array($pagination ?? null) ? $pagination : [];
    $filters = is_array($filters ?? null) ? $filters : [];
    $aba = $aba ?? 'modelos';
@endphp

<section class="surface-table">
    <div class="surface-table-header">
        <div>
            <h2 class="surface-title">Modelos <x-favorite-toggle /></h2>
            <p class="surface-subtitle">
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} modelos cadastrados no catálogo, usados para padronizar o cadastro de equipamentos em OS e orçamento.
            </p>
        </div>

        <span class="desktop-chip">
            <i class="bi bi-phone"></i>
            {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
        </span>
    </div>

    @if ($modelos !== [])
        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                <tr>
                    <th style="width: 5rem;">ID</th>
                    <th>Tipo(s)</th>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($modelos as $modelo)
                    @php
                        $isActive = (bool) ($modelo['ativo'] ?? true);
                        $tipos = is_array($modelo['tipos'] ?? null) ? $modelo['tipos'] : [];
                        $marca = is_array($modelo['marca'] ?? null) ? $modelo['marca'] : [];
                    @endphp
                    <tr>
                        <td data-label="ID" class="text-secondary">{{ (int) ($modelo['id'] ?? 0) }}</td>
                        <td data-label="Tipo(s)">
                            @forelse ($tipos as $tipo)
                                <span class="desktop-chip me-1 mb-1">{{ $tipo['nome'] ?? '' }}</span>
                            @empty
                                <span class="text-secondary">—</span>
                            @endforelse
                        </td>
                        <td data-label="Marca">{{ trim((string) ($marca['nome'] ?? '')) !== '' ? $marca['nome'] : '-' }}</td>
                        <td data-label="Modelo">
                            <div class="fw-semibold">{{ $modelo['nome'] ?? '' }}</div>
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
                                @if (\App\Support\DesktopSession::can('equipamentos', 'editar'))
                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#catalogModeloModal"
                                            data-catalog-edit="modelo"
                                            data-catalog-item="{{ json_encode(['id' => $modelo['id'] ?? 0, 'nome' => $modelo['nome'] ?? '']) }}"
                                        >
                                            <i class="bi bi-pencil me-2"></i>Renomear
                                        </button>
                                    </li>
                                    <li>
                                        <form
                                            method="post"
                                            action="{{ route('equipments.catalog.models.toggle', $modelo['id'] ?? 0) }}"
                                            data-confirm="{{ $isActive ? 'Desativar este modelo? Ele deixa de aparecer no cadastro de novos equipamentos (aparelhos já cadastrados não são afetados).' : 'Ativar este modelo novamente?' }}"
                                            data-confirm-title="{{ $isActive ? 'Desativar modelo' : 'Ativar modelo' }}"
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

        @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => array_merge($filters, ['aba' => $aba])])
    @else
        @include('layouts.partials.empty-state', [
            'icon' => 'bi-phone',
            'title' => 'Nenhum modelo encontrado',
            'message' => 'Ajuste os filtros ou importe um CSV para popular o catálogo.',
        ])
    @endif
</section>
