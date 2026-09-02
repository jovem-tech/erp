@extends('layouts.app')

@section('content')
    @php
        $moeda = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
        $data = static fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
        $podeEmitir = \App\Support\DesktopSession::can('os', 'editar');

        $rotulos = [
            'emitido' => ['Emitida', 'bg-success'],
            'cancelado' => ['Cancelada', 'bg-danger'],
            'rejeitado' => ['Rejeitada', 'bg-warning text-dark'],
            'rascunho' => ['Rascunho', 'bg-secondary'],
        ];

        $filtrosAtivos = trim((string) ($filtros['busca'] ?? '')) !== ''
            || trim((string) ($filtros['de'] ?? '')) !== ''
            || trim((string) ($filtros['ate'] ?? '')) !== ''
            || (string) ($filtros['status'] ?? '') !== 'emitido,cancelado';
        $quantosFiltros = count(array_filter([
            trim((string) ($filtros['busca'] ?? '')) !== '',
            trim((string) ($filtros['de'] ?? '')) !== '',
            trim((string) ($filtros['ate'] ?? '')) !== '',
            (string) ($filtros['status'] ?? '') !== 'emitido,cancelado',
        ]));
    @endphp

    <x-list-filters
        form-id="notasFilterPanel"
        search-name="busca"
        :search-value="$filtros['busca'] ?? ''"
        search-placeholder="Número da nota, chave, OS, cliente ou CPF/CNPJ"
        :results-count="$pagination['total'] ?? 0"
        results-label="notas"
        :clear-url="route('fiscal.emitidas')"
        :has-active-filters="$filtrosAtivos"
        :active-filter-count="$quantosFiltros"
    >
        <x-slot:actions>
            <a href="{{ route('fiscal.pendentes') }}" class="btn btn-outline-primary">
                <i class="bi bi-receipt me-2"></i>Notas pendentes
            </a>
            <x-list-actions label="Mais ações" size="" :favoritable="true">
                <li>
                    <a href="{{ route('fiscal.prontidao') }}" class="dropdown-item">
                        <i class="bi bi-clipboard-check me-2"></i>Prontidão fiscal
                    </a>
                </li>
            </x-list-actions>
        </x-slot:actions>

        <div>
            <label for="status">Situação</label>
            <select id="status" name="status" class="form-select">
                <option value="emitido,cancelado" @selected((string) ($filtros['status'] ?? '') === 'emitido,cancelado')>Emitidas e canceladas</option>
                <option value="emitido" @selected((string) ($filtros['status'] ?? '') === 'emitido')>Só emitidas</option>
                <option value="cancelado" @selected((string) ($filtros['status'] ?? '') === 'cancelado')>Só canceladas</option>
                <option value="rejeitado" @selected((string) ($filtros['status'] ?? '') === 'rejeitado')>Rejeitadas</option>
                <option value="rascunho" @selected((string) ($filtros['status'] ?? '') === 'rascunho')>Rascunhos</option>
                <option value="" @selected((string) ($filtros['status'] ?? '') === '')>Todas</option>
            </select>
        </div>

        <div>
            <label for="de">Emitidas de</label>
            <input type="date" id="de" name="de" class="form-control" value="{{ $filtros['de'] ?? '' }}">
        </div>

        <div>
            <label for="ate">até</label>
            <input type="date" id="ate" name="ate" class="form-control" value="{{ $filtros['ate'] ?? '' }}">
        </div>

        <div>
            <label for="per_page">Itens por página</label>
            <select id="per_page" name="per_page" class="form-select">
                @foreach ([20, 50, 100] as $tamanho)
                    <option value="{{ $tamanho }}" @selected((int) ($filtros['per_page'] ?? 20) === $tamanho)>{{ $tamanho }}</option>
                @endforeach
            </select>
        </div>
    </x-list-filters>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Notas emitidas <x-favorite-toggle /></h2>
                <p class="surface-subtitle mb-0">
                    NFS-e registradas no sistema, com o XML e o DANFSe guardados junto da OS.
                    A guarda dos arquivos é obrigatória por 5 anos.
                </p>
            </div>

            <div class="d-flex flex-column align-items-end gap-1">
                <span class="desktop-chip">
                    <i class="bi bi-receipt-cutoff"></i>
                    {{ number_format((int) ($totais['emitidas'] ?? 0), 0, ',', '.') }} emitidas
                </span>
                {{-- Só o que está emitido entra na soma: cancelada não valeu
                     nada e rascunho não chegou a existir. --}}
                <span class="surface-subtitle small mb-0">
                    {{ $moeda($totais['valor'] ?? 0) }} em notas emitidas no filtro
                </span>
            </div>
        </div>

        @if ($documentos === [])
            <div class="p-4">
                <div class="alert alert-info mb-0">
                    Nenhuma nota encontrada com esse filtro.
                    @if ($filtrosAtivos)
                        <a href="{{ route('fiscal.emitidas') }}">Limpar os filtros</a> mostra todas.
                    @else
                        As notas aparecem aqui depois de registradas em
                        <a href="{{ route('fiscal.pendentes') }}">Notas pendentes</a>.
                    @endif
                </div>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-stack align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nota</th>
                            <th>OS</th>
                            <th>Cliente</th>
                            <th>Emitida em</th>
                            <th class="text-end">Valor</th>
                            <th>Situação</th>
                            <th>Arquivos</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documentos as $documento)
                            @php
                                $status = (string) ($documento['status'] ?? '');
                                [$rotulo, $cor] = $rotulos[$status] ?? [$status, 'bg-secondary'];
                                $numero = (string) ($documento['numero'] ?? '');
                                $chave = (string) ($documento['chave'] ?? '');
                                $osId = $documento['os_id'] ?? null;
                                $valor = $documento['valor_xml'] ?? $documento['valor_total'] ?? 0;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $numero !== '' ? $numero : '—' }}</strong>
                                    @if (($documento['serie'] ?? '') !== '')
                                        <span class="surface-subtitle small d-block">série {{ $documento['serie'] }}</span>
                                    @endif
                                    @if ($chave !== '')
                                        {{-- Chave inteira no title: e' o que o
                                             contador pede e nao cabe na coluna. --}}
                                        <span class="surface-subtitle small d-block text-truncate" style="max-width: 14rem;" title="{{ $chave }}">
                                            {{ $chave }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($osId && ($documento['numero_os'] ?? '') !== '')
                                        <a href="{{ route('orders.show', $osId) }}">{{ $documento['numero_os'] }}</a>
                                    @elseif ($osId)
                                        <a href="{{ route('orders.show', $osId) }}">OS #{{ $osId }}</a>
                                    @else
                                        <span class="surface-subtitle">—</span>
                                    @endif
                                </td>
                                <td>
                                    {{ ($documento['tomador_nome'] ?? '') !== '' ? $documento['tomador_nome'] : '—' }}
                                    @if (($documento['tomador_documento'] ?? '') !== '')
                                        {{-- Sem mascara, igual a tela da nota: o
                                             formatador mora no backend, e um
                                             terceiro espelho da regra de CPF/CNPJ
                                             aqui so' daria divergencia. --}}
                                        <span class="surface-subtitle small d-block">{{ $documento['tomador_documento'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $data($documento['emitido_em'] ?? null) }}</td>
                                <td class="text-end">
                                    {{ $moeda($valor) }}
                                    @if ($documento['valor_diverge'] ?? false)
                                        {{-- O XML declarou um valor diferente do
                                             que a OS calculou. Mostrar so' o
                                             numero esconderia a divergencia. --}}
                                        <span class="badge bg-warning text-dark d-block mt-1"
                                              title="A OS calculou {{ $moeda($documento['valor_total'] ?? 0) }}, mas o XML declarou {{ $moeda($documento['valor_xml'] ?? 0) }}.">
                                            valor difere da OS
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $cor }}">{{ $rotulo }}</span>
                                    @if ($status === 'cancelado' && ($documento['motivo_cancelamento'] ?? '') !== '')
                                        <span class="surface-subtitle small d-block">{{ $documento['motivo_cancelamento'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($documento['tem_xml'] ?? false)
                                        <a href="{{ route('fiscal.documentos.arquivo.download', [$documento['id'], 'xml']) }}"
                                           class="badge bg-primary text-decoration-none" title="Baixar o XML guardado">XML</a>
                                    @else
                                        <span class="badge bg-secondary" title="Sem o XML não há guarda do documento">sem XML</span>
                                    @endif

                                    @if ($documento['tem_pdf'] ?? false)
                                        <a href="{{ route('fiscal.documentos.arquivo.download', [$documento['id'], 'pdf']) }}"
                                           class="badge bg-primary text-decoration-none" title="DANFSe baixado do portal">PDF</a>
                                    @elseif ($documento['tem_xml'] ?? false)
                                        <a href="{{ route('fiscal.documentos.danfse', $documento['id']) }}"
                                           class="badge bg-info text-dark text-decoration-none"
                                           title="DANFSe desenhado a partir do XML">DANFSe</a>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($osId && $podeEmitir)
                                        <a href="{{ route('fiscal.nota', $osId) }}" class="btn btn-outline-primary btn-sm">
                                            Abrir
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => $filtros])
        @endif
    </section>
@endsection
