@extends('layouts.app')

@section('content')
    @php
        $fmt = static fn ($valor) => 'R$ ' . number_format((float) ($valor ?? 0), 2, ',', '.');
        $pct = static fn ($valor) => number_format((float) ($valor ?? 0), 2, ',', '.') . '%';
        $piores = $margem['piores_os'] ?? [];
        $melhores = $margem['melhores_os'] ?? [];
        $melhoresPorHora = $margem['melhores_por_hora'] ?? [];
        $pioresPorHora = $margem['piores_por_hora'] ?? [];
        $porTecnico = $margem['por_tecnico'] ?? [];
        $variaveis = $margem['custos_variaveis'] ?? [];
        $horas = $margem['horas'] ?? [];
        $semApontamento = (int) ($horas['os_sem_apontamento'] ?? 0);
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Margem por OS <x-favorite-toggle /></h2>
            <p class="surface-subtitle mb-0">
                Receita menos os custos que variam com a venda — peças (custo real de estoque), comissão,
                taxa de recebimento e imposto. Referência: {{ $margem['periodo_label'] ?? '' }}.
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

    <div class="desktop-grid desktop-grid-four mb-4">
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">OS concluídas no período</p>
            <h3 class="surface-title mb-0">{{ $margem['total_os'] ?? 0 }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Ticket médio</p>
            <h3 class="surface-title mb-0">{{ $fmt($margem['ticket_medio'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Margem de contribuição</p>
            <h3 class="surface-title mb-0 {{ ((float) ($margem['margem_total'] ?? 0)) < 0 ? 'text-danger' : '' }}">
                {{ $fmt($margem['margem_total'] ?? 0) }}
            </h3>
        </div>
        <div class="desktop-form-card text-center">
            {{-- Índice de contribuição: MC total / receita total. Não é a média
                 dos percentuais das OS — num mix heterogêneo a média simples
                 distorce para cima. --}}
            <p class="surface-subtitle mb-1">Índice de contribuição</p>
            <h3 class="surface-title mb-0">{{ $pct($margem['margem_media_percentual'] ?? 0) }}</h3>
            <p class="surface-subtitle mb-0 small">sobre {{ $fmt($margem['receita_total'] ?? 0) }}</p>
        </div>
    </div>

    <section class="surface-table mb-4">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Composição dos custos variáveis</h2>
                <p class="surface-subtitle">O que consumiu a receita antes de sobrar margem para pagar o fixo.</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <tbody>
                <tr>
                    <td class="fw-semibold">Receita do período</td>
                    <td class="text-end fw-semibold">{{ $fmt($margem['receita_total'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td>(-) Peças aplicadas</td>
                    <td class="text-end">{{ $fmt($variaveis['pecas'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td>(-) Comissões</td>
                    <td class="text-end">{{ $fmt($variaveis['comissao'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td>(-) Taxas de recebimento</td>
                    <td class="text-end">{{ $fmt($variaveis['taxa_recebimento'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td>
                        (-) Impostos sobre venda
                        <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">estimado</span>
                    </td>
                    <td class="text-end">{{ $fmt($variaveis['imposto'] ?? 0) }}</td>
                </tr>
                <tr class="table-light">
                    <td class="fw-semibold">(=) Margem de contribuição</td>
                    <td class="text-end fw-semibold {{ ((float) ($margem['margem_total'] ?? 0)) < 0 ? 'text-danger' : '' }}">
                        {{ $fmt($margem['margem_total'] ?? 0) }}
                        <span class="text-secondary fw-normal small">({{ $pct($margem['margem_media_percentual'] ?? 0) }})</span>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

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
                                    {{ $pct($linha['percentual_margem'] ?? 0) }}
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
                                <td class="text-end">{{ $pct($linha['percentual_margem'] ?? 0) }}</td>
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

    <section class="surface-table mb-4">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Margem por hora de técnico</h2>
                <p class="surface-subtitle">
                    Quando a bancada é o gargalo, o que decide o mix é a margem por hora — não a margem da OS.
                    Um reparo de margem alta que trava o técnico o dia inteiro pode render menos que dois
                    serviços rápidos.
                </p>
            </div>
        </div>

        <div class="p-3">
            <div class="desktop-grid desktop-grid-three mb-3">
                <div class="desktop-form-card text-center">
                    <p class="surface-subtitle mb-1">Margem por hora (período)</p>
                    <h3 class="surface-title mb-0">
                        {{ ($horas['margem_por_hora'] ?? null) !== null ? $fmt($horas['margem_por_hora']) : '—' }}
                    </h3>
                </div>
                <div class="desktop-form-card text-center">
                    <p class="surface-subtitle mb-1">Horas apontadas</p>
                    <h3 class="surface-title mb-0">{{ number_format((float) ($horas['total'] ?? 0), 2, ',', '.') }}h</h3>
                </div>
                <div class="desktop-form-card text-center">
                    <p class="surface-subtitle mb-1">OS sem apontamento</p>
                    <h3 class="surface-title mb-0 {{ $semApontamento > 0 ? 'text-warning' : '' }}">{{ $semApontamento }}</h3>
                </div>
            </div>

            @if ($semApontamento > 0)
                <div class="alert alert-warning">
                    <i class="bi bi-clock-history me-2"></i>
                    {{ $semApontamento }} OS do período ficaram sem horas apontadas e estão fora deste ranking.
                    O técnico informa as horas ao concluir o reparo; a baixa também aceita, quando o dado não veio antes.
                </div>
            @endif

            @if ($melhoresPorHora !== [])
                <div class="desktop-grid desktop-grid-two">
                    <div>
                        <h3 class="surface-subtitle fw-semibold mb-2">Melhor retorno por hora</h3>
                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead><tr><th>OS</th><th class="text-end">Horas</th><th class="text-end">R$/hora</th></tr></thead>
                                <tbody>
                                @foreach ($melhoresPorHora as $linha)
                                    <tr>
                                        <td>{{ $linha['numero_os'] ?? ('#' . ($linha['os_id'] ?? '-')) }}</td>
                                        <td class="text-end">{{ number_format((float) ($linha['tempo_tecnico_horas'] ?? 0), 2, ',', '.') }}h</td>
                                        <td class="text-end fw-semibold">{{ $fmt($linha['margem_por_hora'] ?? 0) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div>
                        <h3 class="surface-subtitle fw-semibold mb-2">Pior retorno por hora</h3>
                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead><tr><th>OS</th><th class="text-end">Horas</th><th class="text-end">R$/hora</th></tr></thead>
                                <tbody>
                                @foreach ($pioresPorHora as $linha)
                                    <tr>
                                        <td>{{ $linha['numero_os'] ?? ('#' . ($linha['os_id'] ?? '-')) }}</td>
                                        <td class="text-end">{{ number_format((float) ($linha['tempo_tecnico_horas'] ?? 0), 2, ',', '.') }}h</td>
                                        <td class="text-end {{ ((float) ($linha['margem_por_hora'] ?? 0)) < 0 ? 'text-danger fw-semibold' : 'fw-semibold' }}">
                                            {{ $fmt($linha['margem_por_hora'] ?? 0) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-clock-history',
                    'title' => 'Nenhuma OS com horas apontadas',
                    'message' => 'Assim que os técnicos informarem o tempo de bancada ao concluir os reparos, o ranking por hora aparece aqui.',
                ])
            @endif
        </div>
    </section>

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
                    <thead>
                    <tr>
                        <th>Técnico (ID)</th>
                        <th>OS no período</th>
                        <th class="text-end">Índice de contribuição</th>
                        <th class="text-end">Margem total</th>
                        <th class="text-end">Horas</th>
                        <th class="text-end">R$/hora</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($porTecnico as $linha)
                        <tr>
                            <td>{{ $linha['tecnico_id'] ?? 'Sem técnico' }}</td>
                            <td>{{ $linha['total_os'] ?? 0 }}</td>
                            <td class="text-end">{{ $pct($linha['margem_media_percentual'] ?? 0) }}</td>
                            <td class="text-end">{{ $fmt($linha['margem_total'] ?? 0) }}</td>
                            <td class="text-end">{{ number_format((float) ($linha['horas_totais'] ?? 0), 2, ',', '.') }}h</td>
                            <td class="text-end">
                                {{ ($linha['margem_por_hora'] ?? null) !== null ? $fmt($linha['margem_por_hora']) : '—' }}
                            </td>
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
