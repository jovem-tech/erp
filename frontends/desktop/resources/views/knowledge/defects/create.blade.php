@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Base de Defeitos</p>
            <h2 class="surface-title fs-3 mb-2">Novo defeito</h2>
            <p class="surface-subtitle mb-0">Cadastre um defeito comum para um tipo de equipamento.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('knowledge.defects.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('knowledge.defects.form', [
        'defeito' => $defeito ?? [],
        'equipmentTypes' => $equipmentTypes ?? [],
        'formAction' => route('knowledge.defects.store'),
        'formMethod' => 'POST',
        'formTitle' => 'Cadastro de defeito',
        'formSubtitle' => 'Defina nome, tipo de equipamento e classificação do defeito.',
        'submitLabel' => 'Criar defeito',
        'cancelUrl' => route('knowledge.defects.index'),
    ])
@endsection
