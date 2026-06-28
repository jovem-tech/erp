@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Gestão de Conhecimento</p>
            <h2 class="surface-title fs-3 mb-2">{{ $pageTitle }}</h2>
            <p class="surface-subtitle mb-0">Configure o modelo de checklist por tipo de equipamento.</p>
        </div>
    </div>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Tipos de equipamento</h2>
                <p class="surface-subtitle">Cada tipo de equipamento possui um modelo de checklist próprio para este checklist.</p>
            </div>
        </div>

        @if ($equipmentTypes !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Tipo de equipamento</th>
                        <th>Itens</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($equipmentTypes as $row)
                        @php
                            $row = array_merge([
                                'tipo_equipamento_id' => 0,
                                'tipo_equipamento_nome' => '',
                                'modelo_id' => null,
                                'configurado' => false,
                                'itens_count' => 0,
                                'ativo' => false,
                            ], is_array($row) ? $row : []);

                            $configurado = (bool) ($row['configurado'] ?? false);
                            $itensCount = (int) ($row['itens_count'] ?? 0);
                        @endphp
                        <tr>
                            <td data-label="Tipo de equipamento">{{ $row['tipo_equipamento_nome'] !== '' ? $row['tipo_equipamento_nome'] : 'Sem nome' }}</td>
                            <td data-label="Itens">{{ $itensCount }} {{ $itensCount === 1 ? 'item' : 'itens' }}</td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $configurado ? 'Configurado' : 'Não configurado',
                                    'color' => $configurado ? '#29c384' : '#8b93a7',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <a
                                    href="{{ route('knowledge.checklists.modelo.show', ['tipo' => $tipo, 'tipoEquipamento' => $row['tipo_equipamento_id']]) }}"
                                    class="btn btn-sm btn-outline-light"
                                >
                                    <i class="bi bi-{{ $configurado ? 'gear' : 'plus-lg' }} me-2"></i>
                                    {{ $configurado ? 'Gerenciar' : 'Configurar' }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-list-check',
                'title' => 'Nenhum tipo de equipamento cadastrado',
                'message' => 'Cadastre tipos de equipamento para configurar os checklists.',
            ])
        @endif
    </section>
@endsection
