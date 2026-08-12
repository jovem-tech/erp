@extends('layouts.app')

@section('content')
    @php
        $sessao = is_array($sessao) ? $sessao : [];
        $sessaoId = (int) ($sessao['id'] ?? 0);
        $fechada = (string) ($sessao['status'] ?? '') === 'fechada';
        $diferenca = $sessao['diferenca'] ?? null;
        $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    @endphp

    <section class="surface-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="surface-title mb-1">Caixa #{{ $sessaoId }}</h1>
                <p class="surface-subtitle mb-2">
                    {{ $sessao['operador_nome'] ?? '—' }}
                    @if (! empty($sessao['aberto_em']))
                        · Aberto em {{ \Illuminate\Support\Carbon::parse($sessao['aberto_em'])->format('d/m/Y H:i') }}
                    @endif
                    @if (! empty($sessao['fechado_em']))
                        · Fechado em {{ \Illuminate\Support\Carbon::parse($sessao['fechado_em'])->format('d/m/Y H:i') }}
                    @endif
                </p>

                @include('layouts.partials.status-pill', [
                    'label' => $sessao['status_label'] ?? '-',
                    'color' => $fechada ? '#6b7280' : '#16a34a',
                    'small' => true,
                ])
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('caixa.report', $sessaoId) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="bi bi-printer me-2"></i>Relatório 80mm
                </a>
                <a href="{{ route('caixa.report', ['sessao' => $sessaoId, 'formato' => 'a4']) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-pdf me-2"></i>A4
                </a>
                @if ($fechada && \App\Support\DesktopSession::can('caixa', 'excluir'))
                    <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#caixaReabrirModal">
                        <i class="bi bi-unlock me-2"></i>Reabrir
                    </button>
                @endif
                <a href="{{ route('caixa.historico') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </a>
            </div>
        </div>
    </section>

    @if ($fechada)
        <section class="surface-card mt-3">
            <h2 class="surface-title fs-6 mb-3">Conferência</h2>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <div class="text-secondary small">Esperado em caixa</div>
                    <div class="fs-4">{{ $money($sessao['valor_esperado'] ?? 0) }}</div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="text-secondary small">Contado na gaveta</div>
                    <div class="fs-4">{{ $money($sessao['valor_informado'] ?? 0) }}</div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="text-secondary small">Diferença</div>
                    <div class="fs-3 fw-semibold {{ (float) $diferenca < 0 ? 'text-danger' : ((float) $diferenca > 0 ? 'text-warning' : 'text-success') }}">
                        {{ $money($diferenca ?? 0) }}
                    </div>
                    <small class="text-secondary">
                        @if ((float) $diferenca < 0)
                            Faltou dinheiro na gaveta.
                        @elseif ((float) $diferenca > 0)
                            Sobrou dinheiro na gaveta.
                        @else
                            Conferência sem diferença.
                        @endif
                    </small>
                </div>
            </div>

            @if (! empty($sessao['observacoes_fechamento']))
                <p class="mt-3 mb-0"><strong>Observações:</strong> {{ $sessao['observacoes_fechamento'] }}</p>
            @endif
        </section>
    @endif

    <section class="surface-card mt-3">
        <h2 class="surface-title fs-6 mb-3">Movimento do turno</h2>
        <dl class="row mb-0">
            <dt class="col-7 col-md-9 fw-normal text-secondary">Abertura (troco)</dt>
            <dd class="col-5 col-md-3 text-end">{{ $money($sessao['valor_abertura'] ?? 0) }}</dd>

            <dt class="col-7 col-md-9 fw-normal text-secondary">
                Vendas em dinheiro ({{ (int) ($sessao['quantidade_vendas'] ?? 0) }})
            </dt>
            <dd class="col-5 col-md-3 text-end">{{ $money($sessao['total_vendas_dinheiro'] ?? 0) }}</dd>

            <dt class="col-7 col-md-9 fw-normal text-secondary">Suprimentos</dt>
            <dd class="col-5 col-md-3 text-end">{{ $money($sessao['total_suprimentos'] ?? 0) }}</dd>

            <dt class="col-7 col-md-9 fw-normal text-secondary">Sangrias</dt>
            <dd class="col-5 col-md-3 text-end">− {{ $money($sessao['total_sangrias'] ?? 0) }}</dd>
        </dl>
    </section>

    @if (! empty($sessao['movimentos']))
        <section class="surface-table mt-3">
            <div class="surface-table-header">
                <h2 class="surface-title">Sangrias e suprimentos</h2>
            </div>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Quando</th>
                        <th>Tipo</th>
                        <th>Motivo</th>
                        <th>Responsável</th>
                        <th class="text-end">Valor</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($sessao['movimentos'] as $movimento)
                        <tr>
                            <td data-label="Quando">
                                {{ ! empty($movimento['created_at']) ? \Illuminate\Support\Carbon::parse($movimento['created_at'])->format('d/m/Y H:i') : '-' }}
                            </td>
                            <td data-label="Tipo">
                                <span class="badge {{ ($movimento['tipo'] ?? '') === 'sangria' ? 'bg-danger' : 'bg-success' }}">
                                    {{ $movimento['tipo_label'] ?? '' }}
                                </span>
                            </td>
                            <td data-label="Motivo">{{ $movimento['motivo'] ?? '' }}</td>
                            <td data-label="Responsável">{{ $movimento['responsavel_nome'] ?? '-' }}</td>
                            <td data-label="Valor" class="text-end fw-semibold">{{ $money($movimento['valor'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection

@push('modals')
    @if ($fechada && \App\Support\DesktopSession::can('caixa', 'excluir'))
        <div class="modal fade" id="caixaReabrirModal" tabindex="-1" aria-labelledby="caixaReabrirModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="post" action="{{ route('caixa.reopen', $sessaoId) }}" class="modal-content">
                    @csrf

                    <div class="modal-header">
                        <h5 class="modal-title" id="caixaReabrirModalLabel">Reabrir caixa #{{ $sessaoId }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="alert alert-warning">
                            A conferência já registrada será descartada. Um fechamento
                            reaberto não vale mais como conferência do turno.
                        </div>

                        <div class="mb-2">
                            <label for="caixaReabrirEmail" class="form-label">E-mail do administrador</label>
                            <input type="email" id="caixaReabrirEmail" name="admin_email" class="form-control" autocomplete="off" required>
                        </div>

                        <div>
                            <label for="caixaReabrirSenha" class="form-label">Senha</label>
                            <input type="password" id="caixaReabrirSenha" name="admin_password" class="form-control" autocomplete="new-password" required>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-warning">Reabrir caixa</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endpush
