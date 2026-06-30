@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Defeitos Relatados</p>
            <h2 class="surface-title fs-3 mb-2">Novo defeito relatado</h2>
            <p class="surface-subtitle mb-0">Cadastre um relato de defeito exibido para o cliente na abertura de OS.</p>
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
        'formAction' => route('knowledge.reported-defects.store'),
        'formMethod' => 'POST',
        'formTitle' => 'Cadastro de defeito relatado',
        'formSubtitle' => 'Defina tipo de equipamento, categoria, subcategoria e o texto exibido para o cliente.',
        'submitLabel' => 'Criar defeito relatado',
        'cancelUrl' => route('knowledge.reported-defects.index'),
    ])
@endsection
