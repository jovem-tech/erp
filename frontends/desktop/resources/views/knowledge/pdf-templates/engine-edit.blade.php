@extends('layouts.app')

@section('styles')
    <style>
        .pdf-editor-structure-column,
        .pdf-editor-config-column {
            min-width: 0;
        }

        #pdfeConfigPanel textarea {
            min-height: 180px;
            resize: vertical;
        }

        #pdfeConfigPanel textarea.font-monospace {
            min-height: clamp(320px, 50vh, 560px);
            line-height: 1.5;
        }

        @media (max-width: 991.98px) {
            #pdfeConfigPanel textarea.font-monospace {
                min-height: 300px;
            }
        }
    </style>
@endsection

@section('content')
    @php
        $canEdit = \App\Support\DesktopSession::can('conhecimento', 'editar');
        $canPublish = \App\Support\DesktopSession::can('conhecimento', 'publicar');
        $canRestore = \App\Support\DesktopSession::can('conhecimento', 'restaurar');
        $rascunho = $template['rascunho'] ?? null;
        $publicada = $template['versao_publicada'] ?? null;
        $schemaInicial = $rascunho['schema'] ?? ($publicada['schema'] ?? ['pagina' => [], 'cabecalho' => [], 'corpo' => [], 'rodape' => []]);
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Modelos PDF · Motor central</p>
            <h2 class="surface-title fs-3 mb-1">{{ $template['nome'] ?? 'Modelo PDF' }}</h2>
            <p class="surface-subtitle mb-2"><code>{{ $template['tipo_codigo'] ?? '' }}</code> — {{ $template['descricao'] ?? '' }}</p>
            <div class="d-flex gap-2">
                @if ($publicada !== null)
                    <span class="badge text-bg-success">Publicada: v{{ (int) $publicada['versao'] }}</span>
                @else
                    <span class="badge text-bg-danger">Sem versão publicada</span>
                @endif
                @if ($rascunho !== null)
                    <span class="badge text-bg-warning">Rascunho: v{{ (int) $rascunho['versao'] }}</span>
                @endif
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('knowledge.pdf-engine.index') }}" class="btn btn-soft">
                <i class="bi bi-arrow-left me-2"></i>Voltar
            </a>
            <button type="button" class="btn btn-soft" id="pdfeBtnPreview">
                <i class="bi bi-eye me-2"></i>Visualizar prévia
            </button>
            @if ($canEdit)
                <button type="button" class="btn btn-primary" id="pdfeBtnSave">
                    <i class="bi bi-save me-2"></i>Salvar rascunho
                </button>
            @endif
            @if ($canPublish)
                <button type="button" class="btn btn-success" id="pdfeBtnPublish">
                    <i class="bi bi-cloud-upload me-2"></i>Publicar
                </button>
            @endif
        </div>
    </div>

    <div id="pdfeStatus" class="alert d-none mb-3" role="alert"></div>

    <div class="row g-4 pdf-editor-columns">
        <div class="col-xl-4 col-lg-5 pdf-editor-structure-column">
            <section class="surface-card mb-4">
                <div class="surface-card-header">
                    <div>
                        <h2 class="surface-title fs-5">Estrutura do documento</h2>
                        <p class="surface-subtitle mb-0">Cabeçalho, corpo e rodapé montados por blocos. Selecione um bloco para configurá-lo.</p>
                    </div>
                </div>

                @foreach (['cabecalho' => 'Cabeçalho', 'corpo' => 'Corpo', 'rodape' => 'Rodapé'] as $areaKey => $areaLabel)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="fs-6 fw-bold mb-0 text-uppercase">{{ $areaLabel }}</h3>
                            @if ($canEdit)
                                <div class="d-flex gap-2">
                                    <select class="form-select form-select-sm" data-select2="false" data-pdfe-add-select="{{ $areaKey }}" style="width: auto;"></select>
                                    <button type="button" class="btn btn-soft btn-sm" data-pdfe-add-btn="{{ $areaKey }}">
                                        <i class="bi bi-plus-lg"></i> Bloco
                                    </button>
                                </div>
                            @endif
                        </div>
                        <div class="list-group" data-pdfe-area="{{ $areaKey }}"></div>
                    </div>
                @endforeach
            </section>

            <section class="surface-card">
                <div class="surface-card-header">
                    <div>
                        <h2 class="surface-title fs-5">Versões</h2>
                        <p class="surface-subtitle mb-0">Publicadas são imutáveis. Restaurar cria um novo rascunho a partir da versão escolhida.</p>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr><th>Versão</th><th>Status</th><th>Publicada em</th><th>Atualizada em</th><th class="text-end">Ações</th></tr>
                        </thead>
                        <tbody>
                        @forelse ((array) ($template['versoes'] ?? []) as $versao)
                            <tr>
                                <td>v{{ (int) $versao['versao'] }}</td>
                                <td>
                                    @if (($versao['status'] ?? '') === 'publicado')
                                        <span class="badge text-bg-success">Publicado</span>
                                    @elseif (($versao['status'] ?? '') === 'rascunho')
                                        <span class="badge text-bg-warning">Rascunho</span>
                                    @else
                                        <span class="badge text-bg-secondary">Arquivado</span>
                                    @endif
                                </td>
                                <td>{{ $versao['publicado_em'] ?? '—' }}</td>
                                <td>{{ $versao['updated_at'] ?? '—' }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-soft btn-sm" data-pdfe-preview-version="{{ (int) $versao['versao'] }}">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    @if ($canRestore && ($versao['status'] ?? '') !== 'rascunho')
                                        <button type="button" class="btn btn-soft btn-sm" data-pdfe-restore="{{ (int) $versao['versao'] }}" title="Restaurar como novo rascunho">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-secondary">Nenhuma versão registrada.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-xl-8 col-lg-7 pdf-editor-config-column">
            <section class="surface-card mb-4">
                <div class="surface-card-header">
                    <div>
                        <h2 class="surface-title fs-5">Configuração do bloco</h2>
                        <p class="surface-subtitle mb-0" id="pdfeConfigHint">Selecione um bloco na estrutura ao lado.</p>
                    </div>
                </div>
                <div id="pdfeConfigPanel" class="d-grid gap-3"></div>
            </section>

            <section class="surface-card mb-4">
                <div class="surface-card-header">
                    <div>
                        <h2 class="surface-title fs-5">Variáveis disponíveis</h2>
                        <p class="surface-subtitle mb-0">Somente as variáveis deste tipo documental são aceitas. Clique para inserir no campo em edição.</p>
                    </div>
                </div>
                <div class="d-flex gap-2 mb-2">
                    <select id="pdfeVariablePicker" class="form-select form-select-sm" data-select2="false"></select>
                    <button type="button" class="btn btn-soft btn-sm" id="pdfeVariableInsert">Inserir</button>
                </div>
                <small class="text-secondary">
                    Formatadores: <code>@{{ os.valor_final | moeda }}</code>, <code>| data</code>, <code>| data_hora</code>, <code>| telefone</code>, <code>| documento</code>, <code>| inteiro</code>, <code>| maiusculas</code>.
                </small>
            </section>

            <section class="surface-card">
                <div class="surface-card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="surface-title fs-5">Prévia</h2>
                        <p class="surface-subtitle mb-0">Dados simulados, ou informe o ID de uma OS{{ ($template['tipo_codigo'] ?? '') === 'os_orcamento' ? ' / orçamento' : '' }} real.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <select id="pdfePreviewFormat" class="form-select form-select-sm" data-select2="false" style="width: auto;">
                            <option value="a4">A4</option>
                            <option value="80mm">80mm</option>
                        </select>
                        <input type="number" min="1" id="pdfePreviewEntity" class="form-control form-control-sm" placeholder="ID real (opcional)" style="width: 130px;">
                    </div>
                </div>
                <iframe id="pdfePreviewFrame" title="Prévia do PDF" style="width: 100%; height: 520px; border: 1px solid var(--desktop-border); border-radius: 8px; background: #f8fafc;"></iframe>
            </section>
        </div>
    </div>

    <script>
        window.__PDF_TEMPLATE_EDITOR = {!! \Illuminate\Support\Js::from([
            'templateId' => (int) ($template['id'] ?? 0),
            'tipoCodigo' => (string) ($template['tipo_codigo'] ?? ''),
            'schema' => $schemaInicial,
            'draftUpdatedAt' => $rascunho['updated_at'] ?? null,
            'canEdit' => $canEdit,
            'metadata' => $metadata,
            'routes' => [
                'draft' => route('knowledge.pdf-engine.draft', ['template' => (int) ($template['id'] ?? 0)]),
                'publish' => route('knowledge.pdf-engine.publish', ['template' => (int) ($template['id'] ?? 0)]),
                'preview' => route('knowledge.pdf-engine.preview', ['template' => (int) ($template['id'] ?? 0)]),
                'restoreBase' => route('knowledge.pdf-engine.edit', ['template' => (int) ($template['id'] ?? 0)]) . '/versoes',
            ],
            'csrf' => csrf_token(),
        ]) !!};
    </script>
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/pdf-template-editor.js') }}?v={{ @filemtime(public_path('assets/js/pdf-template-editor.js')) ?: time() }}"></script>
@endsection
