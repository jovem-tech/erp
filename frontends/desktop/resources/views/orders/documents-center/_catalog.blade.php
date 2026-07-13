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
                <th class="text-end">Ação</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($catalog as $type)
                @php
                    $latest = is_array($type['latest_document'] ?? null) ? $type['latest_document'] : null;
                    $canGenerate = (bool) ($type['can_generate'] ?? false);
                    $typeCode = (string) ($type['type'] ?? '');
                    $triggers = is_array($type['automatic_triggers'] ?? null) ? $type['automatic_triggers'] : [];
                    $origem = $triggers === []
                        ? 'Geração manual'
                        : 'Manual + automático (' . implode(', ', $triggers) . ')';
                @endphp
                <tr class="doc-type-card" data-doc-type-card="{{ $typeCode }}">
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
                        @if ($latest)
                            v{{ $latest['version'] ?? 1 }}
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
                    </td>
                    <td class="text-end">
                        @if ($canGenerate)
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-doc-generate-type="{{ $typeCode }}">
                                <i class="bi bi-file-earmark-plus me-2"></i>Gerar
                            </button>
                        @endif
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
