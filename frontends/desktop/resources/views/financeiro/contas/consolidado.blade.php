@extends('layouts.app')

@php
    use App\Support\DesktopSession;

    $report = is_array($report ?? null) ? $report : [];
    $summary = is_array($report['resumo'] ?? null) ? $report['resumo'] : [];
    $accounts = is_array($report['contas'] ?? null) ? $report['contas'] : [];
    $unclassified = is_array($report['sem_conta'] ?? null) ? $report['sem_conta'] : [];
    $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    $date = static fn ($value): string => $value ? date('d/m/Y', strtotime((string) $value)) : '—';
    $transferDifference = (float) ($summary['conferencia_transferencias'] ?? 0);
    $canViewFinanceiro = DesktopSession::can('financeiro', 'visualizar');
    $typeLabels = [
        'caixa' => 'Caixa físico',
        'banco' => 'Banco',
        'adquirente' => 'Adquirente / maquininha',
        'reserva' => 'Reserva',
        'carteira_digital' => 'Carteira digital',
        'outra' => 'Outra',
    ];
@endphp

@section('styles')
    <style>
        .consolidated-help { border-left: 4px solid #38bdf8; }
        .consolidated-positive { color: #5ee0a0; }
        .consolidated-negative { color: #fb7185; }
        .consolidated-pending { color: #fbbf24; }
        .consolidated-statement th, .consolidated-statement td { padding-block: .85rem; vertical-align: middle; }
        .consolidated-statement .is-total { border-top: 2px solid rgba(148,163,184,.35); font-weight: 800; }
        .consolidated-statement .is-grand-total { background: rgba(56,189,248,.08); font-size: 1.05rem; font-weight: 800; }
        .consolidated-account-table th, .consolidated-account-table td { white-space: nowrap; vertical-align: middle; }
        .consolidated-account-table td:first-child, .consolidated-account-table th:first-child { position: sticky; left: 0; z-index: 1; background: var(--bs-body-bg); }
        .consolidated-dot { display: inline-block; width: .65rem; height: .65rem; border-radius: 50%; background: var(--account-color, #3868b0); }
        .treasury-metric { background: rgba(255,255,255,.035); border: 1px solid rgba(148,163,184,.13); border-radius: 14px; padding: .9rem; }
        .treasury-metric span { display: block; font-size: .75rem; color: var(--bs-secondary-color); margin-bottom: .25rem; }
    </style>
@endsection

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Tesouraria</p>
            <h2 class="surface-title fs-3 mb-2">Consolidado de Contas e Saldos</h2>
            <p class="surface-subtitle mb-0">Evolução do dinheiro real por conta, sem misturar saldos patrimoniais e transferências internas com faturamento.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-start">
            @if ($canViewFinanceiro)
                <a href="{{ route('financeiro.relatorios.dre', ['mes' => $month]) }}" class="btn btn-outline-light">
                    <i class="bi bi-graph-up-arrow me-2"></i>Ver DRE
                </a>
            @endif
            <a href="{{ route('financeiro.contas.index', ['mes' => $month]) }}" class="btn btn-primary">
                <i class="bi bi-arrow-left me-2"></i>Contas e Saldos
            </a>
        </div>
    </div>

    <section class="surface-card consolidated-help mb-4">
        <div class="d-flex gap-3 align-items-start">
            <i class="bi bi-info-circle text-info fs-4"></i>
            <div>
                <strong>Este relatório não é uma DRE.</strong>
                <p class="surface-subtitle mb-0 mt-1">A DRE mede o resultado do negócio. Este consolidado explica onde o dinheiro está, quanto entrou ou saiu das contas e por que o saldo mudou. Transferências aparecem para conferência, mas se anulam no total geral.</p>
            </div>
        </div>
    </section>

    <form method="GET" action="{{ route('financeiro.contas.consolidado') }}" class="surface-card mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-4 col-xl-3">
                <label for="consolidatedMonth" class="form-label">Mês de referência</label>
                <input id="consolidatedMonth" type="month" name="mes" value="{{ $month }}" class="form-control" required>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary"><i class="bi bi-arrow-clockwise me-2"></i>Atualizar</button>
            </div>
            <div class="col text-md-end">
                <small class="text-body-secondary">Movimentos de {{ $date($report['data_inicio'] ?? null) }} até {{ $date($report['data_fim'] ?? null) }}</small>
            </div>
        </div>
    </form>

    <div class="desktop-grid desktop-grid-four mb-4">
        <article class="summary-card">
            <p class="summary-card-eyebrow">Disponível operacional</p>
            <h3 class="summary-card-value consolidated-positive">{{ $money($summary['disponivel_operacional'] ?? 0) }}</h3>
            <p class="summary-card-meta">Contas marcadas para a operação</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Reservado</p>
            <h3 class="summary-card-value">{{ $money($summary['reservado'] ?? 0) }}</h3>
            <p class="summary-card-meta">Saldo separado da operação</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Cartão a receber</p>
            <h3 class="summary-card-value consolidated-pending">{{ $money($summary['cartao_a_receber'] ?? 0) }}</h3>
            <p class="summary-card-meta">Líquido de taxas na data final</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Posição total</p>
            <h3 class="summary-card-value">{{ $money($summary['posicao_total'] ?? 0) }}</h3>
            <p class="summary-card-meta">Saldo em contas + cartão a receber</p>
        </article>
    </div>

    @if ((int) ($unclassified['quantidade'] ?? 0) > 0)
        <div class="alert alert-warning d-flex gap-3 align-items-start mb-4">
            <i class="bi bi-exclamation-triangle fs-5"></i>
            <div>
                <strong>Consolidado incompleto: {{ (int) $unclassified['quantidade'] }} baixa(s) sem conta no período</strong>
                <div>{{ $money($unclassified['valor'] ?? 0) }} ainda precisa(m) ser classificado(s) em uma conta financeira.</div>
            </div>
        </div>
    @endif

    <div class="row g-4 mb-4">
        <div class="col-xl-7">
            <section class="surface-card h-100">
                <div class="mb-3">
                    <h3 class="surface-title fs-5 mb-1">Movimentação consolidada</h3>
                    <p class="surface-subtitle mb-0">Ponte entre o saldo anterior e a posição financeira final.</p>
                </div>
                <div class="table-responsive">
                    <table class="table consolidated-statement mb-0">
                        <tbody>
                            <tr><th>Saldo anterior ao mês</th><td class="text-end">{{ $money($summary['saldo_anterior'] ?? 0) }}</td></tr>
                            <tr><th><span class="consolidated-positive">(+)</span> Saldos iniciais cadastrados no período</th><td class="text-end">{{ $money($summary['saldos_iniciais_periodo'] ?? 0) }}</td></tr>
                            <tr><th><span class="consolidated-positive">(+)</span> Recebimentos operacionais</th><td class="text-end consolidated-positive">{{ $money($summary['entradas_operacionais'] ?? 0) }}</td></tr>
                            <tr><th><span class="consolidated-negative">(−)</span> Pagamentos operacionais</th><td class="text-end consolidated-negative">{{ $money($summary['saidas_operacionais'] ?? 0) }}</td></tr>
                            <tr><th><span class="consolidated-positive">(+)</span> Ajustes de entrada</th><td class="text-end">{{ $money($summary['ajustes_entrada'] ?? 0) }}</td></tr>
                            <tr><th><span class="consolidated-negative">(−)</span> Ajustes de saída</th><td class="text-end">{{ $money($summary['ajustes_saida'] ?? 0) }}</td></tr>
                            <tr class="is-total"><th>Saldo antes das transferências internas</th><td class="text-end">{{ $money($summary['saldo_antes_transferencias'] ?? 0) }}</td></tr>
                            <tr><th><span class="consolidated-positive">(+)</span> Transferências recebidas</th><td class="text-end">{{ $money($summary['transferencias_entrada'] ?? 0) }}</td></tr>
                            <tr><th><span class="consolidated-negative">(−)</span> Transferências enviadas</th><td class="text-end">{{ $money($summary['transferencias_saida'] ?? 0) }}</td></tr>
                            <tr class="is-grand-total"><th>Saldo final em contas</th><td class="text-end">{{ $money($summary['saldo_final'] ?? 0) }}</td></tr>
                            <tr><th><span class="consolidated-pending">(+)</span> Cartão a receber</th><td class="text-end consolidated-pending">{{ $money($summary['cartao_a_receber'] ?? 0) }}</td></tr>
                            <tr class="is-grand-total"><th>Posição financeira total</th><td class="text-end">{{ $money($summary['posicao_total'] ?? 0) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-xl-5">
            <section class="surface-card h-100">
                <h3 class="surface-title fs-5 mb-1">Conferência do período</h3>
                <p class="surface-subtitle mb-4">Indicadores para validar se o consolidado está íntegro.</p>

                <div class="d-grid gap-3">
                    <div class="treasury-metric">
                        <span>Transferências internas</span>
                        <strong>{{ $money($summary['transferencias_saida'] ?? 0) }} movimentados entre contas</strong>
                        <div class="small text-body-secondary mt-1">Entradas {{ $money($summary['transferencias_entrada'] ?? 0) }} · saídas {{ $money($summary['transferencias_saida'] ?? 0) }}</div>
                    </div>
                    <div class="treasury-metric">
                        <span>Diferença entre os dois lados</span>
                        <strong class="{{ abs($transferDifference) < 0.01 ? 'consolidated-positive' : 'consolidated-negative' }}">{{ $money($transferDifference) }}</strong>
                        <div class="small text-body-secondary mt-1">{{ abs($transferDifference) < 0.01 ? 'Transferências conciliadas.' : 'Há uma divergência que precisa ser investigada.' }}</div>
                    </div>
                    <div class="treasury-metric">
                        <span>Saldo efetivamente creditado</span>
                        <strong>{{ $money($summary['saldo_final'] ?? 0) }}</strong>
                        <div class="small text-body-secondary mt-1">Não inclui cartão que ainda não apareceu no extrato.</div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <section class="surface-card">
        <div class="mb-3">
            <h3 class="surface-title fs-5 mb-1">Fechamento por conta</h3>
            <p class="surface-subtitle mb-0">Detalhamento das mesmas linhas do consolidado para localizar qualquer diferença.</p>
        </div>
        <div class="table-responsive">
            <table class="table consolidated-account-table mb-0">
                <thead>
                    <tr>
                        <th>Conta</th>
                        <th class="text-end">Saldo anterior</th>
                        <th class="text-end">Saldos iniciais</th>
                        <th class="text-end">Entradas op.</th>
                        <th class="text-end">Saídas op.</th>
                        <th class="text-end">Ajustes líquidos</th>
                        <th class="text-end">Transf. recebidas</th>
                        <th class="text-end">Transf. enviadas</th>
                        <th class="text-end">Saldo final</th>
                        <th class="text-end">Cartão a receber</th>
                        <th class="text-end">Posição total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        @php
                            $adjustmentNet = (float) ($account['ajustes_entrada'] ?? 0) - (float) ($account['ajustes_saida'] ?? 0);
                        @endphp
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="consolidated-dot" style="--account-color: {{ $account['cor'] ?? '#3868B0' }}"></span>
                                    <div>
                                        <strong>{{ $account['nome'] ?? 'Conta' }}</strong>
                                        <div class="small text-body-secondary">{{ $typeLabels[$account['tipo'] ?? ''] ?? 'Conta' }}{{ !empty($account['instituicao']) ? ' · ' . $account['instituicao'] : '' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-end">{{ $money($account['saldo_anterior'] ?? 0) }}</td>
                            <td class="text-end">{{ $money($account['saldos_iniciais_periodo'] ?? 0) }}</td>
                            <td class="text-end consolidated-positive">{{ $money($account['entradas_operacionais'] ?? 0) }}</td>
                            <td class="text-end consolidated-negative">{{ $money($account['saidas_operacionais'] ?? 0) }}</td>
                            <td class="text-end {{ $adjustmentNet < 0 ? 'consolidated-negative' : '' }}">{{ $money($adjustmentNet) }}</td>
                            <td class="text-end">{{ $money($account['transferencias_entrada'] ?? 0) }}</td>
                            <td class="text-end">{{ $money($account['transferencias_saida'] ?? 0) }}</td>
                            <td class="text-end"><strong>{{ $money($account['saldo_final'] ?? 0) }}</strong></td>
                            <td class="text-end consolidated-pending">{{ $money($account['cartao_a_receber'] ?? 0) }}</td>
                            <td class="text-end"><strong>{{ $money($account['posicao_total'] ?? 0) }}</strong></td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="text-center text-body-secondary py-5">Nenhuma conta era controlada até a data final deste período.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
