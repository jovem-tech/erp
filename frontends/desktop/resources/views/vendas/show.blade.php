@extends('layouts.app')

@section('content')
    @php
        $venda = is_array($venda) ? $venda : [];
        $vendaId = (int) ($venda['id'] ?? 0);
        $cancelada = (string) ($venda['status'] ?? '') === 'cancelada';
        $aberto = (float) ($venda['valor_aberto'] ?? 0);
        $hoje = now()->toDateString();
        $doDia = (string) ($venda['data_venda'] ?? '') === $hoje;

        $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    @endphp

    <section class="surface-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="surface-title mb-1">Venda {{ $venda['numero'] ?? '' }}</h1>
                <p class="surface-subtitle mb-2">
                    {{ $venda['cliente_nome'] ?? 'Consumidor final' }}
                    @if (! empty($venda['vendedor_nome']))
                        · Vendedor: {{ $venda['vendedor_nome'] }}
                    @endif
                    @if (! empty($venda['data_venda']))
                        · {{ \Illuminate\Support\Carbon::parse($venda['data_venda'])->format('d/m/Y') }}
                    @endif
                </p>

                <div class="d-flex flex-wrap gap-2">
                    @include('layouts.partials.status-pill', [
                        'label' => $venda['status_label'] ?? '-',
                        'color' => $venda['status_color'] ?? '#8b93a7',
                        'small' => true,
                    ])
                    @include('layouts.partials.status-pill', [
                        'label' => $venda['status_pagamento_label'] ?? 'Pendente',
                        'color' => match ($venda['status_pagamento'] ?? '') {
                            'pago' => '#29c384',
                            'parcial' => '#f5a623',
                            default => '#e2574c',
                        },
                        'small' => true,
                    ])
                    @if (! empty($venda['estoque_divergente']))
                        @include('layouts.partials.status-pill', [
                            'label' => 'Vendida sem saldo',
                            'color' => '#f5a623',
                            'small' => true,
                        ])
                    @endif
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('vendas.receipt', $vendaId) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="bi bi-printer me-2"></i>Cupom 80mm
                </a>
                <a href="{{ route('vendas.receipt', ['venda' => $vendaId, 'formato' => 'a4']) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-pdf me-2"></i>A4
                </a>
                @if (! $cancelada && \App\Support\DesktopSession::can('vendas', 'criar'))
                    <a href="{{ route('devolucoes.create', $vendaId) }}" class="btn btn-outline-warning">
                        <i class="bi bi-arrow-return-left me-2"></i>Devolver
                    </a>
                @endif
                @if (! $cancelada && \App\Support\DesktopSession::can('vendas', 'excluir'))
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#vendaCancelModal">
                        <i class="bi bi-x-circle me-2"></i>Cancelar venda
                    </button>
                @endif
                <a href="{{ route('vendas.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </a>
            </div>
        </div>

        @if ($cancelada)
            <div class="alert alert-danger mt-3 mb-0">
                <strong>Venda cancelada</strong>
                @if (! empty($venda['cancelado_em']))
                    em {{ \Illuminate\Support\Carbon::parse($venda['cancelado_em'])->format('d/m/Y H:i') }}
                @endif
                @if (! empty($venda['motivo_cancelamento']))
                    — {{ $venda['motivo_cancelamento'] }}
                @endif
                <div class="small mt-1">Estoque e título financeiro foram estornados.</div>
            </div>
        @endif
    </section>

    <section class="surface-table mt-3">
        <div class="surface-table-header">
            <h2 class="surface-title">Itens</h2>
        </div>

        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                <tr>
                    <th>Item</th>
                    <th>Tipo</th>
                    <th class="text-center">Qtd</th>
                    <th class="text-end">Unitário</th>
                    <th class="text-end">Desconto</th>
                    <th class="text-end">Total</th>
                </tr>
                </thead>
                <tbody>
                @foreach ((array) ($venda['itens'] ?? []) as $item)
                    <tr>
                        <td data-label="Item">
                            <div class="fw-semibold">{{ $item['descricao'] ?? '' }}</div>
                            @if (! empty($item['codigo']))
                                <small class="text-secondary d-block">{{ $item['codigo'] }}</small>
                            @endif
                            @if (! empty($item['observacoes']))
                                <small class="text-secondary d-block">{{ $item['observacoes'] }}</small>
                            @endif
                        </td>
                        <td data-label="Tipo">{{ $item['tipo_item_label'] ?? '' }}</td>
                        <td data-label="Qtd" class="text-center">
                            {{ rtrim(rtrim(number_format((float) ($item['quantidade'] ?? 0), 3, ',', '.'), '0'), ',') }}
                        </td>
                        <td data-label="Unitário" class="text-end">{{ $money($item['valor_unitario'] ?? 0) }}</td>
                        <td data-label="Desconto" class="text-end">{{ $money($item['desconto'] ?? 0) }}</td>
                        <td data-label="Total" class="text-end fw-semibold">{{ $money($item['total'] ?? 0) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="row justify-content-end px-3 pb-3">
            <div class="col-12 col-md-5">
                <dl class="row mb-0">
                    <dt class="col-7 fw-normal text-secondary">Subtotal</dt>
                    <dd class="col-5 text-end">{{ $money($venda['subtotal'] ?? 0) }}</dd>
                    <dt class="col-7 fw-normal text-secondary">Desconto</dt>
                    <dd class="col-5 text-end">{{ $money($venda['desconto'] ?? 0) }}</dd>
                    @if ((float) ($venda['acrescimo'] ?? 0) > 0)
                        <dt class="col-7 fw-normal text-secondary">Acréscimo</dt>
                        <dd class="col-5 text-end">{{ $money($venda['acrescimo'] ?? 0) }}</dd>
                    @endif
                    <dt class="col-7 fs-5 fw-semibold">Total</dt>
                    <dd class="col-5 text-end fs-5 fw-semibold">{{ $money($venda['total'] ?? 0) }}</dd>

                    <dt class="col-7 fw-normal text-secondary">Custo</dt>
                    <dd class="col-5 text-end">{{ $money($venda['custo_total'] ?? 0) }}</dd>
                    <dt class="col-7 fw-normal text-secondary">Margem</dt>
                    <dd class="col-5 text-end">
                        {{ $money($venda['margem_valor'] ?? 0) }}
                        <small class="text-secondary">({{ number_format((float) ($venda['margem_percentual'] ?? 0), 2, ',', '.') }}%)</small>
                    </dd>
                </dl>
            </div>
        </div>
    </section>

    <section class="surface-table mt-3">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Pagamentos</h2>
                @if (! empty($venda['financeiro_id']) && \App\Support\DesktopSession::can('financeiro', 'visualizar'))
                    <p class="surface-subtitle mb-0">
                        <a href="{{ route('financeiro.show', $venda['financeiro_id']) }}">
                            Ver título no financeiro <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    </p>
                @endif
            </div>
            @if ($aberto > 0 && ! $cancelada)
                <span class="desktop-chip text-warning">
                    <i class="bi bi-exclamation-circle"></i>
                    Em aberto: {{ $money($aberto) }}
                </span>
            @endif
        </div>

        @if (! empty($venda['pagamentos']))
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Forma</th>
                        <th class="text-center">Parcelas</th>
                        <th class="text-end">Valor</th>
                        <th class="text-end">Taxa</th>
                        <th class="text-end">Líquido</th>
                        <th class="text-end">Troco</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ((array) $venda['pagamentos'] as $pagamento)
                        <tr>
                            <td data-label="Forma">
                                {{ ucfirst(str_replace('_', ' ', (string) ($pagamento['forma_pagamento'] ?? ''))) }}
                            </td>
                            <td data-label="Parcelas" class="text-center">{{ $pagamento['parcelas'] ?? 1 }}x</td>
                            <td data-label="Valor" class="text-end">{{ $money($pagamento['valor'] ?? 0) }}</td>
                            <td data-label="Taxa" class="text-end">{{ $money($pagamento['valor_taxa'] ?? 0) }}</td>
                            <td data-label="Líquido" class="text-end">{{ $money($pagamento['valor_liquido'] ?? 0) }}</td>
                            <td data-label="Troco" class="text-end">{{ $money($pagamento['troco'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-secondary px-3 pb-3 mb-0">Nenhum pagamento registrado — venda em aberto.</p>
        @endif
    </section>

    @if (! empty($venda['observacoes']))
        <section class="surface-card mt-3">
            <h2 class="surface-title fs-6">Observações</h2>
            <p class="mb-0">{{ $venda['observacoes'] }}</p>
        </section>
    @endif
@endsection

@push('modals')
    @if (! $cancelada && \App\Support\DesktopSession::can('vendas', 'excluir'))
        @include('vendas._cancel_modal', ['venda' => $venda, 'exigeAdmin' => ! $doDia])
    @endif
@endpush
