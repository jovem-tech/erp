@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Novo lançamento</h2>
            <p class="surface-subtitle mb-0">Cadastre um título a receber ou a pagar e vincule a uma OS, cliente ou fornecedor quando aplicável.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.configuracoes') }}" class="btn btn-outline-info">
                <i class="bi bi-bar-chart-line me-2"></i>
                Configurações financeiras
            </a>
            <a href="{{ route('financeiro.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('financeiro.form', [
        'lancamento' => $lancamento ?? [],
        'categorias' => $categorias ?? [],
        'formAction' => route('financeiro.store'),
        'formMethod' => 'POST',
        'formTitle' => 'Cadastro do lançamento',
        'submitLabel' => 'Criar lançamento',
        'cancelUrl' => route('financeiro.index'),
    ])
@endsection
