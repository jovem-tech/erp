@extends('layouts.app')

@section('content')
    @php
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['search'] ?? '')) !== '',
            trim((string) ($filters['data_inicio'] ?? '')) !== '',
            trim((string) ($filters['data_fim'] ?? '')) !== '',
        ]));
        $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    @endphp

    <x-list-filters
        form-id="devolucoesFilterPanel"
        search-name="search"
        :search-value="$filters['search'] ?? ''"
        search-placeholder="Número da devolução ou da venda"
        :results-count="$pagination['total'] ?? 0"
        results-label="devoluções"
        :clear-url="route('devolucoes.index')"
        :has-active-filters="$activeFilterCount > 0"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            <a href="{{ route('vendas.index') }}" class="btn btn-primary">
                <i class="bi bi-cart-check me-2"></i>Ir para vendas
            </a>

            <x-list-actions label="Mais ações" size="">
                <li>
                    <a href="{{ route('devolucoes.help') }}" class="dropdown-item">
                        <i class="bi bi-question-circle me-2"></i>Ajuda
                    </a>
                </li>
            </x-list-actions>
        </x-slot:actions>

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

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Devoluções</h2>
                <p class="surface-subtitle">
                    Produtos que voltaram do balcão. Para registrar uma devolução, abra a venda de origem.
                </p>
            </div>
        </div>

        @if ($devolucoes !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Devolução</th>
                        <th>Venda</th>
                        <th>Cliente</th>
                        <th>Data</th>
                        <th class="text-end">Reembolsado</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($devolucoes as $devolucao)
                        @php
                            $devolucao = is_array($devolucao) ? $devolucao : [];
                            $devolucaoId = (int) ($devolucao['id'] ?? 0);
                            $abatido = (float) ($devolucao['valor_abatido'] ?? 0);
                        @endphp
                        <tr>
                            <td data-label="Devolução">
                                <div class="fw-semibold">{{ $devolucao['numero'] ?? '-' }}</div>
                                @if (! empty($devolucao['motivo']))
                                    <small class="text-secondary d-block">{{ \Illuminate\Support\Str::limit($devolucao['motivo'], 60) }}</small>
                                @endif
                            </td>
                            <td data-label="Venda">
                                <a href="{{ route('vendas.show', (int) ($devolucao['venda_id'] ?? 0)) }}">
                                    {{ $devolucao['venda_numero'] ?? '-' }}
                                </a>
                            </td>
                            <td data-label="Cliente">{{ $devolucao['cliente_nome'] ?: 'Consumidor final' }}</td>
                            <td data-label="Data">
                                {{ ! empty($devolucao['data_devolucao']) ? \Illuminate\Support\Carbon::parse($devolucao['data_devolucao'])->format('d/m/Y') : '-' }}
                            </td>
                            <td data-label="Reembolsado" class="text-end fw-semibold">
                                {{ $money($devolucao['valor_reembolsado'] ?? 0) }}
                                @if ($abatido > 0)
                                    <small class="text-secondary d-block">+ {{ $money($abatido) }} abatido</small>
                                @endif
                            </td>
                            <td data-label="Ações" class="text-end">
                                <x-list-actions>
                                    <li>
                                        <a href="{{ route('devolucoes.show', $devolucaoId) }}" class="dropdown-item">
                                            <i class="bi bi-eye me-2"></i>Detalhes
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('devolucoes.receipt', $devolucaoId) }}" class="dropdown-item" target="_blank" rel="noopener">
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
                'icon' => 'bi-arrow-return-left',
                'title' => 'Nenhuma devolução encontrada',
                'message' => 'Ajuste os filtros ou registre uma devolução abrindo a venda de origem.',
            ])
        @endif
    </section>
@endsection
