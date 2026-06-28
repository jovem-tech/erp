@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Modelos PDF</p>
            <h2 class="surface-title fs-3 mb-2">Editar modelo de PDF</h2>
            <p class="surface-subtitle mb-0">Atualize o modelo de documento sem perder o vínculo com os PDFs já gerados.</p>
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
        'formAction' => route('knowledge.pdf-templates.update', $template['id'] ?? 0),
        'formMethod' => 'PUT',
        'formTitle' => 'Edição de modelo de PDF',
        'formSubtitle' => 'Revise código, nome, HTML e status antes de salvar as alterações.',
        'submitLabel' => 'Salvar alterações',
        'cancelUrl' => route('knowledge.pdf-templates.index'),
    ])
@endsection
