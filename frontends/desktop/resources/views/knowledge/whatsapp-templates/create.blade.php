@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Templates WhatsApp</p>
            <h2 class="surface-title fs-3 mb-2">Novo template de WhatsApp</h2>
            <p class="surface-subtitle mb-0">Cadastre uma mensagem automática de WhatsApp para eventos da OS e follow-up de clientes.</p>
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
        'formAction' => route('knowledge.whatsapp-templates.store'),
        'formMethod' => 'POST',
        'formTitle' => 'Cadastro de template de WhatsApp',
        'formSubtitle' => 'Defina código, nome, evento e o conteúdo da mensagem automática.',
        'submitLabel' => 'Criar template de WhatsApp',
        'cancelUrl' => route('knowledge.whatsapp-templates.index'),
    ])
@endsection
