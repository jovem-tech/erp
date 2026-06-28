@extends('layouts.app')

@section('content')
    @php
        $fmt = static fn ($valor) => 'R$ ' . number_format((float) ($valor ?? 0), 2, ',', '.');
        $linhas = $fluxo['linhas_diarias'] ?? [];
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Fluxo de caixa</h2>
            <p class="surface-subtitle mb-0">Movimentos já realizados e títulos com vencimento previsto, referência: {{ $fluxo['periodo_label'] ?? '' }}.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.relatorios.dre', ['mes' => $mes]) }}" class="btn btn-outline-light">
                <i class="bi bi-graph-up-arrow me-2"></i>
                DRE por competência
            </a>
        </div>
    </div>

    <section class="desktop-form-card mb-4">
        <form method="get" class="desktop-filter-grid">
            <div>
                <label for="mes">Mês de referência</label>
                <input type="month" id="mes" name="mes" class="form-control" value="{{ $mes }}">
            </div>
            <div class="field-actions">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Atualizar
                </button>
            </div>
        </form>
    </section>

    <div class="desktop-grid desktop-grid-three mb-4">
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Saldo inicial</p>
            <h3 class="surface-title mb-0">{{ $fmt($fluxo['saldo_inicial'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Saldo final (realizado)</p>
            <h3 class="surface-title mb-0">{{ $fmt($fluxo['saldo_final'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Saldo projetado</p>
            <h3 class="surface-title mb-0">{{ $fmt($fluxo['saldo_projetado'] ?? 0) }}</h3>
        </div>
    </div>

    <div class="desktop-grid desktop-grid-two mb-4">
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Realizado no período</h4>
            <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Entradas</span>
                <span class="fw-semibold">{{ $fmt($fluxo['entradas_realizadas'] ?? 0) }}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-secondary">Saídas</span>
                <span class="fw-semibold">{{ $fmt($fluxo['saidas_realizadas'] ?? 0) }}</span>
            </div>
        </div>
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Previsto até o vencimento</h4>
            <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Entradas previstas</span>
                <span class="fw-semibold">{{ $fmt($fluxo['entradas_previstas'] ?? 0) }}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-secondary">Saídas previstas</span>
                <span class="fw-semibold">{{ $fmt($fluxo['saidas_previstas'] ?? 0) }}</span>
            </div>
        </div>
    </div>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Movimento diário</h2>
                <p class="surface-subtitle">Saldo realizado acumulado dia a dia dentro do período.</p>
            </div>
        </div>

        @if ($linhas !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr><th>Data</th><th class="text-end">Entradas</th><th class="text-end">Saídas</th><th class="text-end">Saldo realizado</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($linhas as $linha)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($linha['data'])->format('d/m/Y') }}</td>
                            <td class="text-end">{{ $fmt($linha['entradas_realizadas'] ?? 0) }}</td>
                            <td class="text-end">{{ $fmt($linha['saidas_realizadas'] ?? 0) }}</td>
                            <td class="text-end fw-semibold">{{ $fmt($linha['saldo_realizado'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-calendar3-week',
                'title' => 'Sem movimentos no período',
                'message' => 'Ajuste o mês de referência para ver o detalhamento diário.',
            ])
        @endif
    </section>

    <div class="desktop-grid desktop-grid-two mt-4">
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Realizado por categoria</h4>
            @forelse (($fluxo['realizados_por_categoria'] ?? []) as $categoria => $valor)
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-secondary">{{ $categoria }}</span>
                    <span class="fw-semibold">{{ $fmt($valor) }}</span>
                </div>
            @empty
                <p class="text-secondary mb-0">Sem movimentos classificados no período.</p>
            @endforelse
        </div>
        <div class="desktop-form-card">
            <h4 class="surface-title mb-3">Previsto por categoria</h4>
            @forelse (($fluxo['previstos_por_categoria'] ?? []) as $categoria => $valor)
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-secondary">{{ $categoria }}</span>
                    <span class="fw-semibold">{{ $fmt($valor) }}</span>
                </div>
            @empty
                <p class="text-secondary mb-0">Sem títulos pendentes no período.</p>
            @endforelse
        </div>
    </div>
@endsection
