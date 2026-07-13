@php
    $template = $template ?? [];
    $formMethod = strtoupper((string) ($formMethod ?? 'POST'));
    $ativo = old('ativo', $template['ativo'] ?? true);
    $placeholders = $placeholders ?? [];
@endphp

<section class="desktop-form-card">
    <div class="desktop-form-intro">
        <div class="desktop-form-intro-copy">
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Cadastro de template de WhatsApp' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $formSubtitle ?? 'Defina o código, o nome e o conteúdo da mensagem automática de WhatsApp.' }}
            </p>
        </div>
    </div>

    <form
        method="post"
        action="{{ $formAction }}"
        class="desktop-form-stack"
        id="whatsappTemplateForm"
    >
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-tags"></i>
                <span>IDENTIFICAÇÃO</span>
            </div>

            <div class="desktop-grid desktop-grid-three">
                <div>
                    <label for="templateCodigo">Código *</label>
                    <input type="text" id="templateCodigo" name="codigo" class="form-control" value="{{ old('codigo', $template['codigo'] ?? '') }}" maxlength="80" required>
                </div>

                <div>
                    <label for="templateNome">Nome *</label>
                    <input type="text" id="templateNome" name="nome" class="form-control" value="{{ old('nome', $template['nome'] ?? '') }}" maxlength="140" required>
                </div>

                <div>
                    <label for="templateEvento">Evento</label>
                    <input type="text" id="templateEvento" name="evento" class="form-control" value="{{ old('evento', $template['evento'] ?? '') }}" maxlength="80" placeholder="ex.: status_aguardando_autorizacao">
                </div>
            </div>

            <div class="form-check form-switch mt-3">
                <input type="hidden" name="ativo" value="0">
                <input class="form-check-input" type="checkbox" name="ativo" id="templateAtivo" value="1" @checked((bool) $ativo)>
                <label class="form-check-label" for="templateAtivo">Template ativo no sistema</label>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-whatsapp"></i>
                <span>CONTEÚDO DA MENSAGEM</span>
            </div>

            <div>
                <label for="templateConteudo">Mensagem *</label>
                <textarea name="conteudo" id="templateConteudo" rows="6" class="form-control" required>{{ old('conteudo', $template['conteudo'] ?? '') }}</textarea>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-braces"></i>
                <span>PLACEHOLDERS DISPONÍVEIS</span>
            </div>

            <p class="surface-subtitle mb-2">
                Use os tokens abaixo dentro da mensagem para inserir dados da OS automaticamente.
            </p>

            <ul class="list-unstyled mb-0">
                @foreach ($placeholders as $item)
                    <li class="mb-1">
                        <code>{{ '{' . '{' . ($item['token'] ?? '') . '}' . '}' }}</code> — {{ $item['label'] ?? '' }}
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl ?? route('knowledge.whatsapp-templates.index') }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar template de WhatsApp' }}</button>
        </div>
    </form>
</section>
