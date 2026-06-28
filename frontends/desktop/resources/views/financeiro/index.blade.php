@extends('layouts.app')

@section('content')
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Financeiro</h2>
                <p class="surface-subtitle">
                    Títulos a receber e a pagar, com baixa parcial ou total e classificação automática de DRE pela categoria.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('financeiro.configuracoes') }}" class="btn btn-outline-info">
                    <i class="bi bi-bar-chart-line me-2"></i>
                    Configurações financeiras
                </a>

                @if (\App\Support\DesktopSession::can('financeiro', 'criar'))
                    <a href="{{ route('financeiro.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-2"></i>
                        Novo lançamento
                    </a>
                @endif
            </div>
        </div>

        <form method="get" class="desktop-filter-grid">
            <div>
                <label for="tipo">Tipo</label>
                <select id="tipo" name="tipo" class="form-select">
                    <option value="" @selected(($filters['tipo'] ?? '') === '')>Todos</option>
                    <option value="receber" @selected(($filters['tipo'] ?? '') === 'receber')>A receber</option>
                    <option value="pagar" @selected(($filters['tipo'] ?? '') === 'pagar')>A pagar</option>
                </select>
            </div>

            <div>
                <label for="status">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="" @selected(($filters['status'] ?? '') === '')>Todos</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] ?? '' }}" @selected(($filters['status'] ?? '') === ($option['value'] ?? ''))>
                            {{ $option['label'] ?? $option['value'] }}
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

            <div class="field-actions" style="grid-column: span 2;">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Filtrar
                </button>
                <a href="{{ route('financeiro.index') }}" class="btn btn-outline-light">Limpar</a>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Títulos financeiros</h2>
                <p class="surface-subtitle">
                    {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} lançamentos retornados pela API central.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-cash-coin"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($lancamentos !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo</th>
                        <th>Categoria</th>
                        <th>Valor</th>
                        <th>Vencimento</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($lancamentos as $lancamento)
                        @php
                            $id = (int) ($lancamento['id'] ?? 0);
                            $tipo = (string) ($lancamento['tipo'] ?? '');
                            $status = (string) ($lancamento['status'] ?? 'pendente');
                            $statusColors = [
                                'pendente' => '#f59e0b',
                                'parcial' => '#3b82f6',
                                'pago' => '#29c384',
                                'cancelado' => '#8b93a7',
                            ];
                            $canPay = in_array($status, ['pendente', 'parcial'], true);
                        @endphp
                        <tr>
                            <td data-label="ID">{{ $id > 0 ? $id : '-' }}</td>
                            <td data-label="Tipo">
                                <span class="badge {{ $tipo === 'receber' ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $tipo === 'receber' ? 'A receber' : 'A pagar' }}
                                </span>
                            </td>
                            <td data-label="Categoria">
                                <div class="fw-semibold">{{ $lancamento['categoria'] ?? 'Sem categoria' }}</div>
                                @if (! empty($lancamento['grupo_dre']))
                                    <small class="text-secondary d-block">{{ $lancamento['grupo_dre'] }} / {{ $lancamento['subgrupo_dre'] ?? '-' }}</small>
                                @endif
                            </td>
                            <td data-label="Valor">R$ {{ number_format((float) ($lancamento['valor'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Vencimento">{{ !empty($lancamento['data_vencimento']) ? \Illuminate\Support\Carbon::parse($lancamento['data_vencimento'])->format('d/m/Y') : '-' }}</td>
                            <td data-label="Status">
                                @include('layouts.partials.status-pill', [
                                    'label' => ucfirst($status),
                                    'color' => $statusColors[$status] ?? '#8b93a7',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                        <span>Ações</span>
                                        <i class="bi bi-chevron-down"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end">
                                        @if (\App\Support\DesktopSession::can('financeiro', 'editar'))
                                            <li>
                                                <a href="{{ route('financeiro.edit', $id) }}" class="dropdown-item">
                                                    <i class="bi bi-pencil me-2"></i>
                                                    Editar
                                                </a>
                                            </li>
                                        @endif

                                        @if ($canPay && \App\Support\DesktopSession::can('financeiro', 'editar'))
                                            <li>
                                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#payModal{{ $id }}">
                                                    <i class="bi bi-cash-stack me-2"></i>
                                                    Registrar baixa
                                                </button>
                                            </li>
                                        @endif

                                        @if (\App\Support\DesktopSession::can('financeiro', 'excluir'))
                                            <li>
                                                <form
                                                    method="post"
                                                    action="{{ route('financeiro.destroy', $id) }}"
                                                    data-confirm="Deseja excluir este lançamento? Esta ação não pode ser desfeita."
                                                    data-confirm-title="Excluir lançamento"
                                                    data-confirm-button="Sim, excluir"
                                                >
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

                        @if ($canPay)
                            <div class="modal fade" id="payModal{{ $id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="post" action="{{ route('financeiro.pay', $id) }}">
                                            @csrf
                                            <div class="modal-header">
                                                <h5 class="modal-title">Registrar baixa — Lançamento #{{ $id }}</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">Valor da baixa</label>
                                                    <input type="number" name="valor_movimento" class="form-control" step="0.01" min="0.01" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Data do movimento</label>
                                                    <input type="date" name="data_movimento" class="form-control" value="{{ now()->toDateString() }}">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Forma de pagamento</label>
                                                    <input type="text" name="forma_pagamento" class="form-control" placeholder="Pix, dinheiro, cartão...">
                                                </div>
                                                <div>
                                                    <label class="form-label">Observações</label>
                                                    <textarea name="observacoes" class="form-control" rows="2"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-primary">Confirmar baixa</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>

            @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => $filters])
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-cash-coin',
                'title' => 'Nenhum lançamento encontrado',
                'message' => 'Ajuste os filtros ou cadastre o primeiro lançamento financeiro para começar o acompanhamento.',
            ])
        @endif
    </section>
@endsection
