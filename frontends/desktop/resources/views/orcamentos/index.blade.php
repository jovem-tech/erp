@extends('layouts.app')

@section('content')
    @php
        $budgets = is_array($budgets ?? null) ? $budgets : [];
        $filters = is_array($filters ?? null) ? $filters : [];
        $summary = is_array($summary ?? null) ? $summary : [];
        $statusOptions = is_array($statusOptions ?? null) ? $statusOptions : [];
        $typeOptions = [
            ['value' => '', 'label' => 'Todos os tipos'],
            ['value' => 'previo', 'label' => 'Orçamento prévio'],
            ['value' => 'assistencia', 'label' => 'Orçamento com equipamento na assistência'],
        ];
        $originOptions = [
            ['value' => '', 'label' => 'Todas as origens'],
            ['value' => 'manual', 'label' => 'Manual'],
            ['value' => 'os', 'label' => 'Ordem de serviço'],
            ['value' => 'conversa', 'label' => 'Conversa'],
            ['value' => 'cliente', 'label' => 'Cliente'],
        ];
        $totalValue = (float) ($summary['total_value'] ?? 0);
    @endphp

    <section class="desktop-form-card mb-4">
        <div class="surface-card-header align-items-start">
            <div>
                <h2 class="surface-title">Orçamentos</h2>
                <p class="surface-subtitle">Listagem comercial com catálogo, origem, vínculos e status operacionais vindos da API central.</p>
            </div>

            <div class="d-flex flex-wrap gap-2 align-items-start">
                <span class="desktop-chip">
                    <i class="bi bi-list-ol"></i>
                    {{ number_format((int) ($summary['total'] ?? 0), 0, ',', '.') }} registros
                </span>
                <span class="desktop-chip">
                    <i class="bi bi-currency-dollar"></i>
                    R$ {{ number_format($totalValue, 2, ',', '.') }}
                </span>
                <a href="{{ route('orcamentos.help') }}" class="btn btn-outline-info btn-sm">
                    <i class="bi bi-question-circle me-2"></i>
                    Ajuda
                </a>
                @if (\App\Support\DesktopSession::can('orcamentos', 'criar'))
                    <a href="{{ route('orcamentos.create') }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg me-2"></i>
                        Novo orçamento
                    </a>
                @endif
            </div>
        </div>

        <form method="get" class="desktop-filter-grid">
            <div class="desktop-grid-span-2">
                <label for="search">Busca</label>
                <input type="text" id="search" name="search" class="form-control" value="{{ $filters['search'] ?? '' }}" placeholder="Número, cliente, equipamento, OS ou observações">
            </div>

            <div>
                <label for="status">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Todos os status</option>
                    @foreach ($statusOptions as $statusOption)
                        <option value="{{ $statusOption['value'] ?? '' }}" @selected(($filters['status'] ?? '') === ($statusOption['value'] ?? ''))>
                            {{ $statusOption['label'] ?? 'Status' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="tipo">Tipo</label>
                <select id="tipo" name="tipo" class="form-select">
                    @foreach ($typeOptions as $typeOption)
                        <option value="{{ $typeOption['value'] }}" @selected(($filters['tipo'] ?? '') === $typeOption['value'])>{{ $typeOption['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="origem">Origem</label>
                <select id="origem" name="origem" class="form-select">
                    @foreach ($originOptions as $originOption)
                        <option value="{{ $originOption['value'] }}" @selected(($filters['origem'] ?? '') === $originOption['value'])>{{ $originOption['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="per_page">Itens por página</label>
                <select id="per_page" name="per_page" class="form-select">
                    @foreach ([10, 15, 30, 50] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 15) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field-actions">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Filtrar
                </button>
                <a href="{{ route('orcamentos.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Orçamentos cadastrados</h2>
                <p class="surface-subtitle">
                    A tabela segue o padrão do legado, com número, cliente, vínculos, validade e total por registro.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-receipt"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} encontrados
            </span>
        </div>

        @if ($budgets !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Número</th>
                        <th>Cliente</th>
                        <th>Tipo</th>
                        <th>Origem</th>
                        <th>Vínculos</th>
                        <th>Status</th>
                        <th>Validade</th>
                        <th>Total</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($budgets as $budget)
                        @php
                            $budget = is_array($budget) ? $budget : [];
                            $budgetId = (int) ($budget['id'] ?? 0);
                            $statusColor = (string) ($budget['status_color'] ?? '#64748b');
                            $clientName = trim((string) ($budget['cliente_nome'] ?? ($budget['cliente_nome_avulso'] ?? '')));
                            $equipmentName = trim((string) ($budget['equipamento_resumo'] ?? ''));
                            $links = trim((string) ($budget['vinculos'] ?? ''));
                        @endphp
                        <tr>
                            <td data-label="Número">
                                <div class="fw-semibold">{{ $budget['numero'] !== '' ? $budget['numero'] : ('#' . $budgetId) }}</div>
                                <small class="text-secondary d-block">Versão {{ (int) ($budget['versao'] ?? 1) }}</small>
                            </td>
                            <td data-label="Cliente">
                                <div class="fw-semibold">{{ $clientName !== '' ? $clientName : 'Cliente não informado' }}</div>
                                @if (($budget['cliente_documento'] ?? '') !== '')
                                    <small class="text-secondary d-block">{{ $budget['cliente_documento'] }}</small>
                                @endif
                            </td>
                            <td data-label="Tipo">{{ $budget['tipo_label'] ?? 'Orçamento prévio' }}</td>
                            <td data-label="Origem">{{ $budget['origem_label'] ?? 'Manual' }}</td>
                            <td data-label="Vínculos">
                                @if ($links !== '')
                                    <div>{{ $links }}</div>
                                @else
                                    <div class="text-secondary">Sem vínculo informado</div>
                                @endif
                                @if ($equipmentName !== '')
                                    <small class="text-secondary d-block">{{ $equipmentName }}</small>
                                @endif
                            </td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => $budget['status_label'] ?? 'Rascunho',
                                    'color' => $statusColor,
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Validade">
                                <div>{{ $budget['validade_data'] !== '' ? $budget['validade_data'] : 'Sem validade' }}</div>
                                @if ((int) ($budget['validade_dias'] ?? 0) > 0)
                                    <small class="text-secondary d-block">{{ (int) ($budget['validade_dias'] ?? 0) }} dias</small>
                                @endif
                            </td>
                            <td data-label="Total">
                                <div class="fw-bold">R$ {{ $budget['total_formatado'] ?? number_format((float) ($budget['total'] ?? 0), 2, ',', '.') }}</div>
                            </td>
                            <td data-label="Ações" class="text-end">
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                        <span>Ações</span>
                                        <i class="bi bi-chevron-down ms-1"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a href="{{ route('orcamentos.show', $budgetId) }}" class="dropdown-item">
                                                <i class="bi bi-eye me-2"></i>
                                                Abrir
                                            </a>
                                        </li>
                                        @if (! empty($budget['can_edit']))
                                            <li>
                                                <a href="{{ route('orcamentos.edit', $budgetId) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil me-2"></i>
                                                    Editar
                                                </a>
                                            </li>
                                        @endif
                                        @if (! empty($budget['can_delete']))
                                            <li>
                                                <form method="post" action="{{ route('orcamentos.destroy', $budgetId) }}" data-confirm="Deseja excluir este orçamento? Esta ação não poderá ser desfeita." data-confirm-title="Excluir orçamento" data-confirm-button="Sim, excluir">
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
                'icon' => 'bi-receipt',
                'title' => 'Nenhum orçamento encontrado',
                'message' => 'Ajuste os filtros ou crie o primeiro orçamento comercial para começar o acompanhamento.',
            ])
        @endif
    </section>
@endsection
