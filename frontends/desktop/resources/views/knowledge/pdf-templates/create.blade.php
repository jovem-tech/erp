@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Modelos PDF</p>
            <h2 class="surface-title fs-3 mb-2">Novo modelo de PDF</h2>
            <p class="surface-subtitle mb-0">Cadastre um modelo de documento HTML usado para gerar PDFs de OS, laudos e comprovantes.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('knowledge.pdf-templates.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('knowledge.pdf-templates.form', [
        'template' => $template ?? [],
        'placeholders' => $placeholders ?? [],
        'formAction' => route('knowledge.pdf-templates.store'),
        'formMethod' => 'POST',
        'formTitle' => 'Cadastro de modelo de PDF',
        'formSubtitle' => 'Defina código, nome e o HTML que será usado para gerar o documento.',
        'submitLabel' => 'Criar modelo de PDF',
        'cancelUrl' => route('knowledge.pdf-templates.index'),
    ])
@endsection
