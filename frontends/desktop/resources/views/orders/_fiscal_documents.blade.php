<div class="os-panel-block mt-0">
    <h4 class="os-panel-title">
        Notas fiscais (NFS-e)
        <span class="os-count">{{ count($fiscalDocuments) }}</span>
    </h4>

    <div class="table-responsive">
        <table class="table table-stack align-middle mb-0">
            <thead>
            <tr>
                <th>Nota</th>
                <th>Emitida em</th>
                <th>Valor</th>
                <th>Situação</th>
                <th>Arquivos</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($fiscalDocuments as $fiscalDocument)
                @php
                    $fiscalStatus = (string) ($fiscalDocument['status'] ?? '');
                    [$fiscalStatusLabel, $fiscalStatusClass] = match ($fiscalStatus) {
                        'emitido' => ['Emitida', 'bg-success'],
                        'cancelado' => ['Cancelada', 'bg-danger'],
                        default => [ucfirst($fiscalStatus), 'bg-secondary'],
                    };
                    $fiscalNumber = trim((string) ($fiscalDocument['numero'] ?? ''));
                    $fiscalSeries = trim((string) ($fiscalDocument['serie'] ?? ''));
                    $fiscalKey = trim((string) ($fiscalDocument['chave'] ?? ''));
                    $fiscalValue = $fiscalDocument['valor_xml'] ?? $fiscalDocument['valor_total'] ?? 0;
                    $fiscalIssuedAt = '—';

                    if (($fiscalDocument['emitido_em'] ?? '') !== '') {
                        try {
                            $fiscalIssuedAt = \Illuminate\Support\Carbon::parse($fiscalDocument['emitido_em'])->format('d/m/Y H:i');
                        } catch (\Throwable) {
                            $fiscalIssuedAt = '—';
                        }
                    }
                @endphp
                <tr>
                    <td data-label="Nota">
                        <strong>NFS-e nº {{ $fiscalNumber !== '' ? $fiscalNumber : '—' }}</strong>
                        @if ($fiscalSeries !== '')
                            <span class="surface-subtitle small d-block">série {{ $fiscalSeries }}</span>
                        @endif
                        @if ($fiscalKey !== '')
                            <span class="surface-subtitle small d-block text-truncate"
                                style="max-width: 20rem;"
                                title="{{ $fiscalKey }}">
                                Chave: {{ $fiscalKey }}
                            </span>
                        @endif
                    </td>
                    <td data-label="Emitida em">{{ $fiscalIssuedAt }}</td>
                    <td data-label="Valor">
                        <strong>R$ {{ number_format((float) $fiscalValue, 2, ',', '.') }}</strong>
                        @if ($fiscalDocument['valor_diverge'] ?? false)
                            <span class="badge bg-warning text-dark d-block mt-1">valor difere da OS</span>
                        @endif
                    </td>
                    <td data-label="Situação">
                        <span class="badge {{ $fiscalStatusClass }}">{{ $fiscalStatusLabel }}</span>
                        @if ($fiscalStatus === 'cancelado' && ($fiscalDocument['motivo_cancelamento'] ?? '') !== '')
                            <span class="surface-subtitle small d-block mt-1">{{ $fiscalDocument['motivo_cancelamento'] }}</span>
                        @endif
                    </td>
                    <td data-label="Arquivos">
                        @if ($fiscalDocument['tem_xml'] ?? false)
                            <a href="{{ route('fiscal.documentos.arquivo.download', [$fiscalDocument['id'], 'xml']) }}"
                                class="badge bg-primary text-decoration-none"
                                title="Baixar o XML guardado">XML</a>
                        @endif

                        @if ($fiscalDocument['tem_pdf'] ?? false)
                            <a href="{{ route('fiscal.documentos.arquivo.download', [$fiscalDocument['id'], 'pdf']) }}"
                                class="badge bg-primary text-decoration-none"
                                target="_blank"
                                rel="noopener"
                                title="Abrir o DANFSe guardado">PDF</a>
                        @elseif ($fiscalDocument['tem_xml'] ?? false)
                            <a href="{{ route('fiscal.documentos.danfse', $fiscalDocument['id']) }}"
                                class="badge bg-info text-dark text-decoration-none"
                                target="_blank"
                                rel="noopener"
                                title="Abrir o DANFSe gerado do XML">DANFSe</a>
                        @endif
                    </td>
                    <td data-label="" class="text-end">
                        @if ($canManageFiscal)
                            <a href="{{ route('fiscal.nota', $order['id']) }}" class="btn btn-soft btn-sm">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Abrir nota
                            </a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
