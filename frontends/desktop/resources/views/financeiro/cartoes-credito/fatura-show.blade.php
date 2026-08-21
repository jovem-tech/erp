@extends('layouts.app')

@php
    use App\Support\DesktopSession;

    $cartao = is_array($cartao ?? null) ? $cartao : [];
    $fatura = is_array($fatura ?? null) ? $fatura : [];
    $despesas = is_array($despesas ?? null) ? $despesas : [];
    $cartaoId = (int) ($cartao['id'] ?? 0);
    $dataVencimento = (string) ($fatura['data_vencimento'] ?? '');
    $aberta = ($fatura['status'] ?? '') === 'aberta';
    // Mesma regra de faturas.blade.php: só a fatura corrente ou uma vencida
    // pode ser paga por aqui — as futuras ainda estão recebendo compras e o
    // total muda até o fechamento (calculado em
    // FinanceiroCartaoCreditoService::invoiceDetail()).
    $podePagar = (bool) ($fatura['pode_pagar'] ?? false);
    // Só existe baixa para cancelar quando alguma despesa da fatura já foi
    // liquidada (ver FinanceiroCartaoCreditoService::cancelInvoicePayment()).
    $podeCancelarBaixa = (bool) ($fatura['pode_cancelar_baixa'] ?? false);
    // Data em que a fatura ficou quitada (null enquanto aberta).
    $pagaEm = $fatura['data_pagamento'] ?? null;
    $canEdit = DesktopSession::can('contas_saldos', 'editar');
    $canViewFinanceiro = DesktopSession::can('financeiro', 'visualizar');
    $canViewOs = DesktopSession::can('os', 'visualizar');
    // Editar a despesa é ação do módulo financeiro, não de contas_saldos: esta
    // tela é só o caminho de acesso, o registro editado continua sendo o título.
    $canEditDespesa = DesktopSession::can('financeiro', 'editar');
    $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    $date = static fn ($value): string => $value ? date('d/m/Y', strtotime((string) $value)) : '—';
    $statusLabels = [
        'pendente' => ['label' => 'Pendente', 'class' => 'text-bg-warning'],
        'parcial' => ['label' => 'Parcial', 'class' => 'text-bg-info'],
        'pago' => ['label' => 'Pago', 'class' => 'text-bg-success'],
        'cancelado' => ['label' => 'Cancelado', 'class' => 'text-bg-secondary'],
    ];
    $emAberto = array_sum(array_map(
        static fn (array $despesa): float => in_array($despesa['status'] ?? '', ['pendente', 'parcial'], true)
            ? (float) ($despesa['valor'] ?? 0)
            : 0.0,
        $despesas
    ));
@endphp

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro · {{ $cartao['nome'] ?? 'Cartão' }}</p>
            <h2 class="surface-title fs-3 mb-2">Fatura de {{ $date($dataVencimento) }}</h2>
            <p class="surface-subtitle mb-0">
                {{ (int) ($fatura['quantidade_despesas'] ?? 0) }} despesa(s) neste ciclo
                · fecha dia {{ (int) ($cartao['dia_fechamento'] ?? 0) }}, vence dia {{ (int) ($cartao['dia_vencimento'] ?? 0) }}
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.cartoes-credito.faturas', ['cartaoCredito' => $cartaoId]) }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>Todas as faturas
            </a>
            @if ($canEdit && $podePagar)
                <button class="btn btn-primary" data-bs-toggle="modal"
                        data-bs-target="#pagarFaturaModal{{ preg_replace('/\D/', '', (string) $dataVencimento) }}">
                    <i class="bi bi-check2-circle me-2"></i>Marcar fatura como paga
                </button>
            @endif

            @if ($canEdit && $podeCancelarBaixa)
                <div class="dropdown os-actions-dropdown align-self-start">
                    <button type="button"
                            class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        Mais ações
                    </button>

                    <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                        <button type="button"
                                class="dropdown-item text-warning"
                                data-bs-toggle="modal"
                                data-bs-target="#cancelarBaixaFaturaModal">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>Cancelar baixa da fatura
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div class="desktop-grid desktop-grid-four mb-4">
        <article class="summary-card">
            <p class="summary-card-eyebrow">Total da fatura</p>
            <h3 class="summary-card-value">{{ $money($fatura['total'] ?? 0) }}</h3>
            <p class="summary-card-meta">Fixas + variáveis do ciclo</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Despesas fixas</p>
            <h3 class="summary-card-value">{{ $money($fatura['total_fixas'] ?? 0) }}</h3>
            <p class="summary-card-meta">Assinaturas, planos, mensalidades</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Despesas variáveis</p>
            <h3 class="summary-card-value">{{ $money($fatura['total_variaveis'] ?? 0) }}</h3>
            <p class="summary-card-meta">Peças, materiais, imprevistos</p>
        </article>
        <article class="summary-card">
            <p class="summary-card-eyebrow">Situação</p>
            <h3 class="summary-card-value">{{ $aberta ? $money($emAberto) : 'Paga' }}</h3>
            <p class="summary-card-meta">
                @if ($aberta)
                    Ainda em aberto
                @elseif ($pagaEm !== null)
                    Paga em {{ $date($pagaEm) }}
                @else
                    Todas as despesas liquidadas
                @endif
            </p>
        </article>
    </div>

    @if ($despesas === [])
        @include('layouts.partials.empty-state', [
            'icon' => 'bi-receipt',
            'title' => 'Nenhuma despesa nesta fatura',
            'message' => 'As despesas aparecem aqui conforme forem lançadas com este cartão dentro do ciclo.',
        ])
    @else
        <section class="surface-table">
            <div class="surface-table-header">
                <div>
                    <h2 class="surface-title">Despesas da fatura</h2>
                    <p class="surface-subtitle mb-0">Cada linha é um lançamento próprio no financeiro — o que a fatura faz é agrupá-los.</p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                        <tr>
                            <th>Compra</th>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Tipo</th>
                            <th>Valor</th>
                            <th>Status</th>
                            @if ($canViewFinanceiro)<th class="text-end">Ações</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($despesas as $despesa)
                            @php $badge = $statusLabels[$despesa['status'] ?? ''] ?? ['label' => ucfirst((string) ($despesa['status'] ?? '')), 'class' => 'text-bg-secondary']; @endphp
                            <tr>
                                <td data-label="Compra">{{ $date($despesa['data_compra'] ?? null) }}</td>
                                <td data-label="Descrição">
                                    <strong>{{ $despesa['descricao'] ?? '' }}</strong>
                                    @if (!empty($despesa['parcelas_total']) && (int) $despesa['parcelas_total'] > 1)
                                        <span class="badge rounded-pill text-bg-info ms-1">
                                            {{ (int) $despesa['parcela_numero'] }}/{{ (int) $despesa['parcelas_total'] }}
                                        </span>
                                    @endif
                                    @if (!empty($despesa['fornecedor']))
                                        <br><small class="text-body-secondary">{{ $despesa['fornecedor'] }}</small>
                                    @endif
                                    @php $despesaOs = is_array($despesa['os'] ?? null) ? $despesa['os'] : null; @endphp
                                    @if ($despesaOs !== null)
                                        @php
                                            $despesaOsId = (int) ($despesaOs['id'] ?? 0);
                                            $despesaOsLabel = trim((string) ($despesaOs['numero_os'] ?? '')) !== ''
                                                ? $despesaOs['numero_os']
                                                : '#' . $despesaOsId;
                                        @endphp
                                        <br>
                                        @if ($canViewOs && $despesaOsId > 0)
                                            <a class="small" href="{{ route('orders.show', ['order' => $despesaOsId]) }}">
                                                <i class="bi bi-clipboard-check me-1"></i>{{ $despesaOsLabel }}
                                            </a>
                                        @else
                                            <small class="text-body-secondary">
                                                <i class="bi bi-clipboard-check me-1"></i>{{ $despesaOsLabel }}
                                            </small>
                                        @endif
                                    @endif
                                </td>
                                <td data-label="Categoria">{{ $despesa['categoria'] ?? '' }}</td>
                                <td data-label="Tipo">
                                    <span class="badge rounded-pill text-bg-secondary">
                                        {{ (bool) ($despesa['dre_fixo_mensal'] ?? false) ? 'Fixa' : 'Variável' }}
                                    </span>
                                </td>
                                <td data-label="Valor"><strong>{{ $money($despesa['valor'] ?? 0) }}</strong></td>
                                <td data-label="Status"><span class="badge rounded-pill {{ $badge['class'] }}">{{ $badge['label'] }}</span></td>
                                @if ($canViewFinanceiro)
                                    @php $despesaId = (int) ($despesa['id'] ?? 0); @endphp
                                    <td data-label="Ações" class="text-end">
                                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                                            <a class="btn btn-sm btn-outline-light" href="{{ route('financeiro.show', ['financeiro' => $despesaId]) }}">
                                                <i class="bi bi-eye me-1"></i>Detalhe
                                            </a>
                                            {{-- Despesa de cartão só é editada por aqui (a
                                                 listagem de Lançamentos redireciona para cá):
                                                 é nesta tela que dá para ver o efeito da
                                                 alteração no total da fatura. --}}
                                            @if ($canEditDespesa)
                                                <a class="btn btn-sm btn-outline-secondary"
                                                   href="{{ route('financeiro.edit', [
                                                       'financeiro' => $despesaId,
                                                       'voltar_cartao_id' => $cartaoId,
                                                       'voltar_fatura_vencimento' => $dataVencimento,
                                                   ]) }}">
                                                    <i class="bi bi-pencil me-1"></i>Editar
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection

@if ($canEdit && $podePagar)
    @push('modals')
        @include('financeiro.cartoes-credito._pagar_fatura_modal', [
            'cartaoId' => $cartaoId,
            'cartao' => $cartao,
            'dataVencimento' => $dataVencimento,
            'valorEmAberto' => $emAberto,
            'accountDataset' => $accountDataset ?? [],
        ])
    @endpush
@endif

@if ($canEdit && $podeCancelarBaixa)
    @push('modals')
        @include('financeiro.cartoes-credito._cancelar_baixa_admin_modal', [
            'cartaoId' => $cartaoId,
            'dataVencimento' => $dataVencimento,
        ])
    @endpush
@endif
