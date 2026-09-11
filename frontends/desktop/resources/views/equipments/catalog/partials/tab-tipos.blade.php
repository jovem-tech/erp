@php
    $tipos = is_array($tipos ?? null) ? $tipos : [];
@endphp

<section class="surface-table">
    <div class="surface-table-header">
        <div>
            <h2 class="surface-title">Tipos <x-favorite-toggle /></h2>
            <p class="surface-subtitle">
                {{ number_format(count($tipos), 0, ',', '.') }} tipos de equipamento — catálogo compartilhado com Estoque, Serviços, Checklists e Base de defeitos.
            </p>
        </div>

        <span class="desktop-chip">
            <i class="bi bi-diagram-2"></i>
            {{ number_format(count($tipos), 0, ',', '.') }} registros
        </span>
    </div>

    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3" role="alert">
        <i class="bi bi-exclamation-triangle mt-1"></i>
        <div>
            Tipo é um catálogo compartilhado: desativar um tipo aqui também o esconde em Estoque
            (Gerenciar categorias), Serviços, Checklists e Base de defeitos.
        </div>
    </div>

    @if ($tipos !== [])
        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Marcas</th>
                    <th>Modelos</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($tipos as $tipo)
                    @php
                        $isActive = (bool) ($tipo['ativo'] ?? true);
                    @endphp
                    <tr>
                        <td data-label="Tipo">
                            <div class="fw-semibold">{{ $tipo['nome'] ?? '' }}</div>
                        </td>
                        <td data-label="Marcas">{{ (int) ($tipo['marcas'] ?? 0) }}</td>
                        <td data-label="Modelos">
                            @if ((int) ($tipo['modelos'] ?? 0) > 0)
                                <a href="{{ route('equipments.catalog.index', ['aba' => 'modelos', 'tipo_id' => $tipo['id'] ?? 0]) }}">
                                    {{ (int) ($tipo['modelos'] ?? 0) }}
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
                                    <a class="dropdown-item" href="{{ route('equipments.catalog.index', ['aba' => 'modelos', 'tipo_id' => $tipo['id'] ?? 0]) }}">
                                        <i class="bi bi-phone me-2"></i>Ver modelos
                                    </a>
                                </li>
                                @if (\App\Support\DesktopSession::can('equipamentos', 'editar'))
                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#catalogTipoModal"
                                            data-catalog-edit="tipo"
                                            data-catalog-item="{{ json_encode(['id' => $tipo['id'] ?? 0, 'nome' => $tipo['nome'] ?? '']) }}"
                                        >
                                            <i class="bi bi-pencil me-2"></i>Renomear
                                        </button>
                                    </li>
                                    <li>
                                        <form
                                            method="post"
                                            action="{{ route('equipments.catalog.types.toggle', $tipo['id'] ?? 0) }}"
                                            data-confirm="{{ $isActive ? 'Desativar este tipo? Ele some do cadastro de equipamentos, estoque, serviços, checklists e base de defeitos.' : 'Ativar este tipo novamente?' }}"
                                            data-confirm-title="{{ $isActive ? 'Desativar tipo' : 'Ativar tipo' }}"
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
            'icon' => 'bi-diagram-2',
            'title' => 'Nenhum tipo encontrado',
            'message' => 'Cadastre o primeiro tipo de equipamento.',
        ])
    @endif
</section>
