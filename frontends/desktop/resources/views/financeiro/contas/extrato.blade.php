@extends('layouts.app')

@php
    $statement = is_array($statement ?? null) ? $statement : [];
    $account = is_array($statement['conta'] ?? null) ? $statement['conta'] : [];
    $period = is_array($statement['periodo'] ?? null) ? $statement['periodo'] : [];
    $items = is_array($statement['movimentos'] ?? null) ? $statement['movimentos'] : [];
    $pagination = is_array($statement['paginacao'] ?? null) ? $statement['paginacao'] : [];
    $money = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
    $date = static fn ($value): string => $value ? date('d/m/Y', strtotime((string) $value)) : '—';
@endphp

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro · Contas e Saldos</p>
            <h2 class="surface-title fs-3 mb-2">Extrato — {{ $account['nome'] ?? 'Conta' }}</h2>
            <p class="surface-subtitle mb-0">Movimentos operacionais, créditos líquidos de cartão, saldo inicial, ajustes e transferências.</p>
        </div>
        <a href="{{ route('financeiro.contas.index') }}" class="btn btn-outline-light align-self-start"><i class="bi bi-arrow-left me-2"></i>Voltar às contas</a>
    </div>

    <form class="surface-card mb-4" method="GET">
        <div class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label">Data inicial</label><input type="date" name="data_inicio" value="{{ $filters['data_inicio'] }}" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">Data final</label><input type="date" name="data_fim" value="{{ $filters['data_fim'] }}" class="form-control" required></div>
            <div class="col-auto"><button class="btn btn-primary"><i class="bi bi-search me-2"></i>Consultar</button></div>
        </div>
    </form>

    <div class="desktop-grid desktop-grid-four mb-4">
        <article class="summary-card"><p class="summary-card-eyebrow">Saldo inicial do período</p><h3 class="summary-card-value">{{ $money($period['saldo_inicial'] ?? 0) }}</h3></article>
        <article class="summary-card"><p class="summary-card-eyebrow">Entradas realizadas</p><h3 class="summary-card-value text-success">{{ $money($period['entradas'] ?? 0) }}</h3></article>
        <article class="summary-card"><p class="summary-card-eyebrow">Saídas realizadas</p><h3 class="summary-card-value text-danger">{{ $money($period['saidas'] ?? 0) }}</h3></article>
        <article class="summary-card"><p class="summary-card-eyebrow">Saldo final do período</p><h3 class="summary-card-value">{{ $money($period['saldo_final'] ?? 0) }}</h3></article>
    </div>

    <section class="surface-card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Data</th><th>Descrição</th><th>Origem</th><th>Status</th><th class="text-end">Entrada</th><th class="text-end">Saída</th></tr></thead>
                <tbody>
                    @forelse ($items as $item)
                        @php $isEntry = ($item['natureza'] ?? '') === 'entrada'; @endphp
                        <tr class="{{ ($item['status'] ?? '') === 'cancelado' ? 'opacity-50' : '' }}">
                            <td>{{ $date($item['data'] ?? null) }}</td>
                            <td><strong>{{ $item['descricao'] ?? 'Movimento' }}</strong>@if(!empty($item['documento_ref']))<br><small class="text-body-secondary">Ref.: {{ $item['documento_ref'] }}</small>@endif</td>
                            <td><span class="badge text-bg-secondary">{{ ucfirst(str_replace('_', ' ', (string) ($item['subtipo'] ?? $item['origem'] ?? 'movimento'))) }}</span></td>
                            <td><span class="badge {{ ($item['status'] ?? '') === 'realizado' ? 'text-bg-success' : (($item['status'] ?? '') === 'previsto' ? 'text-bg-warning' : 'text-bg-secondary') }}">{{ ucfirst((string) ($item['status'] ?? '')) }}</span></td>
                            <td class="text-end text-success">{{ $isEntry ? $money($item['valor'] ?? 0) : '—' }}</td>
                            <td class="text-end text-danger">{{ !$isEntry ? $money($item['valor'] ?? 0) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-body-secondary py-5">Nenhum movimento no período.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ((int) ($pagination['ultima_pagina'] ?? 1) > 1)
            <div class="d-flex justify-content-between align-items-center pt-3 mt-3 border-top">
                <small class="text-body-secondary">{{ (int) ($pagination['total'] ?? 0) }} movimentos</small>
                <div class="btn-group">
                    @if ((int) ($pagination['pagina_atual'] ?? 1) > 1)<a class="btn btn-sm btn-outline-light" href="{{ request()->fullUrlWithQuery(['page' => (int) $pagination['pagina_atual'] - 1]) }}">Anterior</a>@endif
                    @if ((int) ($pagination['pagina_atual'] ?? 1) < (int) ($pagination['ultima_pagina'] ?? 1))<a class="btn btn-sm btn-outline-light" href="{{ request()->fullUrlWithQuery(['page' => (int) $pagination['pagina_atual'] + 1]) }}">Próxima</a>@endif
                </div>
            </div>
        @endif
    </section>
@endsection
