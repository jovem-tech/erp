@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Clientes</p>
            <h2 class="surface-title fs-3 mb-2">Editar cliente</h2>
            <p class="surface-subtitle mb-0">Atualize o cadastro operacional sem perder o contexto do cliente no fluxo de atendimento.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('clients.show', $client['id'] ?? 0) }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
            <a href="{{ route('clients.index') }}" class="btn btn-soft">
                Ver lista
            </a>
        </div>
    </div>

    @include('clients.form', [
        'client' => $client ?? [],
        'formAction' => route('clients.update', $client['id'] ?? 0),
        'formMethod' => 'PUT',
        'formTitle' => 'Edição de cliente',
        'formSubtitle' => 'Revise dados cadastrais, contatos e endereço antes de salvar as alterações.',
        'submitLabel' => 'Salvar alterações',
        'cancelUrl' => route('clients.show', $client['id'] ?? 0),
    ])
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
@endsection
