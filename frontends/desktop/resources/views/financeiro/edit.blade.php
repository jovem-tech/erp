@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Editar lançamento</h2>
            <p class="surface-subtitle mb-0">Ajuste os dados do título. Campos sensíveis ficam bloqueados quando já existe baixa registrada.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('financeiro.form', [
        'lancamento' => $lancamento ?? [],
        'resumo' => $resumo ?? [],
        'categorias' => $categorias ?? [],
        'canQuickClient' => $canQuickClient ?? false,
        'formAction' => route('financeiro.update', $lancamento['id'] ?? 0),
        'formMethod' => 'PUT',
        'formTitle' => 'Lançamento #' . ($lancamento['id'] ?? '-'),
        'submitLabel' => 'Salvar alterações',
        'cancelUrl' => route('financeiro.index'),
    ])
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_FINANCEIRO_FORM = {!! json_encode([
            'clientSearchUrl' => route('financeiro.clients.search'),
            'quickClientStoreUrl' => route('clients.quick.store'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
    </script>
    <script src="{{ asset('assets/js/financeiro-form.js') }}?v={{ filemtime(public_path('assets/js/financeiro-form.js')) }}"></script>
    @if ($canQuickClient ?? false)
        <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
    @endif
@endsection
