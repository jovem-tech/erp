@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Clientes</p>
            <h2 class="surface-title fs-3 mb-2">Novo cliente</h2>
            <p class="surface-subtitle mb-0">Cadastro operacional para iniciar OS, vincular equipamentos e manter o histórico do atendimento.</p>
        </div>

        <a href="{{ route('clients.index') }}" class="btn btn-outline-light align-self-start">
            <i class="bi bi-arrow-left me-2"></i>
            Voltar
        </a>
    </div>

    @include('clients.form', [
        'client' => $client ?? [],
        'formAction' => route('clients.store'),
        'formMethod' => 'POST',
        'formTitle' => 'Cadastro operacional do cliente',
        'formSubtitle' => 'Nome e telefone principal são obrigatórios. Use CPF/CNPJ, endereço e contatos auxiliares para acelerar buscas, abertura de OS e liberações do portal.',
        'submitLabel' => 'Criar cliente',
        'cancelUrl' => route('clients.index'),
    ])
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
@endsection
