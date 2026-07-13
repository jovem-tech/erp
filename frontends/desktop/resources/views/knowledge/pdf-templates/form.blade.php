@php
    $template = $template ?? [];
    $formMethod = strtoupper((string) ($formMethod ?? 'POST'));
    $ativo = old('ativo', $template['ativo'] ?? true);
    $placeholders = $placeholders ?? [];
@endphp

<section class="desktop-form-card">
    <div class="desktop-form-intro">
        <div class="desktop-form-intro-copy">
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Cadastro de modelo de PDF' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $formSubtitle ?? 'Defina o código, o nome e o HTML usado para gerar o documento da OS.' }}
            </p>
        </div>
    </div>

    <form
        method="post"
        action="{{ $formAction }}"
        class="desktop-form-stack"
        id="pdfTemplateForm"
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
                    <input type="text" id="templateCodigo" name="codigo" class="form-control" value="{{ old('codigo', $template['codigo'] ?? '') }}" maxlength="255" required>
                </div>

                <div>
                    <label for="templateNome">Nome *</label>
                    <input type="text" id="templateNome" name="nome" class="form-control" value="{{ old('nome', $template['nome'] ?? '') }}" maxlength="255" required>
                </div>

                <div>
                    <label for="templateOrdem">Ordem</label>
                    <input type="number" id="templateOrdem" name="ordem" class="form-control" min="0" value="{{ old('ordem', $template['ordem'] ?? 0) }}">
                </div>
            </div>

            <div class="mt-3">
                <label for="templateDescricao">Descrição</label>
                <textarea id="templateDescricao" name="descricao" class="form-control" rows="2" placeholder="Descrição interna do modelo">{{ old('descricao', $template['descricao'] ?? '') }}</textarea>
            </div>

            <div class="form-check form-switch mt-3">
                <input type="hidden" name="ativo" value="0">
                <input class="form-check-input" type="checkbox" name="ativo" id="templateAtivo" value="1" @checked((bool) $ativo)>
                <label class="form-check-label" for="templateAtivo">Modelo ativo no sistema</label>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-filetype-html"></i>
                <span>CONTEÚDO HTML</span>
            </div>

            <div>
                <label for="templateConteudoHtml">HTML do documento *</label>
                <textarea name="conteudo_html" id="templateConteudoHtml" rows="14" class="form-control font-monospace" required>{{ old('conteudo_html', $template['conteudo_html'] ?? '') }}</textarea>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-braces"></i>
                <span>PLACEHOLDERS DISPONÍVEIS</span>
            </div>

            <p class="surface-subtitle mb-2">
                Use os tokens abaixo dentro do HTML para inserir dados da OS automaticamente.
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
            <a href="{{ $cancelUrl ?? route('knowledge.pdf-templates.index') }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar modelo de PDF' }}</button>
        </div>
    </form>
</section>