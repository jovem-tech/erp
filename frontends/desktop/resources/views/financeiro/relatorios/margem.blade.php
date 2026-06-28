@extends('layouts.app')

@section('content')
    @php
        $fmt = static fn ($valor) => 'R$ ' . number_format((float) ($valor ?? 0), 2, ',', '.');
        $piores = $margem['piores_os'] ?? [];
        $melhores = $margem['melhores_os'] ?? [];
        $porTecnico = $margem['por_tecnico'] ?? [];
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Margem por OS</h2>
            <p class="surface-subtitle mb-0">
                Receita líquida menos custo real de peças (saída de estoque) e comissão do técnico, referência: {{ $margem['periodo_label'] ?? '' }}.
            </p>
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
            <p class="surface-subtitle mb-1">OS concluídas no período</p>
            <h3 class="surface-title mb-0">{{ $margem['total_os'] ?? 0 }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Ticket médio</p>
            <h3 class="surface-title mb-0">{{ $fmt($margem['ticket_medio'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Margem média</p>
            <h3 class="surface-title mb-0">{{ number_format((float) ($margem['margem_media_percentual'] ?? 0), 2, ',', '.') }}%</h3>
        </div>
    </div>

    <div class="desktop-grid desktop-grid-two mb-4">
        <section class="surface-table">
            <div class="surface-table-header">
                <div>
                    <h2 class="surface-title">OS com menor margem</h2>
                    <p class="surface-subtitle">Mais urgentes para revisar precificação ou custo.</p>
                </div>
            </div>

            @if ($piores !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead><tr><th>OS</th><th class="text-end">Margem</th><th class="text-end">%</th></tr></thead>
                        <tbody>
                        @foreach ($piores as $linha)
                            <tr>
                                <td>{{ $linha['numero_os'] ?? ('#' . ($linha['os_id'] ?? '-')) }}</td>
                                <td class="text-end">{{ $fmt($linha['margem_contribuicao'] ?? 0) }}</td>
                                <td class="text-end {{ ((float) ($linha['percentual_margem'] ?? 0)) < 0 ? 'text-danger fw-semibold' : '' }}">
                                    {{ number_format((float) ($linha['percentual_margem'] ?? 0), 2, ',', '.') }}%
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-graph-up',
                    'title' => 'Sem OS concluídas no período',
                    'message' => 'Ajuste o mês de referência para ver o ranking de margem.',
                ])
            @endif
        </section>

        <section class="surface-table">
            <div class="surface-table-header">
                <div>
                    <h2 class="surface-title">OS com maior margem</h2>
                    <p class="surface-subtitle">Padrão de serviço mais lucrativo no período.</p>
                </div>
            </div>

            @if ($melhores !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead><tr><th>OS</th><th class="text-end">Margem</th><th class="text-end">%</th></tr></thead>
                        <tbody>
                        @foreach ($melhores as $linha)
                            <tr>
                                <td>{{ $linha['numero_os'] ?? ('#' . ($linha['os_id'] ?? '-')) }}</td>
                                <td class="text-end">{{ $fmt($linha['margem_contribuicao'] ?? 0) }}</td>
                                <td class="text-end">{{ number_format((float) ($linha['percentual_margem'] ?? 0), 2, ',', '.') }}%</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-graph-up',
                    'title' => 'Sem OS concluídas no período',
                    'message' => 'Ajuste o mês de referência para ver o ranking de margem.',
                ])
            @endif
        </section>
    </div>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Margem por técnico</h2>
                <p class="surface-subtitle">Produtividade e qualidade de margem por responsável técnico.</p>
            </div>
        </div>

        @if ($porTecnico !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead><tr><th>Técnico (ID)</th><th>OS no período</th><th class="text-end">Margem média</th><th class="text-end">Margem total</th></tr></thead>
                    <tbody>
                    @foreach ($porTecnico as $linha)
                        <tr>
                            <td>{{ $linha['tecnico_id'] ?? 'Sem técnico' }}</td>
                            <td>{{ $linha['total_os'] ?? 0 }}</td>
                            <td class="text-end">{{ number_format((float) ($linha['margem_media_percentual'] ?? 0), 2, ',', '.') }}%</td>
                            <td class="text-end">{{ $fmt($linha['margem_total'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-person-badge',
                'title' => 'Sem dados por técnico',
                'message' => 'Ajuste o mês de referência para ver a margem por técnico.',
            ])
        @endif
    </section>
@endsection
