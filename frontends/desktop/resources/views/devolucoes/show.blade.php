@extends('layouts.app')

@section('content')
    @php
        $devolucao = is_array($devolucao) ? $devolucao : [];
        $devolucaoId = (int) ($devolucao['id'] ?? 0);
        $abatido = (float) ($devolucao['valor_abatido'] ?? 0);
        $taxaPerdida = (float) ($devolucao['valor_taxa_nao_estornada'] ?? 0);
        $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    @endphp

    <section class="surface-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="surface-title mb-1">Devolução {{ $devolucao['numero'] ?? '' }}</h1>
                <p class="surface-subtitle mb-0">
                    Venda
                    <a href="{{ route('vendas.show', (int) ($devolucao['venda_id'] ?? 0)) }}">
                        {{ $devolucao['venda_numero'] ?? '' }}
                    </a>
                    · {{ $devolucao['cliente_nome'] ?: 'Consumidor final' }}
                    @if (! empty($devolucao['data_devolucao']))
                        · {{ \Illuminate\Support\Carbon::parse($devolucao['data_devolucao'])->format('d/m/Y') }}
                    @endif
                    @if (! empty($devolucao['criado_por_nome']))
                        · por {{ $devolucao['criado_por_nome'] }}
                    @endif
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('devolucoes.receipt', $devolucaoId) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="bi bi-printer me-2"></i>Cupom 80mm
                </a>
                <a href="{{ route('devolucoes.receipt', ['devolucao' => $devolucaoId, 'formato' => 'a4']) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-pdf me-2"></i>A4
                </a>
                <a href="{{ route('devolucoes.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </a>
            </div>
        </div>

        @if (! empty($devolucao['venda_troca_numero']))
            <div class="alert alert-info mt-3 mb-0">
                <i class="bi bi-arrow-left-right me-2"></i>
                Troca: o cliente levou a venda
                <a href="{{ route('vendas.show', (int) ($devolucao['venda_troca_id'] ?? 0)) }}">
                    {{ $devolucao['venda_troca_numero'] }}
                </a> no lugar.
            </div>
        @endif
    </section>

    <section class="surface-table mt-3">
        <div class="surface-table-header">
            <h2 class="surface-title">Itens devolvidos</h2>
        </div>

        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                <tr>
                    <th>Item</th>
                    <th class="text-center">Qtd</th>
                    <th class="text-end">Unitário</th>
                    <th class="text-end">Reembolso</th>
                    <th>Estoque</th>
                </tr>
                </thead>
                <tbody>
                @foreach ((array) ($devolucao['itens'] ?? []) as $item)
                    <tr>
                        <td data-label="Item">
                            <div class="fw-semibold">{{ $item['descricao'] ?? '' }}</div>
                            @if (! empty($item['codigo']))
                                <small class="text-secondary d-block">{{ $item['codigo'] }}</small>
                            @endif
                        </td>
                        <td data-label="Qtd" class="text-center">
                            {{ rtrim(rtrim(number_format((float) ($item['quantidade'] ?? 0), 3, ',', '.'), '0'), ',') }}
                        </td>
                        <td data-label="Unitário" class="text-end">{{ $money($item['valor_unitario'] ?? 0) }}</td>
                        <td data-label="Reembolso" class="text-end fw-semibold">{{ $money($item['valor_reembolsado'] ?? 0) }}</td>
                        <td data-label="Estoque">
                            @if (! empty($item['retorna_estoque']))
                                <span class="badge bg-success">Voltou ao estoque</span>
                            @else
                                <span class="badge bg-secondary">Não movimenta</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="row justify-content-end px-3 pb-3">
            <div class="col-12 col-md-5">
                <dl class="row mb-0">
                    <dt class="col-7 fw-normal text-secondary">Crédito do cliente</dt>
                    <dd class="col-5 text-end">{{ $money($devolucao['valor_devolvido'] ?? 0) }}</dd>

                    <dt class="col-7 fs-5 fw-semibold">Reembolsado</dt>
                    <dd class="col-5 text-end fs-5 fw-semibold">{{ $money($devolucao['valor_reembolsado'] ?? 0) }}</dd>

                    @if ($abatido > 0)
                        <dt class="col-7 fw-normal text-secondary">Abatido da dívida</dt>
                        <dd class="col-5 text-end">{{ $money($abatido) }}</dd>
                    @endif
                </dl>

                @if ($abatido > 0)
                    <small class="text-secondary d-block mt-2">
                        A venda tinha saldo em aberto: só o que o cliente já havia pago voltou
                        em dinheiro; o restante abateu a dívida.
                    </small>
                @endif
            </div>
        </div>
    </section>

    <section class="surface-table mt-3">
        <div class="surface-table-header">
            <h2 class="surface-title">Reembolso</h2>
        </div>

        @if (! empty($devolucao['pagamentos']))
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Forma</th>
                        <th class="text-end">Valor</th>
                        <th class="text-end">Taxa não estornada</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ((array) $devolucao['pagamentos'] as $pagamento)
                        <tr>
                            <td data-label="Forma">
                                {{ ucfirst(str_replace('_', ' ', (string) ($pagamento['forma_pagamento'] ?? ''))) }}
                            </td>
                            <td data-label="Valor" class="text-end fw-semibold">{{ $money($pagamento['valor'] ?? 0) }}</td>
                            <td data-label="Taxa não estornada" class="text-end">
                                {{ (float) ($pagamento['valor_taxa_nao_estornada'] ?? 0) > 0 ? $money($pagamento['valor_taxa_nao_estornada']) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-secondary px-3 pb-3 mb-0">
                Sem reembolso em dinheiro — o crédito abateu a dívida em aberto da venda.
            </p>
        @endif

        @if ($taxaPerdida > 0)
            <div class="alert alert-warning m-3">
                <i class="bi bi-exclamation-triangle me-2"></i>
                A operadora não devolve a taxa do cartão: <strong>{{ $money($taxaPerdida) }}</strong>
                ficaram como custo desta devolução. A despesa já estava lançada desde a venda
                e não foi revertida.
            </div>
        @endif
    </section>

    <section class="surface-card mt-3">
        <h2 class="surface-title fs-6">Motivo</h2>
        <p class="mb-0">{{ $devolucao['motivo'] ?? '' }}</p>
    </section>
@endsection
