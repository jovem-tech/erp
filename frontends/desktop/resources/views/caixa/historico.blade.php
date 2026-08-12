@extends('layouts.app')

@section('content')
    @php
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['status'] ?? '')) !== '',
            trim((string) ($filters['data_inicio'] ?? '')) !== '',
            trim((string) ($filters['data_fim'] ?? '')) !== '',
            (bool) ($filters['com_diferenca'] ?? false),
        ]));
        $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    @endphp

    <x-list-filters
        form-id="caixaFilterPanel"
        search-name="search"
        search-value=""
        search-placeholder="Filtre pelos campos ao lado"
        :results-count="$pagination['total'] ?? 0"
        results-label="turnos"
        :clear-url="route('caixa.historico')"
        :has-active-filters="$activeFilterCount > 0"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            <a href="{{ route('caixa.index') }}" class="btn btn-primary">
                <i class="bi bi-cash-stack me-2"></i>Caixa atual
            </a>
        </x-slot:actions>

        <div>
            <label for="status">Situação</label>
            <select id="status" name="status" class="form-select">
                <option value="">Todas</option>
                <option value="aberta" @selected(($filters['status'] ?? '') === 'aberta')>Aberta</option>
                <option value="fechada" @selected(($filters['status'] ?? '') === 'fechada')>Fechada</option>
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
            <label for="com_diferenca">Conferência</label>
            <select id="com_diferenca" name="com_diferenca" class="form-select">
                <option value="">Todos os turnos</option>
                <option value="1" @selected((bool) ($filters['com_diferenca'] ?? false))>Só com diferença</option>
            </select>
        </div>
    </x-list-filters>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Histórico de caixa</h2>
                <p class="surface-subtitle">Turnos abertos e fechados, com a diferença apurada em cada conferência.</p>
            </div>
        </div>

        @if ($sessoes !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Turno</th>
                        <th>Operador</th>
                        <th>Período</th>
                        <th class="text-end">Vendas</th>
                        <th class="text-end">Contado</th>
                        <th class="text-end">Diferença</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($sessoes as $sessao)
                        @php
                            $sessao = is_array($sessao) ? $sessao : [];
                            $sessaoId = (int) ($sessao['id'] ?? 0);
                            $diferenca = $sessao['diferenca'];
                        @endphp
                        <tr>
                            <td data-label="Turno">
                                <div class="fw-semibold">#{{ $sessaoId }}</div>
                                @include('layouts.partials.status-pill', [
                                    'label' => $sessao['status_label'] ?? '-',
                                    'color' => ($sessao['status'] ?? '') === 'fechada' ? '#6b7280' : '#16a34a',
                                    'small' => true,
                                ])
                            </td>
                            <td data-label="Operador">{{ $sessao['operador_nome'] ?? '-' }}</td>
                            <td data-label="Período">
                                {{ ! empty($sessao['aberto_em']) ? \Illuminate\Support\Carbon::parse($sessao['aberto_em'])->format('d/m/Y H:i') : '-' }}
                                @if (! empty($sessao['fechado_em']))
                                    <small class="text-secondary d-block">
                                        até {{ \Illuminate\Support\Carbon::parse($sessao['fechado_em'])->format('d/m/Y H:i') }}
                                    </small>
                                @endif
                            </td>
                            <td data-label="Vendas" class="text-end">
                                {{ $money($sessao['total_vendas_dinheiro'] ?? 0) }}
                                <small class="text-secondary d-block">{{ (int) ($sessao['quantidade_vendas'] ?? 0) }} venda(s)</small>
                            </td>
                            <td data-label="Contado" class="text-end">
                                {{ $sessao['valor_informado'] !== null ? $money($sessao['valor_informado']) : '—' }}
                            </td>
                            <td data-label="Diferença" class="text-end fw-semibold {{ $diferenca !== null && (float) $diferenca < 0 ? 'text-danger' : ($diferenca !== null && (float) $diferenca > 0 ? 'text-warning' : '') }}">
                                {{ $diferenca !== null ? $money($diferenca) : '—' }}
                            </td>
                            <td data-label="Ações" class="text-end">
                                <x-list-actions>
                                    <li>
                                        <a href="{{ route('caixa.show', $sessaoId) }}" class="dropdown-item">
                                            <i class="bi bi-eye me-2"></i>Detalhes
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('caixa.report', $sessaoId) }}" class="dropdown-item" target="_blank" rel="noopener">
                                            <i class="bi bi-printer me-2"></i>Relatório
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
                'icon' => 'bi-cash-stack',
                'title' => 'Nenhum turno encontrado',
                'message' => 'Ajuste os filtros ou abra o primeiro caixa para começar o controle da gaveta.',
            ])
        @endif
    </section>
@endsection
