@extends('layouts.app')

@section('content')
    @php
        $activeFilters = [
            trim((string) ($filters['search'] ?? '')) !== '',
            trim((string) ($filters['status'] ?? '')) !== '',
            trim((string) ($filters['status_pagamento'] ?? '')) !== '',
            (int) ($filters['vendedor_id'] ?? 0) > 0,
            trim((string) ($filters['data_inicio'] ?? '')) !== '',
            trim((string) ($filters['data_fim'] ?? '')) !== '',
        ];
        $activeFilterCount = count(array_filter($activeFilters));
        $hasActiveFilters = $activeFilterCount > 0;

        $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    @endphp

    <x-list-filters
        form-id="vendasFilterPanel"
        search-name="search"
        :search-value="$filters['search'] ?? ''"
        search-placeholder="Número da venda ou nome do cliente"
        :results-count="$pagination['total'] ?? 0"
        results-label="vendas"
        :clear-url="route('vendas.index')"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if (\App\Support\DesktopSession::can('vendas', 'criar'))
                <a href="{{ route('vendas.create') }}" class="btn btn-primary">
                    <i class="bi bi-upc-scan me-2"></i>
                    Nova venda
                </a>
            @endif

            <x-list-actions label="Mais ações" size="">
                <li>
                    <a href="{{ route('vendas.help') }}" class="dropdown-item">
                        <i class="bi bi-question-circle me-2"></i>Ajuda
                    </a>
                </li>
            </x-list-actions>
        </x-slot:actions>

        <div>
            <label for="status">Situação</label>
            <select id="status" name="status" class="form-select">
                <option value="">Todas</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option['value'] ?? '' }}" @selected((string) ($filters['status'] ?? '') === (string) ($option['value'] ?? ''))>
                        {{ $option['label'] ?? '' }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="status_pagamento">Pagamento</label>
            <select id="status_pagamento" name="status_pagamento" class="form-select">
                <option value="">Todos</option>
                @foreach ($statusPagamentoOptions as $option)
                    <option value="{{ $option['value'] ?? '' }}" @selected((string) ($filters['status_pagamento'] ?? '') === (string) ($option['value'] ?? ''))>
                        {{ $option['label'] ?? '' }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="data_inicio">De</label>
            <input type="date" id="data_inicio" name="data_inicio" class="form-control" value="{{ $filters['data_inicio'] ?? '' }}">
        </div>

        <div>
            <label for="data_fim">Até</label>
            <input type="date" id="data_fim" name="data_fim" class="form-control" value="{{ $filters['data_fim'] ?? '' }}">
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

    @if (($summary['total_vendas'] ?? 0) > 0)
        <section class="row g-3 mb-3">
            @foreach ([
                ['rotulo' => 'Total vendido', 'valor' => $money($summary['total_vendido'] ?? 0), 'icone' => 'bi-cash-coin'],
                ['rotulo' => 'Vendas', 'valor' => number_format((int) ($summary['total_vendas'] ?? 0), 0, ',', '.'), 'icone' => 'bi-receipt'],
                ['rotulo' => 'Ticket médio', 'valor' => $money($summary['ticket_medio'] ?? 0), 'icone' => 'bi-graph-up'],
                ['rotulo' => 'Margem', 'valor' => $money($summary['total_margem'] ?? 0) . ' (' . number_format((float) ($summary['margem_percentual'] ?? 0), 2, ',', '.') . '%)', 'icone' => 'bi-percent'],
            ] as $card)
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="surface-card h-100">
                        <div class="d-flex align-items-center gap-2 text-secondary">
                            <i class="bi {{ $card['icone'] }}"></i>
                            <small>{{ $card['rotulo'] }}</small>
                        </div>
                        <div class="fs-5 fw-semibold mt-1">{{ $card['valor'] }}</div>
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Vendas de balcão</h2>
                <p class="surface-subtitle">
                    Produtos, acessórios e serviços vendidos sem vínculo com ordem de serviço.
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-cart-check"></i>
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
            </span>
        </div>

        @if ($vendas !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Venda</th>
                        <th>Cliente</th>
                        <th>Data</th>
                        <th class="text-end">Total</th>
                        <th>Pagamento</th>
                        <th>Situação</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($vendas as $venda)
                        @php
                            $venda = is_array($venda) ? $venda : [];
                            $vendaId = (int) ($venda['id'] ?? 0);
                            $cancelada = (string) ($venda['status'] ?? '') === 'cancelada';
                            $aberto = (float) ($venda['valor_aberto'] ?? 0);
                        @endphp
                        <tr>
                            <td data-label="Venda">
                                <div class="fw-semibold">{{ $venda['numero'] ?? '-' }}</div>
                                @if (! empty($venda['vendedor_nome']))
                                    <small class="text-secondary d-block">{{ $venda['vendedor_nome'] }}</small>
                                @endif
                                @if (! empty($venda['estoque_divergente']))
                                    <small class="text-warning d-block mt-1">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Vendida sem saldo
                                    </small>
                                @endif
                            </td>
                            <td data-label="Cliente">{{ $venda['cliente_nome'] ?? 'Consumidor final' }}</td>
                            <td data-label="Data">
                                {{ ! empty($venda['data_venda']) ? \Illuminate\Support\Carbon::parse($venda['data_venda'])->format('d/m/Y') : '-' }}
                            </td>
                            <td data-label="Total" class="text-end fw-semibold">{{ $money($venda['total'] ?? 0) }}</td>
                            <td data-label="Pagamento">
                                @include('layouts.partials.status-pill', [
                                    'label' => $venda['status_pagamento_label'] ?? 'Pendente',
                                    'color' => match ($venda['status_pagamento'] ?? '') {
                                        'pago' => '#29c384',
                                        'parcial' => '#f5a623',
                                        default => '#e2574c',
                                    },
                                    'small' => true,
                                ])
                                @if ($aberto > 0 && ! $cancelada)
                                    <small class="text-secondary d-block mt-1">Em aberto: {{ $money($aberto) }}</small>
                                @endif
                            </td>
                            <td data-label="Situação">
                                @include('layouts.partials.status-pill', [
                                    'label' => $venda['status_label'] ?? '-',
                                    'color' => $venda['status_color'] ?? '#8b93a7',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Ações" class="text-end">
                                <x-list-actions>
                                    <li>
                                        <a href="{{ route('vendas.show', $vendaId) }}" class="dropdown-item">
                                            <i class="bi bi-eye me-2"></i>Detalhes
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('vendas.receipt', $vendaId) }}" class="dropdown-item" target="_blank" rel="noopener">
                                            <i class="bi bi-printer me-2"></i>Comprovante
                                        </a>
                                    </li>
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
                'icon' => 'bi-cart-check',
                'title' => 'Nenhuma venda encontrada',
                'message' => 'Ajuste os filtros ou registre a primeira venda de balcão no PDV.',
            ])
        @endif
    </section>
@endsection
