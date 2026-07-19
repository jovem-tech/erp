@extends('layouts.app')

@section('content')
    @php
        $canEdit = \App\Support\DesktopSession::can('conhecimento', 'editar');
        $tipos = collect($tipos ?? []);
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div class="flex-grow-1">
            <p class="desktop-eyebrow">Gestão de Conhecimento</p>
            <h2 class="surface-title fs-3 mb-2">Modelos PDF</h2>
            <p class="surface-subtitle mb-0">
                Motor central de documentos: todo PDF do sistema é gerado a partir dos modelos abaixo.
                Edite por blocos, pré-visualize, publique — novas emissões passam a usar a versão publicada;
                PDFs já emitidos permanecem imutáveis.
            </p>
        </div>
        @if ($canEdit)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newDocumentModal">
                <i class="bi bi-plus-lg me-1"></i> Novo documento
            </button>
        @endif
    </div>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Tipos documentais do sistema</h2>
                <p class="surface-subtitle mb-0">Cada tipo tem um modelo versionado. Publicado = em uso nas emissões; rascunho = edição em andamento.</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                <tr>
                    <th>Documento</th>
                    <th>Código</th>
                    <th>Versão publicada</th>
                    <th>Rascunho</th>
                    <th>Gatilhos automáticos</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($tipos as $tipo)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $tipo['nome'] ?? '' }}</div>
                            <small class="text-secondary">{{ $tipo['descricao'] ?? '' }}</small>
                        </td>
                        <td><code>{{ $tipo['tipo_codigo'] ?? '' }}</code></td>
                        <td>
                            @if (($tipo['versao_publicada'] ?? null) !== null)
                                <span class="badge text-bg-success">v{{ (int) $tipo['versao_publicada'] }}</span>
                                <div><small class="text-secondary">{{ $tipo['publicado_em'] ?? '' }}</small></div>
                            @else
                                <span class="badge text-bg-danger">Sem versão publicada</span>
                            @endif
                        </td>
                        <td>
                            @if ($tipo['tem_rascunho'] ?? false)
                                <span class="badge text-bg-warning">v{{ (int) ($tipo['versao_rascunho'] ?? 0) }} em edição</span>
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td>
                            @forelse ((array) ($tipo['gatilhos_automaticos'] ?? []) as $gatilho)
                                <span class="desktop-chip">{{ $gatilho }}</span>
                            @empty
                                <span class="text-secondary">Manual</span>
                            @endforelse
                        </td>
                        <td class="text-end">
                            @if (($tipo['template_id'] ?? null) !== null)
                                <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                    <a
                                        href="{{ route('knowledge.pdf-engine.edit', ['template' => (int) $tipo['template_id']]) }}"
                                        class="btn btn-soft btn-sm"
                                    >
                                        <i class="bi bi-pencil-square me-1"></i>
                                        {{ $canEdit ? 'Editar modelo' : 'Ver modelo' }}
                                    </a>
                                    @if ($canEdit)
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm js-clone-document"
                                            data-bs-toggle="modal"
                                            data-bs-target="#cloneDocumentModal"
                                            data-clone-url="{{ route('knowledge.pdf-engine.clone', ['template' => (int) $tipo['template_id']]) }}"
                                            data-source-name="{{ $tipo['nome'] ?? '' }}"
                                            data-source-description="{{ $tipo['descricao'] ?? '' }}"
                                        >
                                            <i class="bi bi-copy me-1"></i> Clonar
                                        </button>
                                    @endif
                                </div>
                            @else
                                <span class="text-secondary" title="Rode as migrations do backend para semear este tipo.">Não semeado</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-secondary">Nenhum tipo documental registrado.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($canEdit)
        <div class="modal fade" id="newDocumentModal" tabindex="-1" aria-labelledby="newDocumentModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="{{ route('knowledge.pdf-engine.store') }}" class="modal-content border-0 shadow">
                    @csrf
                    <div class="modal-header border-0 pb-0">
                        <div>
                            <h2 class="modal-title fs-5" id="newDocumentModalLabel">Criar documento do zero</h2>
                            <p class="text-secondary small mb-0">O sistema prepara cabeçalho, corpo e rodapé; depois você edita os blocos.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="new-document-name" class="form-label fw-semibold">Nome do documento</label>
                            <input id="new-document-name" name="nome" type="text" class="form-control" maxlength="120" required placeholder="Ex.: Termo de garantia">
                        </div>
                        <div class="mb-3">
                            <label for="new-document-description" class="form-label fw-semibold">Descrição <span class="text-secondary fw-normal">(opcional)</span></label>
                            <textarea id="new-document-description" name="descricao" class="form-control" rows="2" maxlength="1000" placeholder="Explique quando este documento deve ser usado."></textarea>
                        </div>
                        <div>
                            <label for="new-document-source" class="form-label fw-semibold">Fonte de dados</label>
                            <select id="new-document-source" name="tipo_base_codigo" class="form-select" required>
                                <option value="os_abertura">Dados gerais da OS, cliente e equipamento</option>
                                <option value="os_orcamento">OS e orçamento com itens e totais</option>
                                <option value="os_encerramento">OS encerrada, recebimentos e garantia</option>
                            </select>
                            <div class="form-text">A fonte define quais campos e tabelas estarão disponíveis no editor.</div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Criar e editar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="cloneDocumentModal" tabindex="-1" aria-labelledby="cloneDocumentModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="" class="modal-content border-0 shadow" id="clone-document-form">
                    @csrf
                    <div class="modal-header border-0 pb-0">
                        <div>
                            <h2 class="modal-title fs-5" id="cloneDocumentModalLabel">Clonar documento</h2>
                            <p class="text-secondary small mb-0">A cópia será independente. O documento original não será alterado.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="rounded-3 bg-light p-3 mb-3">
                            <small class="text-secondary d-block">Documento de origem</small>
                            <strong id="clone-source-name"></strong>
                        </div>
                        <div class="mb-3">
                            <label for="clone-document-name" class="form-label fw-semibold">Nome da nova cópia</label>
                            <input id="clone-document-name" name="nome" type="text" class="form-control" maxlength="120" required>
                        </div>
                        <div>
                            <label for="clone-document-description" class="form-label fw-semibold">Descrição <span class="text-secondary fw-normal">(opcional)</span></label>
                            <textarea id="clone-document-description" name="descricao" class="form-control" rows="2" maxlength="1000"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-copy me-1"></i> Clonar e editar</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    @if ($canEdit)
        <script>
            document.querySelectorAll('.js-clone-document').forEach((button) => {
                button.addEventListener('click', () => {
                    const sourceName = button.dataset.sourceName || 'Documento';
                    document.getElementById('clone-document-form').action = button.dataset.cloneUrl || '';
                    document.getElementById('clone-source-name').textContent = sourceName;
                    document.getElementById('clone-document-name').value = `${sourceName} - cópia`.slice(0, 120);
                    document.getElementById('clone-document-description').value = (button.dataset.sourceDescription || '').slice(0, 1000);
                });
            });
        </script>
    @endif
@endpush
