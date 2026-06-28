@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Fornecedores</p>
            <h2 class="surface-title fs-3 mb-2">Novo fornecedor</h2>
            <p class="surface-subtitle mb-0">Cadastro comercial com consulta por CNPJ, status e endereço para uso operacional no ERP.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('suppliers.help') }}" class="btn btn-outline-info">
                <i class="bi bi-question-circle me-2"></i>
                Ajuda
            </a>
            <a href="{{ route('suppliers.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('suppliers.form', [
        'supplier' => $supplier ?? [],
        'formAction' => route('suppliers.store'),
        'formMethod' => 'POST',
        'formTitle' => 'Cadastro operacional do fornecedor',
        'formSubtitle' => 'Use CNPJ para acelerar o preenchimento dos dados públicos. Telefone principal e nome fantasia são os campos mais importantes para a operação diária.',
        'submitLabel' => 'Criar fornecedor',
        'cancelUrl' => route('suppliers.index'),
    ])
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/suppliers-form.js') }}?v={{ filemtime(public_path('assets/js/suppliers-form.js')) }}"></script>
@endsection
