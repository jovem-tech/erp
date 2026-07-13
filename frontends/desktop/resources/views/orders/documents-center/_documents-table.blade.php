@if ($documents !== [])
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
            <tr>
                <th style="width: 48px;"></th>
                <th>Documento</th>
                <th>Versão</th>
                <th>Formatos</th>
                <th>Gerado em</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($documents as $document)
                @php
                    $files = is_array($document['files'] ?? null) ? $document['files'] : [];
                    $isArchived = ($document['archived_at'] ?? null) !== null;
                    $documentId = (int) ($document['id'] ?? 0);
                @endphp
                <tr>
                    <td>
                        <input type="checkbox"
                               class="form-check-input"
                               value="{{ $documentId }}"
                               data-document-template-code="{{ e((string) ($document['template_code'] ?? '')) }}"
                               data-document-suggested-message="{{ e((string) ($document['suggested_message'] ?? '')) }}"
                               data-document-label="{{ e((string) ($document['label'] ?? 'Documento')) }}"
                               data-document-row-checkbox>
                    </td>
                    <td>
                        <strong>{{ $document['label'] ?? 'Documento' }}</strong>
                        <div class="text-secondary small">{{ $document['type'] ?? '' }}</div>
                    </td>
                    <td>v{{ $document['version'] ?? 1 }}</td>
                    <td>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach (['a4' => 'A4', '80mm' => '80mm'] as $formatCode => $formatLabel)
                                @php
                                    $formatData = is_array($files[$formatCode] ?? null) ? $files[$formatCode] : [];
                                @endphp
                                @if (($formatData['available'] ?? false) && ($formatData['url'] ?? null) !== null)
                                    <a href="{{ route('orders.documents.files.show', ['order' => $orderId, 'document' => $documentId, 'format' => $formatCode]) }}"
                                       target="_blank"
                                       rel="noreferrer"
                                       class="btn btn-sm btn-outline-light">
                                        {{ $formatLabel }}
                                    </a>
                                @else
                                    <span class="desktop-chip text-secondary">{{ $formatLabel }} indisponível</span>
                                @endif
                            @endforeach
                        </div>
                    </td>
                    <td>
                        <div>{{ isset($document['created_at']) ? \Illuminate\Support\Carbon::parse($document['created_at'])->format('d/m/Y H:i') : '—' }}</div>
                        @if (($document['generated_by']['name'] ?? '') !== '')
                            <div class="text-secondary small">{{ $document['generated_by']['name'] }}</div>
                        @endif
                    </td>
                    <td>
                        @if ($isArchived)
                            <span class="desktop-chip">Arquivado</span>
                        @else
                            <span class="desktop-chip desktop-chip-success">Ativo</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <button type="button"
                                class="btn btn-sm btn-outline-light"
                                data-doc-archive-toggle="{{ $documentId }}"
                                data-archive="{{ $isArchived ? '0' : '1' }}">
                            {{ $isArchived ? 'Reativar' : 'Arquivar' }}
                        </button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@else
    @include('layouts.partials.empty-state', [
        'icon' => 'bi-file-earmark-x',
        'title' => 'Nenhum documento no acervo',
        'message' => 'Gere a primeira versão documental desta OS para começar a central do cliente.',
    ])
@endif
