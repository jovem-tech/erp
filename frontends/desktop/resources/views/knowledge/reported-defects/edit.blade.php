@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Defeitos Relatados</p>
            <h2 class="surface-title fs-3 mb-2">Editar defeito relatado</h2>
            <p class="surface-subtitle mb-0">Atualize o relato de defeito sem perder o vínculo com o catálogo exibido ao cliente.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('knowledge.reported-defects.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('knowledge.reported-defects.form', [
        'defeito' => $defeito ?? [],
        'equipmentTypes' => $equipmentTypes ?? [],
        'categoryMap' => $categoryMap ?? [],
        'formAction' => route('knowledge.reported-defects.update', $defeito['id'] ?? 0),
        'formMethod' => 'PUT',
        'formTitle' => 'Edição de defeito relatado',
        'formSubtitle' => 'Revise tipo de equipamento, categoria, relato e status antes de salvar as alterações.',
        'submitLabel' => 'Salvar alterações',
        'cancelUrl' => route('knowledge.reported-defects.index'),
    ])
@endsection
