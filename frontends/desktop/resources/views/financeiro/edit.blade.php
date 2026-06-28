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
        'formAction' => route('financeiro.update', $lancamento['id'] ?? 0),
        'formMethod' => 'PUT',
        'formTitle' => 'Lançamento #' . ($lancamento['id'] ?? '-'),
        'submitLabel' => 'Salvar alterações',
        'cancelUrl' => route('financeiro.index'),
    ])
@endsection
