@if ($catalog !== [])
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
            <tr>
                <th style="width: 48px;"></th>
                <th>Tipo</th>
                <th>Documento</th>
                <th>Versão</th>
                <th>Origem</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($catalog as $type)
                @php
                    $versions = is_array($type['versions'] ?? null) ? $type['versions'] : [];
                    $latest = is_array($type['latest_document'] ?? null) ? $type['latest_document'] : null;
                    $canGenerate = (bool) ($type['can_generate'] ?? false);
                    $typeCode = (string) ($type['type'] ?? '');
                    $triggers = is_array($type['automatic_triggers'] ?? null) ? $type['automatic_triggers'] : [];
                    $origem = $triggers === []
                        ? 'Geração manual'
                        : 'Manual + automático (' . implode(', ', $triggers) . ')';
                    $documentId = $latest ? (int) ($latest['id'] ?? 0) : 0;
                    $files = $latest && is_array($latest['files'] ?? null) ? $latest['files'] : [];
                    $isArchived = $latest && ($latest['archived_at'] ?? null) !== null;
                @endphp
                <tr class="doc-type-card"
                    data-doc-type-card="{{ $typeCode }}"
                    data-document-template-code="{{ $type['template_code'] ?? '' }}"
                    data-document-suggested-message="{{ $latest['suggested_message'] ?? '' }}"
                    data-document-label="{{ $type['label'] ?? 'Documento' }}">
                    <td>
                        <input class="form-check-input"
                               type="checkbox"
                               value="{{ $typeCode }}"
                               data-catalog-checkbox
                               {{ $canGenerate ? '' : 'disabled' }}>
                    </td>
                    <td><span class="text-secondary small">{{ $typeCode }}</span></td>
                    <td><strong>{{ $type['label'] ?? 'Documento' }}</strong></td>
                    <td>
                        @if ($versions !== [])
                            <select class="form-select form-select-sm" data-select2="false" data-doc-version-select style="min-width: 150px;">
                                @foreach ($versions as $index => $version)
                                    @php
                                        $versionFiles = is_array($version['files'] ?? null) ? $version['files'] : [];
                                        $versionCreatedAt = isset($version['created_at'])
                                            ? \Illuminate\Support\Carbon::parse($version['created_at'])->format('d/m/Y H:i')
                                            : '';
                                    @endphp
                                    <option
                                        value="{{ (int) ($version['id'] ?? 0) }}"
                                        data-archived="{{ ($version['archived_at'] ?? null) !== null ? '1' : '0' }}"
                                        data-a4-available="{{ ($versionFiles['a4']['available'] ?? false) ? '1' : '0' }}"
                                        data-a4-url="{{ route('orders.documents.files.show', ['order' => $orderId, 'document' => (int) ($version['id'] ?? 0), 'format' => 'a4']) }}"
                                        data-thermal-available="{{ ($versionFiles['80mm']['available'] ?? false) ? '1' : '0' }}"
                                        data-thermal-url="{{ route('orders.documents.files.show', ['order' => $orderId, 'document' => (int) ($version['id'] ?? 0), 'format' => '80mm']) }}"
                                        data-suggested-message="{{ $version['suggested_message'] ?? '' }}"
                                        data-signed="{{ ($version['signature']['signed'] ?? false) ? '1' : '0' }}"
                                        data-signer="{{ $version['signature']['signer']['name'] ?? '' }}"
                                        {{ $index === 0 ? 'selected' : '' }}
                                    >v{{ $version['version'] ?? 1 }}{{ $versionCreatedAt !== '' ? ' — ' . $versionCreatedAt : '' }}</option>
                                @endforeach
                            </select>
                        @else
                            <span class="text-secondary">Sem versão</span>
                        @endif
                    </td>
                    <td>{{ $origem }}</td>
                    <td>
                        @if ($canGenerate)
                            <span class="desktop-chip desktop-chip-success">Liberado</span>
                        @else
                            <span class="text-danger small">{{ $type['blocked_reason'] ?? 'Pré-requisito ausente.' }}</span>
                        @endif
                        <span class="desktop-chip {{ $isArchived ? '' : 'd-none' }}" data-doc-status-badge>Arquivado</span>
                        @if ($latest && ($latest['signature']['signed'] ?? false))
                            <span class="desktop-chip desktop-chip-success" title="{{ $latest['signature']['method'] ?? '' }}">
                                <i class="bi bi-patch-check me-1"></i>Assinado por {{ $latest['signature']['signer']['name'] ?? 'usuário' }}
                            </span>
                        @endif
                    </td>
                    <td class="text-end">
                        <div class="d-flex flex-wrap justify-content-end gap-2">
                            @if ($canGenerate)
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-doc-generate-type="{{ $typeCode }}">
                                    <i class="bi bi-file-earmark-plus me-2"></i>{{ $latest ? 'Gerar nova versão' : 'Gerar' }}
                                </button>
                            @endif

                            @if ($latest)
                                <div class="dropdown os-actions-dropdown">
                                    <button type="button"
                                        class="btn btn-sm btn-outline-light dropdown-toggle os-actions-toggle"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false">
                                        Ações
                                    </button>

                                    <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                                        <a href="{{ route('orders.documents.files.show', ['order' => $orderId, 'document' => $documentId, 'format' => 'a4']) }}"
                                           target="_blank" rel="noreferrer"
                                           class="dropdown-item {{ ($files['a4']['available'] ?? false) ? '' : 'd-none' }}"
                                           data-doc-view-a4>
                                            <i class="bi bi-file-earmark-pdf me-2"></i>Visualizar A4
                                        </a>
                                        <a href="{{ route('orders.documents.files.show', ['order' => $orderId, 'document' => $documentId, 'format' => '80mm']) }}"
                                           target="_blank" rel="noreferrer"
                                           class="dropdown-item {{ ($files['80mm']['available'] ?? false) ? '' : 'd-none' }}"
                                           data-doc-view-80mm>
                                            <i class="bi bi-receipt me-2"></i>Visualizar 80mm
                                        </a>

                                        <div class="dropdown-divider"></div>

                                        <button type="button" class="dropdown-item" data-doc-row-zip="{{ $documentId }}">
                                            <i class="bi bi-file-earmark-zip me-2"></i>Baixar ZIP
                                        </button>
                                        <button type="button" class="dropdown-item" data-doc-row-print="{{ $documentId }}">
                                            <i class="bi bi-printer me-2"></i>Imprimir
                                        </button>
                                        <button type="button" class="dropdown-item" data-doc-row-share="{{ $documentId }}">
                                            <i class="bi bi-link-45deg me-2"></i>Gerar link
                                        </button>
                                        <button type="button" class="dropdown-item" data-doc-row-send="{{ $documentId }}">
                                            <i class="bi bi-send me-2"></i>Enviar
                                        </button>

                                        <div class="dropdown-divider"></div>

                                        <button type="button"
                                                class="dropdown-item"
                                                data-doc-archive-toggle="{{ $documentId }}"
                                                data-archive="{{ $isArchived ? '0' : '1' }}">
                                            <i class="bi {{ $isArchived ? 'bi-box-arrow-up' : 'bi-archive' }} me-2"></i><span data-doc-archive-label>{{ $isArchived ? 'Reativar' : 'Arquivar' }}</span>
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@else
    @include('layouts.partials.empty-state', [
        'icon' => 'bi-file-earmark-x',
        'title' => 'Nenhum tipo documental disponível',
        'message' => 'O catálogo de documentos desta OS ainda não foi carregado.',
    ])
@endif
