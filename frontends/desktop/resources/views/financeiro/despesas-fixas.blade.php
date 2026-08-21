@extends('layouts.app')

@section('content')
    @php
        $hasActiveFilters = trim((string) ($filters['status'] ?? '')) !== ''
            || trim((string) ($filters['mes'] ?? '')) !== ''
            || trim((string) ($filters['dre_fixo_mensal'] ?? '')) !== '';
        $activeFilterCount = count(array_filter([
            trim((string) ($filters['status'] ?? '')) !== '',
            trim((string) ($filters['mes'] ?? '')) !== '',
            trim((string) ($filters['dre_fixo_mensal'] ?? '')) !== '',
        ]));
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Despesas</h2>
            <p class="surface-subtitle mb-0">Todas as contas a pagar da assistência — fixas (água, luz, internet, pró-labore...) e variáveis (peças, imprevistos...).</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar para Lançamentos
            </a>
        </div>
    </div>

    <x-list-filters
        form-id="despesasFixasFilterPanel"
        :show-search="false"
        :results-count="$pagination['total'] ?? 0"
        results-label="despesas"
        :clear-url="route('financeiro.despesas-fixas.index')"
        :has-active-filters="$hasActiveFilters"
        :active-filter-count="$activeFilterCount"
    >
        <x-slot:actions>
            @if (\App\Support\DesktopSession::can('financeiro', 'criar'))
                <a href="{{ route('financeiro.create', ['tipo' => 'pagar']) }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>
                    Nova despesa
                </a>
            @endif
        </x-slot:actions>

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
            <label for="dre_fixo_mensal">Tipo de despesa</label>
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

    @include('financeiro._lancamentos_table', [
        'lancamentos' => $lancamentos,
        'pagination' => $pagination,
        'filters' => $filters,
        'cartaoDataset' => $cartaoDataset ?? [],
        'accountDataset' => $accountDataset ?? [],
        'emptyTitle' => 'Nenhuma despesa encontrada',
        'emptyMessage' => 'Ajuste os filtros ou cadastre uma nova despesa para ela aparecer aqui.',
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
