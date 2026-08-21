@extends('layouts.app')

@section('content')
    @php
        $hasActiveFilters = trim((string) ($filters['tipo'] ?? '')) !== ''
            || trim((string) ($filters['status'] ?? '')) !== ''
            || trim((string) ($filters['dre_fixo_mensal'] ?? '')) !== ''
            || trim((string) ($filters['mes'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['tipo'] ?? '')) !== '',
            trim((string) ($filters['status'] ?? '')) !== '',
            trim((string) ($filters['dre_fixo_mensal'] ?? '')) !== '',
            trim((string) ($filters['mes'] ?? '')) !== '',
        ]));
    @endphp

    <x-list-filters
        form-id="financeiroFilterPanel"
        :show-search="false"
        :results-count="$pagination['total'] ?? 0"
        results-label="lançamentos"
        :clear-url="route('financeiro.index')"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if ((int) ($filters['cliente_id'] ?? 0) > 0)
                <span class="desktop-chip">
                    <i class="bi bi-person"></i>
                    Cliente #{{ (int) $filters['cliente_id'] }}
                </span>
            @endif

            <div class="dropdown os-actions-dropdown">
                <button type="button"
                    class="btn btn-outline-info dropdown-toggle os-actions-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>
                    Relatórios
                </button>

                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                    <a href="{{ route('financeiro.relatorios.fluxo-caixa') }}" class="dropdown-item">
                        <i class="bi bi-calendar3-week me-2"></i>Fluxo de Caixa
                    </a>

                    <a href="{{ route('financeiro.relatorios.dre') }}" class="dropdown-item">
                        <i class="bi bi-graph-up-arrow me-2"></i>DRE por Competência
                    </a>

                    <a href="{{ route('financeiro.relatorios.dre-caixa') }}" class="dropdown-item">
                        <i class="bi bi-wallet2 me-2"></i>DRE de Caixa
                    </a>

                    <a href="{{ route('financeiro.relatorios.margem') }}" class="dropdown-item">
                        <i class="bi bi-graph-up me-2"></i>Margem por OS
                    </a>
                </div>
            </div>

            <div class="dropdown os-actions-dropdown">
                <button type="button"
                    class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    Mais ações
                </button>

                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                    <a href="{{ route('financeiro.despesas-fixas.index') }}" class="dropdown-item">
                        <i class="bi bi-pin-angle me-2"></i>Despesas
                    </a>

                    <a href="{{ route('financeiro.contas.index') }}" class="dropdown-item">
                        <i class="bi bi-wallet2 me-2"></i>Contas e Saldos
                    </a>

                    <a href="{{ route('financeiro.cartoes.index') }}" class="dropdown-item">
                        <i class="bi bi-credit-card-2-front me-2"></i>Cartões e Taxas
                    </a>

                    <a href="{{ route('financeiro.configuracoes') }}" class="dropdown-item">
                        <i class="bi bi-bar-chart-line me-2"></i>Configurações Financeiras
                    </a>

                    @if (\App\Support\DesktopSession::can('precificacao', 'visualizar'))
                        <a href="{{ route('financeiro.precificacao.index') }}" class="dropdown-item">
                            <i class="bi bi-calculator me-2"></i>Precificação
                        </a>
                    @endif
                </div>
            </div>

            @if (\App\Support\DesktopSession::can('financeiro', 'criar'))
                <a href="{{ route('financeiro.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Novo lançamento
                </a>
            @endif
        </x-slot:actions>

        @if ((int) ($filters['cliente_id'] ?? 0) > 0)
            <input type="hidden" name="cliente_id" value="{{ (int) $filters['cliente_id'] }}">
        @endif

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
            <label for="dre_fixo_mensal">Despesas fixas</label>
            <select id="dre_fixo_mensal" name="dre_fixo_mensal" class="form-select">
                <option value="" @selected(($filters['dre_fixo_mensal'] ?? '') === '')>Todas</option>
                <option value="1" @selected(($filters['dre_fixo_mensal'] ?? '') === '1')>Só fixas</option>
                <option value="0" @selected(($filters['dre_fixo_mensal'] ?? '') === '0')>Só variáveis</option>
            </select>
        </div>

        <div>
            <label for="mes">Mês (vencimento)</label>
            <input type="month" id="mes" name="mes" class="form-control" value="{{ $filters['mes'] ?? '' }}">
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

    @if (($filters['tipo'] ?? '') !== 'receber')
        @php $totaisDespesas = $totaisDespesas ?? ['fixas' => 0, 'variaveis' => 0]; @endphp
        <div class="desktop-grid desktop-grid-two mb-4">
            <div class="desktop-form-card text-center">
                <p class="surface-subtitle mb-1">Total despesas fixas{{ ($filters['mes'] ?? '') !== '' ? ' — ' . $filters['mes'] : '' }}</p>
                <h3 class="surface-title mb-0">R$ {{ number_format((float) ($totaisDespesas['fixas'] ?? 0), 2, ',', '.') }}</h3>
            </div>
            <div class="desktop-form-card text-center">
                <p class="surface-subtitle mb-1">Total despesas variáveis{{ ($filters['mes'] ?? '') !== '' ? ' — ' . $filters['mes'] : '' }}</p>
                <h3 class="surface-title mb-0">R$ {{ number_format((float) ($totaisDespesas['variaveis'] ?? 0), 2, ',', '.') }}</h3>
            </div>
        </div>
    @endif

    @include('financeiro._lancamentos_table', [
        'lancamentos' => $lancamentos,
        'pagination' => $pagination,
        'filters' => $filters,
        'cartaoDataset' => $cartaoDataset ?? [],
        'accountDataset' => $accountDataset ?? [],
    ])
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_FINANCEIRO_INDEX = {!! \Illuminate\Support\Js::from([
            'cartao' => $cartaoDataset ?? ['operadoras' => [], 'bandeiras' => [], 'taxas' => []],
            'contasFinanceiras' => $accountDataset ?? ['contas' => [], 'contas_padrao' => []],
        ]) !!};
    </script>
    <script src="{{ asset('assets/js/financeiro-pay.js') }}?v={{ filemtime(public_path('assets/js/financeiro-pay.js')) }}"></script>
    <script src="{{ asset('assets/js/financeiro-cancel-reason-modal.js') }}?v={{ filemtime(public_path('assets/js/financeiro-cancel-reason-modal.js')) }}"></script>
    <script src="{{ asset('assets/js/financeiro-delete-admin-modal.js') }}?v={{ filemtime(public_path('assets/js/financeiro-delete-admin-modal.js')) }}"></script>
@endsection
