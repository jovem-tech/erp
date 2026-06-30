@extends('layouts.app')

@section('content')
    @php
        $visualSummary = collect($visualSummary ?? []);
        $workflowTrail = collect($workflowTrail ?? []);
        $modelPillars = collect($modelPillars ?? []);
        $naturalJourney = is_array($naturalJourney ?? null) ? $naturalJourney : [];
        $workflowLanes = collect($workflowLanes ?? []);
        $queueRules = collect($queueRules ?? []);
        $specialCases = collect($specialCases ?? []);
        $naturalTrail = collect($naturalJourney['trail'] ?? []);
        $naturalSteps = collect($naturalJourney['steps'] ?? []);
        $naturalBranch = is_array($naturalJourney['branch'] ?? null) ? $naturalJourney['branch'] : null;
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Gestão de Conhecimento</p>
            <h2 class="surface-title fs-3 mb-2">{{ $pageTitle ?? 'Modelo Ideal da Assistência Técnica' }}</h2>
            <p class="surface-subtitle mb-0">
                Fluxo pensado para evitar procrastinação operacional, fila quebrada e OS sem dono.
                A lógica abaixo prioriza triagem rápida, responsabilidade clara, SLA curto e saída controlada.
            </p>
        </div>
    </div>

    <section class="workflow-diagram surface-card mb-4">
        <div class="surface-card-header">
            <div>
                <p class="desktop-eyebrow">Caso feliz</p>
                <h2 class="surface-title">{{ $naturalJourney['title'] ?? 'Fluxo natural de uma OS reparada e entregue' }}</h2>
                <p class="surface-subtitle mb-0">
                    {{ $naturalJourney['subtitle'] ?? 'Simulação do caminho feliz com base nos status atuais do catálogo da assistência.' }}
                </p>
            </div>

            <div class="workflow-legend">
                <span class="workflow-legend-item">Status atuais</span>
                <span class="workflow-legend-item is-transition">Orçamento</span>
                <span class="workflow-legend-item is-transition">Execução</span>
                <span class="workflow-legend-item is-final">Entrega</span>
                <span class="workflow-legend-item is-pause">Ramo financeiro</span>
            </div>
        </div>

        <div class="workflow-overview-grid">
            <div>
                <p class="workflow-overview-label">Trajeto do cliente</p>
                <div class="workflow-trail">
                    @forelse ($naturalTrail as $trailLabel)
                        <span class="workflow-trail-step">{{ $trailLabel }}</span>
                        @if (! $loop->last)
                            <i class="bi bi-arrow-right workflow-trail-arrow" aria-hidden="true"></i>
                        @endif
                    @empty
                        <span class="workflow-trail-empty">Nenhum status atual encontrado.</span>
                    @endforelse
                </div>
            </div>

            <div class="workflow-stats-grid">
                <article class="workflow-stat-card">
                    <span>Status consultados</span>
                    <strong>{{ (int) ($naturalJourney['status_count'] ?? 0) }}</strong>
                    <small>catálogo atual usado na simulação</small>
                </article>
                <article class="workflow-stat-card">
                    <span>Ponto inicial</span>
                    <strong>Recepção</strong>
                    <small>entrada física do equipamento</small>
                </article>
                <article class="workflow-stat-card">
                    <span>Ponto final</span>
                    <strong>Equipamento Entregue</strong>
                    <small>caso feliz sem desvio</small>
                </article>
                <article class="workflow-stat-card">
                    <span>Ramo opcional</span>
                    <strong>{{ $naturalBranch['resolved_label'] ?? 'Nenhum' }}</strong>
                    <small>{{ $naturalBranch['description'] ?? 'Sem saldo em aberto após a entrega.' }}</small>
                </article>
            </div>
        </div>

        <div class="workflow-lanes">
            <section
                class="workflow-lane"
                style="--workflow-lane-accent: #16a34a; --workflow-lane-soft-accent: rgba(22, 163, 74, 0.12);"
            >
                    <div class="workflow-lane-header">
                    <div>
                        <p class="desktop-eyebrow mb-1">Linha principal</p>
                        <h3 class="surface-title fs-5 mb-1">Cliente entra, passa por orçamento, repara e retira</h3>
                        <p class="surface-subtitle mb-0">
                            Este é o caminho feliz que não quebra a fila: cada etapa entrega a próxima ação, com orçamento, autorização, execução, testes e entrega explícitos.
                        </p>
                    </div>

                    <div class="workflow-lane-stats">
                        <span class="desktop-chip">Fluxo natural</span>
                        <span class="desktop-chip">Sem gargalo invisível</span>
                        <span class="desktop-chip">Status atuais</span>
                    </div>
                </div>

                <div class="workflow-lane-track">
                    @forelse ($naturalSteps as $step)
                        <article class="workflow-node" style="--workflow-node-accent: {{ $step['accent'] ?? '#6f5afc' }};">
                            <div class="workflow-node-head">
                                <div class="workflow-node-order">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>

                                <div class="workflow-node-copy">
                                    <div class="workflow-node-title-row">
                                        <h4 class="workflow-node-title">{{ $step['resolved_label'] ?? $step['label'] ?? '' }}</h4>
                                        @if (!empty($step['resolved']))
                                            @include('layouts.partials.status-pill', [
                                                'label' => 'Status atual',
                                                'color' => $step['accent'] ?? '#16a34a',
                                                'small' => true,
                                            ])
                                        @elseif (!empty($step['kind']) && $step['kind'] === 'context')
                                            @include('layouts.partials.status-pill', [
                                                'label' => 'Contexto',
                                                'color' => $step['accent'] ?? '#0ea5e9',
                                                'small' => true,
                                            ])
                                        @endif
                                    </div>
                                    <p class="workflow-node-code">
                                        {{ $step['code'] !== '' ? $step['code'] : 'entrada / saída' }}
                                    </p>
                                </div>
                            </div>

                            <div class="workflow-node-body">
                                <div class="workflow-node-flags">
                                    <span class="workflow-node-state">{{ $step['owner'] ?? 'Dono não definido' }}</span>
                                    <span class="workflow-node-state">{{ $step['timebox'] ?? 'sem prazo' }}</span>
                                </div>

                                <div class="workflow-node-metrics">
                                    <span class="desktop-chip">Entrada: {{ $step['entry'] ?? '' }}</span>
                                    <span class="desktop-chip">Saída: {{ $step['exit'] ?? '' }}</span>
                                </div>

                                <div class="workflow-node-destinations">
                                    <strong>O que garante</strong>
                                    <div class="workflow-node-destination-list">
                                        <div class="workflow-node-destination">
                                            <span class="workflow-node-empty">{{ $step['description'] ?? '' }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>

                        @if (! $loop->last)
                            <div class="workflow-connector" aria-hidden="true">
                                <span class="workflow-connector-line"></span>
                                <i class="bi bi-arrow-right"></i>
                                <span class="workflow-connector-line"></span>
                            </div>
                        @endif
                    @empty
                        <div class="workflow-lane-empty">Nenhum status atual encontrado para simular o caso feliz.</div>
                    @endforelse
                </div>

                @if ($naturalBranch !== null)
                    <div class="workflow-node mt-4" style="--workflow-node-accent: {{ $naturalBranch['accent'] ?? '#f59e0b' }};">
                        <div class="workflow-node-head">
                            <div class="workflow-node-order">
                                <i class="bi bi-credit-card-2-front"></i>
                            </div>

                            <div class="workflow-node-copy">
                                <div class="workflow-node-title-row">
                                    <h4 class="workflow-node-title">{{ $naturalBranch['resolved_label'] ?? $naturalBranch['label'] ?? '' }}</h4>
                                    @include('layouts.partials.status-pill', [
                                        'label' => 'Ramo opcional',
                                        'color' => $naturalBranch['accent'] ?? '#f59e0b',
                                        'small' => true,
                                    ])
                                </div>
                                <p class="workflow-node-code">{{ $naturalBranch['code'] ?? '' }}</p>
                            </div>
                        </div>

                        <div class="workflow-node-body">
                            <div class="workflow-node-flags">
                                <span class="workflow-node-state">{{ $naturalBranch['description'] ?? '' }}</span>
                            </div>

                            <div class="workflow-node-metrics">
                                <span class="desktop-chip">Entrada: {{ $naturalBranch['entry'] ?? '' }}</span>
                                <span class="desktop-chip">Saída: {{ $naturalBranch['exit'] ?? '' }}</span>
                            </div>

                            <div class="workflow-node-destinations">
                                <strong>Por que fica fora do caminho feliz</strong>
                                <div class="workflow-node-destination-list">
                                    <div class="workflow-node-destination">
                                        <span class="workflow-node-empty">{{ $naturalBranch['risk'] ?? '' }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    </section>

    <section class="workflow-diagram surface-card mb-4">
        <div class="surface-card-header">
            <div>
                <p class="desktop-eyebrow">Mapa operacional</p>
                <h2 class="surface-title">Uma fila, um dono, uma próxima ação</h2>
                <p class="surface-subtitle mb-0">
                    O desenho abaixo organiza a assistência técnica como um funil saudável:
                    triagem rápida, garantia tratada como via prioritária, diagnóstico com decisão objetiva,
                    execução com WIP limitado e encerramento sem esconder pendências.
                </p>
            </div>

            <div class="workflow-legend">
                <span class="workflow-legend-item">Fila única</span>
                <span class="workflow-legend-item is-transition">WIP limitado</span>
                <span class="workflow-legend-item is-pause">Pausa com revisão</span>
                <span class="workflow-legend-item is-final">Saída controlada</span>
            </div>
        </div>

        <div class="workflow-overview-grid">
            <div>
                <p class="workflow-overview-label">Trajeto ideal</p>
                <div class="workflow-trail">
                    @forelse ($workflowTrail as $trailLabel)
                        <span class="workflow-trail-step">{{ $trailLabel }}</span>
                        @if (! $loop->last)
                            <i class="bi bi-arrow-right workflow-trail-arrow" aria-hidden="true"></i>
                        @endif
                    @empty
                        <span class="workflow-trail-empty">Nenhuma etapa cadastrada.</span>
                    @endforelse
                </div>
            </div>

            <div class="workflow-stats-grid">
                @forelse ($visualSummary as $summary)
                    <article class="workflow-stat-card">
                        <span>{{ $summary['label'] ?? 'Indicador' }}</span>
                        <strong>{{ $summary['value'] ?? '-' }}</strong>
                        <small>{{ $summary['detail'] ?? '' }}</small>
                    </article>
                @empty
                    <article class="workflow-stat-card">
                        <span>Modelo</span>
                        <strong>OK</strong>
                        <small>Sem métricas disponíveis.</small>
                    </article>
                @endforelse
            </div>
        </div>

        <div class="workflow-lanes">
            @forelse ($workflowLanes as $lane)
                <section
                    class="workflow-lane"
                    style="--workflow-lane-accent: {{ $lane['accent'] ?? '#6f5afc' }}; --workflow-lane-soft-accent: {{ $lane['soft_accent'] ?? 'rgba(111, 90, 252, 0.12)' }};"
                >
                    <div class="workflow-lane-header">
                        <div>
                            <p class="desktop-eyebrow mb-1">{{ $lane['label'] ?? 'Fase' }}</p>
                            <h3 class="surface-title fs-5 mb-1">{{ $lane['label'] ?? 'Fase' }}</h3>
                            <p class="surface-subtitle mb-0">{{ $lane['description'] ?? '' }}</p>
                        </div>

                        <div class="workflow-lane-stats">
                            @foreach ($lane['chips'] ?? [] as $chip)
                                <span class="desktop-chip">{{ $chip }}</span>
                            @endforeach
                        </div>
                    </div>

                    <div class="workflow-lane-track">
                        @foreach ($lane['steps'] ?? [] as $step)
                            <article class="workflow-node" style="--workflow-node-accent: {{ $lane['accent'] ?? '#6f5afc' }};">
                                <div class="workflow-node-head">
                                    <div class="workflow-node-order">{{ $step['order'] ?? '00' }}</div>

                                    <div class="workflow-node-copy">
                                        <div class="workflow-node-title-row">
                                            <h4 class="workflow-node-title">{{ $step['title'] ?? '' }}</h4>
                                            <span class="workflow-node-state">{{ $step['timebox'] ?? 'sem prazo' }}</span>
                                        </div>
                                        <p class="workflow-node-code">{{ $step['code'] ?? '' }}</p>
                                    </div>
                                </div>

                                <div class="workflow-node-body">
                                    <div class="workflow-node-flags">
                                        <span class="workflow-node-state">Dono: {{ $step['owner'] ?? 'não definido' }}</span>
                                    </div>

                                    <div class="workflow-node-metrics">
                                        <span class="desktop-chip">Entrada: {{ $step['entry'] ?? 'não informada' }}</span>
                                        <span class="desktop-chip">Saída: {{ $step['exit'] ?? 'não informada' }}</span>
                                    </div>

                                    <div class="workflow-node-destinations">
                                        <strong>Risco evitado</strong>
                                        <div class="workflow-node-destination-list">
                                            <div class="workflow-node-destination">
                                                <span class="workflow-node-empty">{{ $step['risk'] ?? 'Sem risco descrito.' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>

                            @if (! $loop->last)
                                <div class="workflow-connector" aria-hidden="true">
                                    <span class="workflow-connector-line"></span>
                                    <i class="bi bi-arrow-right"></i>
                                    <span class="workflow-connector-line"></span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="workflow-lane-empty">Nenhuma fase disponível para exibição.</div>
            @endforelse
        </div>
    </section>

    <section class="surface-card mb-4">
        <div class="surface-card-header">
            <div>
                <p class="desktop-eyebrow">Princípios operacionais</p>
                <h2 class="surface-title">Regras que seguram a fila</h2>
                <p class="surface-subtitle mb-0">
                    O modelo funciona quando o time para de depender de memória e passa a operar com regra clara:
                    dono definido, prazo curto, prioridade real e escalonamento quando algo emperra.
                </p>
            </div>
        </div>

        <div class="row g-3">
            @foreach ($modelPillars as $pillar)
                <div class="col-12 col-md-6 col-xl-4">
                    <article class="surface-card h-100 mb-0">
                        <div class="surface-card-header">
                            <div>
                                <p class="desktop-eyebrow mb-1"><i class="bi {{ $pillar['icon'] ?? 'bi-check2-circle' }} me-2"></i>{{ $pillar['title'] ?? '' }}</p>
                                <h3 class="surface-title fs-5 mb-0">{{ $pillar['title'] ?? '' }}</h3>
                            </div>
                        </div>
                        <p class="surface-subtitle mb-0">{{ $pillar['description'] ?? '' }}</p>
                    </article>
                </div>
            @endforeach
        </div>
    </section>

    <section class="surface-table mb-4">
        <div class="surface-table-header">
            <div>
                <p class="desktop-eyebrow mb-1">Regras da fila</p>
                <h2 class="surface-title">Anti-gargalo e anti-procrastinação</h2>
                <p class="surface-subtitle mb-0">
                    Essas regras impedem o comportamento mais comum de assistência técnica ruim:
                    muita OS aberta, pouca clareza, retorno sem prazo e produção escondida atrás de “está olhando”.
                </p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                <tr>
                    <th>Regra</th>
                    <th>Como funciona</th>
                    <th>Efeito</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($queueRules as $rule)
                    <tr>
                        <td data-label="Regra">
                            <div class="fw-semibold">
                                <i class="bi {{ $rule['icon'] ?? 'bi-dot' }} me-2"></i>
                                {{ $rule['title'] ?? '' }}
                            </div>
                        </td>
                        <td data-label="Como funciona">{{ $rule['rule'] ?? '' }}</td>
                        <td data-label="Efeito">{{ $rule['impact'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="surface-card">
        <div class="surface-card-header">
            <div>
                <p class="desktop-eyebrow">Casos especiais</p>
                <h2 class="surface-title">Saídas controladas que não quebram a produção</h2>
                <p class="surface-subtitle mb-0">
                    Exceções precisam ficar visíveis. O problema não é existir exceção, é ela sumir na fila como se estivesse “em andamento”.
                </p>
            </div>
        </div>

        <div class="row g-3">
            @foreach ($specialCases as $case)
                <div class="col-12 col-md-6 col-xl-3">
                    <article class="workflow-node h-100" style="--workflow-node-accent: {{ $case['color'] ?? '#6f5afc' }};">
                        <div class="workflow-node-head">
                            <div class="workflow-node-order">
                                <i class="bi {{ $case['icon'] ?? 'bi-signpost-split' }}"></i>
                            </div>
                            <div class="workflow-node-copy">
                                <div class="workflow-node-title-row">
                                    <h4 class="workflow-node-title">{{ $case['title'] ?? '' }}</h4>
                                </div>
                                <p class="workflow-node-code">{{ $case['entry'] ?? '' }}</p>
                            </div>
                        </div>

                        <div class="workflow-node-body">
                            <div class="workflow-node-flags">
                                <span class="workflow-node-state">{{ $case['rule'] ?? '' }}</span>
                            </div>

                            <div class="workflow-node-destinations">
                                <strong>Saída esperada</strong>
                                <div class="workflow-node-destination-list">
                                    <div class="workflow-node-destination">
                                        <span class="workflow-node-empty">{{ $case['exit'] ?? '' }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    </section>
@endsection
