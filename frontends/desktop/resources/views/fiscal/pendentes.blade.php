@extends('layouts.app')

@section('content')
    @php
        $moeda = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
        $data = static fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
        $podeEditarCliente = \App\Support\DesktopSession::can('clientes', 'editar');
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Fiscal</p>
            <h2 class="surface-title fs-3 mb-2">Notas pendentes <x-favorite-toggle /></h2>
            <p class="surface-subtitle mb-0">
                OS encerradas com valor cobrado que ainda não têm nota emitida. A partir de
                1º de janeiro de 2027 a emissão é obrigatória em toda operação.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('fiscal.emitidas') }}" class="btn btn-outline-primary">
                <i class="bi bi-receipt-cutoff me-2"></i>Notas emitidas
            </a>
            <a href="{{ route('fiscal.prontidao') }}" class="btn btn-outline-primary">
                <i class="bi bi-clipboard-check me-2"></i>Prontidão fiscal
            </a>
        </div>
    </div>

    @if ($ordens === [])
        <div class="alert alert-success">Nenhuma OS encerrada sem nota. Nada pendente.</div>
    @else
        <div class="alert alert-warning">
            <strong>{{ count($ordens) }}</strong> OS encerrada(s) com valor cobrado e sem nota emitida.
        </div>

        <div class="surface-card p-0">
            <div class="table-responsive">
                <table class="table table-stack align-middle mb-0">
                    <thead>
                        <tr>
                            <th>OS</th>
                            <th>Cliente</th>
                            <th>CPF/CNPJ</th>
                            <th class="text-end">Valor</th>
                            <th>Encerrada em</th>
                            <th class="text-end">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ordens as $ordem)
                            <tr>
                                <td><strong>{{ $ordem['numero_os'] ?? '' }}</strong></td>
                                <td>{{ $ordem['cliente_nome'] ?? '—' }}</td>
                                <td>
                                    @if (($ordem['cliente_documento'] ?? '') !== '')
                                        {{ $ordem['cliente_documento'] }}
                                    @elseif (($ordem['cliente_id'] ?? null) && $podeEditarCliente)
                                        {{-- Sem documento a NFS-e nao sai. O aviso LEVA ao
                                             cadastro: apontar a pendencia sem oferecer o
                                             caminho de correcao vira reclamacao, nao acao. --}}
                                        <a href="{{ route('clients.edit', $ordem['cliente_id']) }}"
                                           class="badge bg-danger text-decoration-none"
                                           title="Abrir o cadastro para preencher o CPF/CNPJ">
                                            <i class="bi bi-pencil me-1"></i>preencher CPF/CNPJ
                                        </a>
                                    @else
                                        <span class="badge bg-danger">sem documento</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $moeda($ordem['valor_final'] ?? 0) }}</td>
                                <td>{{ $data($ordem['entregue_em'] ?? null) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('fiscal.nota', $ordem['os_id']) }}" class="btn btn-primary btn-sm">
                                        Emitir nota
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
