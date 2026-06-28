@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Templates WhatsApp</p>
            <h2 class="surface-title fs-3 mb-2">Editar template de WhatsApp</h2>
            <p class="surface-subtitle mb-0">Atualize a mensagem automática sem perder o vínculo com os eventos já configurados.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('knowledge.whatsapp-templates.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('knowledge.whatsapp-templates.form', [
        'template' => $template ?? [],
        'placeholders' => $placeholders ?? [],
        'formAction' => route('knowledge.whatsapp-templates.update', $template['id'] ?? 0),
        'formMethod' => 'PUT',
        'formTitle' => 'Edição de template de WhatsApp',
        'formSubtitle' => 'Revise código, nome, evento, conteúdo e status antes de salvar as alterações.',
        'submitLabel' => 'Salvar alterações',
        'cancelUrl' => route('knowledge.whatsapp-templates.index'),
    ])
@endsection
