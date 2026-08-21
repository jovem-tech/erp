@extends('layouts.app')

@section('content')
    @php $tipoLocked = $tipoLocked ?? false; @endphp
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">{{ $tipoLocked ? 'Nova despesa' : 'Novo lançamento' }}</h2>
            <p class="surface-subtitle mb-0">
                @if ($tipoLocked)
                    Cadastre uma conta a pagar e vincule a uma OS, cliente ou fornecedor quando aplicável.
                @else
                    Cadastre um título a receber ou a pagar e vincule a uma OS, cliente ou fornecedor quando aplicável.
                @endif
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.configuracoes') }}" class="btn btn-outline-info">
                <i class="bi bi-bar-chart-line me-2"></i>
                Configurações financeiras
            </a>
            <a href="{{ $tipoLocked ? route('financeiro.despesas-fixas.index') : route('financeiro.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('financeiro.form', [
        'lancamento' => $lancamento ?? [],
        'categorias' => $categorias ?? [],
        'accountDataset' => $accountDataset ?? [],
        'canQuickClient' => $canQuickClient ?? false,
        'tipoLocked' => $tipoLocked,
        'formAction' => route('financeiro.store'),
        'formMethod' => 'POST',
        'formTitle' => 'Cadastro do lançamento',
        'submitLabel' => $tipoLocked ? 'Criar despesa' : 'Criar lançamento',
        'cancelUrl' => $tipoLocked ? route('financeiro.despesas-fixas.index') : route('financeiro.index'),
    ])
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_FINANCEIRO_FORM = {!! \Illuminate\Support\Js::from([
            'clientSearchUrl' => route('financeiro.clients.search'),
            'orderSearchUrl' => route('financeiro.orders.search'),
            'supplierSearchUrl' => route('financeiro.suppliers.search'),
            'quickClientStoreUrl' => route('clients.quick.store'),
            'contasFinanceiras' => $accountDataset ?? ['contas' => [], 'contas_padrao' => []],
            'categorias' => $categorias ?? [],
        ]) !!};
    </script>
    <script src="{{ asset('assets/js/financeiro-form.js') }}?v={{ filemtime(public_path('assets/js/financeiro-form.js')) }}"></script>
    @if ($canQuickClient ?? false)
        <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
    @endif
@endsection
