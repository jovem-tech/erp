@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Fornecedores</p>
            <h2 class="surface-title fs-3 mb-2">Editar fornecedor</h2>
            <p class="surface-subtitle mb-0">Atualize o cadastro comercial sem perder o vínculo com o fluxo operacional do ERP.</p>
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
        'formAction' => route('suppliers.update', $supplier['id'] ?? 0),
        'formMethod' => 'PUT',
        'formTitle' => 'Edição de fornecedor',
        'formSubtitle' => 'Revise documento, contato, endereço e situação antes de salvar as alterações.',
        'submitLabel' => 'Salvar alterações',
        'cancelUrl' => route('suppliers.index'),
    ])
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/suppliers-form.js') }}?v={{ filemtime(public_path('assets/js/suppliers-form.js')) }}"></script>
@endsection
