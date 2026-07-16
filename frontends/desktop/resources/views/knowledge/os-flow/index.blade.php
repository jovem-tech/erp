@extends('layouts.app')

@section('content')
    @php
        $canEdit = \App\Support\DesktopSession::can('conhecimento', 'editar');
        $canCreate = \App\Support\DesktopSession::can('conhecimento', 'criar');
        $workflowGroups = collect($workflowGroups ?? []);
        $workflowTrail = collect($workflowTrail ?? []);
        $workflowStats = array_merge([
            'status_count' => 0,
            'active_status_count' => 0,
            'inactive_status_count' => 0,
            'final_status_count' => 0,
            'pause_status_count' => 0,
            'group_count' => 0,
            'transition_count' => 0,
        ], $workflowStats ?? []);
        $activeStatuses = collect($activeStatuses ?? []);
        $transitionLookup = $transitionLookup ?? [];
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Gestão de Conhecimento</p>
            <h2 class="surface-title fs-3 mb-2">Fluxo de Trabalho OS</h2>
            <p class="surface-subtitle mb-0">
                Gerencie os status do fluxo de ordens de serviço e quais transições são permitidas entre eles.
            </p>
        </div>
    </div>

    <section class="workflow-diagram surface-card mb-4">
        <div class="surface-card-header">
            <div>
                <p class="desktop-eyebrow">Mapa visual do andamento</p>
                <h2 class="surface-title">Leitura operacional do fluxo</h2>
                <p class="surface-subtitle mb-0">
                    O backend grava <code>status</code> e <code>estado_fluxo</code> juntos na mudança da OS.
                    O diagrama abaixo mostra as macrofases ativas, a ordem dos status e as saídas permitidas
                    pela matriz oficial de transições.
                </p>
            </div>

            <div class="workflow-legend">
                <span class="workflow-legend-item">Ativo</span>
                <span class="workflow-legend-item is-final">Final</span>
                <span class="workflow-legend-item is-pause">Pausa</span>
                <span class="workflow-legend-item is-transition">Transição</span>
            </div>
        </div>

        <div class="workflow-overview-grid">
            <div>
                <p class="workflow-overview-label">Trajeto macro</p>
                <div class="workflow-trail">
                    @forelse ($workflowTrail as $trailLabel)
                        <span class="workflow-trail-step">{{ $trailLabel }}</span>
                        @if (! $loop->last)
                            <i class="bi bi-arrow-right workflow-trail-arrow" aria-hidden="true"></i>
                        @endif
                    @empty
                        <span class="workflow-trail-empty">Nenhuma macrofase encontrada.</span>
                    @endforelse
                </div>
            </div>

            <div class="workflow-stats-grid">
                <article class="workflow-stat-card">
                    <span>Status</span>
                    <strong>{{ (int) ($workflowStats['status_count'] ?? 0) }}</strong>
                    <small>catalogados no fluxo</small>
                </article>
                <article class="workflow-stat-card">
                    <span>Ativos</span>
                    <strong>{{ (int) ($workflowStats['active_status_count'] ?? 0) }}</strong>
                    <small>disponíveis para operação</small>
                </article>
                <article class="workflow-stat-card">
                    <span>Finais</span>
                    <strong>{{ (int) ($workflowStats['final_status_count'] ?? 0) }}</strong>
                    <small>encerram a OS</small>
                </article>
                <article class="workflow-stat-card">
                    <span>Transições</span>
                    <strong>{{ (int) ($workflowStats['transition_count'] ?? 0) }}</strong>
                    <small>rotas ativas na matriz</small>
                </article>
            </div>
        </div>

        <div class="workflow-lanes">
            @forelse ($workflowGroups as $group)
                <section
                    class="workflow-lane"
                    style="--workflow-lane-accent: {{ $group['accent'] ?? '#6f5afc' }}; --workflow-lane-soft-accent: {{ $group['soft_accent'] ?? 'rgba(111, 90, 252, 0.12)' }};"
                >
                    <div class="workflow-lane-header">
                        <div>
                            <p class="desktop-eyebrow mb-1">{{ $group['label'] ?? 'Sem grupo macro' }}</p>
                            <h3 class="surface-title fs-5 mb-1">{{ $group['label'] ?? 'Sem grupo macro' }}</h3>
                            <p class="surface-subtitle mb-0">{{ $group['description'] ?? 'Fase operacional agrupada por macroprocesso.' }}</p>
                        </div>

                        <div class="workflow-lane-stats">
                            <span class="desktop-chip">Status {{ (int) ($group['status_count'] ?? 0) }}</span>
                            <span class="desktop-chip">Ativos {{ (int) ($group['active_status_count'] ?? 0) }}</span>
                            <span class="desktop-chip">Final {{ (int) ($group['final_count'] ?? 0) }}</span>
                            <span class="desktop-chip">Pausa {{ (int) ($group['pause_count'] ?? 0) }}</span>
                            <span class="desktop-chip">Saídas {{ (int) ($group['transition_count'] ?? 0) }}</span>
                        </div>
                    </div>

                    <div class="workflow-lane-track">
                        @forelse ($group['statuses'] ?? [] as $status)
                            <article class="workflow-node" style="--workflow-node-accent: {{ $status['accent_color'] ?? '#6f5afc' }};">
                                <div class="workflow-node-head">
                                    <div class="workflow-node-order">{{ (int) ($status['ordem_fluxo'] ?? 0) }}</div>

                                    <div class="workflow-node-copy">
                                        <div class="workflow-node-title-row">
                                            <h4 class="workflow-node-title">{{ $status['nome'] ?? '' }}</h4>
                                            @include('layouts.partials.status-pill', [
                                                'label' => !empty($status['ativo']) ? 'Ativo' : 'Inativo',
                                                'color' => !empty($status['ativo']) ? '#29c384' : '#8b93a7',
                                                'small' => true,
                                            ])
                                        </div>
                                        <p class="workflow-node-code">{{ $status['codigo'] ?? '' }}</p>
                                    </div>
                                </div>

                                <div class="workflow-node-body">
                                    <div class="workflow-node-flags">
                                        @include('layouts.partials.status-pill', [
                                            'label' => !empty($status['status_final']) ? 'Final' : 'Operacional',
                                            'color' => !empty($status['status_final']) ? '#4da4ff' : '#8b93a7',
                                            'small' => true,
                                        ])
                                        @include('layouts.partials.status-pill', [
                                            'label' => !empty($status['status_pausa']) ? 'Pausa' : 'Fluxo',
                                            'color' => !empty($status['status_pausa']) ? '#ffb84d' : '#8b93a7',
                                            'small' => true,
                                        ])
                                        <span class="workflow-node-state">{{ $status['flow_state_label'] ?? 'Sem estado' }}</span>
                                    </div>

                                    <div class="workflow-node-metrics">
                                        <span class="desktop-chip">Saídas ativas {{ (int) ($status['outgoing_count'] ?? 0) }}</span>
                                        <span class="desktop-chip">Grupo {{ $group['label'] ?? 'Sem grupo macro' }}</span>
                                    </div>

                                    <div class="workflow-node-destinations">
                                        <strong>Próximos passos</strong>
                                        <div class="workflow-node-destination-list">
                                            @forelse ($status['outgoing_transitions'] ?? [] as $destination)
                                                <div class="workflow-node-destination">
                                                    @include('layouts.partials.status-pill', [
                                                        'label' => $destination['nome'] ?? '',
                                                        'color' => $destination['accent_color'] ?? '#6f5afc',
                                                        'small' => true,
                                                    ])
                                                    <small>{{ $destination['codigo'] ?? '' }}</small>
                                                </div>
                                            @empty
                                                <span class="workflow-node-empty">Sem transições ativas.</span>
                                            @endforelse
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
                            <div class="workflow-lane-empty">Nenhum status ativo nesta macrofase.</div>
                        @endforelse
                    </div>
                </section>
            @empty
                <div class="workflow-lane-empty">Nenhum status de fluxo foi retornado pela API central.</div>
            @endforelse
        </div>
    </section>

    @foreach ($workflowGroups as $group)
        <section class="surface-card mb-4">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">{{ $group['label'] ?? 'Sem grupo macro' }}</h2>
                    <p class="surface-subtitle">{{ $group['description'] ?? 'Status do fluxo agrupados pela fase macro.' }}</p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Ordem</th>
                        <th>Final</th>
                        <th>Pausa</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($group['statuses'] ?? [] as $status)
                        @php
                            $statusId = (int) ($status['id'] ?? 0);
                            $active = (bool) ($status['ativo'] ?? false);
                        @endphp
                        <tr>
                            <td colspan="5" class="p-0">
                                <details>
                                    <summary class="d-flex align-items-center gap-2 p-2">
                                        <span class="flex-fill">{{ $status['nome'] ?? '' }}</span>
                                        <span class="text-secondary">{{ (int) ($status['ordem_fluxo'] ?? 0) }}</span>
                                        <span>
                                            @include('layouts.partials.status-pill', [
                                                'label' => !empty($status['status_final']) ? 'Sim' : 'Não',
                                                'color' => !empty($status['status_final']) ? '#4da4ff' : '#8b93a7',
                                                'small' => true,
                                            ])
                                        </span>
                                        <span>
                                            @include('layouts.partials.status-pill', [
                                                'label' => !empty($status['status_pausa']) ? 'Sim' : 'Não',
                                                'color' => !empty($status['status_pausa']) ? '#ffb84d' : '#8b93a7',
                                                'small' => true,
                                            ])
                                        </span>
                                        <span>
                                            @include('layouts.partials.status-pill', [
                                                'label' => $active ? 'Ativo' : 'Inativo',
                                                'color' => $active ? '#29c384' : '#8b93a7',
                                                'small' => true,
                                            ])
                                        </span>
                                        @if ($canEdit)
                                            <i class="bi bi-pencil ms-2"></i>
                                        @endif
                                    </summary>

                                    <div class="p-3">
                                        <form
                                            method="post"
                                            action="{{ route('knowledge.os-flow.status.update', $statusId) }}"
                                            class="desktop-form-grid"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <div>
                                                <label for="statusCodigo{{ $statusId }}">Código</label>
                                                <input
                                                    type="text"
                                                    id="statusCodigo{{ $statusId }}"
                                                    class="form-control"
                                                    value="{{ $status['codigo'] ?? '' }}"
                                                    disabled
                                                    readonly
                                                >
                                                <div class="form-text">O código é imutável após a criação do status.</div>
                                            </div>

                                            <div>
                                                <label for="statusNome{{ $statusId }}">Nome *</label>
                                                <input
                                                    type="text"
                                                    id="statusNome{{ $statusId }}"
                                                    name="nome"
                                                    class="form-control"
                                                    value="{{ $status['nome'] ?? '' }}"
                                                    maxlength="120"
                                                    required
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusGrupoMacro{{ $statusId }}">Grupo macro *</label>
                                                <input
                                                    type="text"
                                                    id="statusGrupoMacro{{ $statusId }}"
                                                    name="grupo_macro"
                                                    class="form-control"
                                                    value="{{ $status['grupo_macro'] ?? '' }}"
                                                    maxlength="60"
                                                    required
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusIcone{{ $statusId }}">Ícone</label>
                                                <input
                                                    type="text"
                                                    id="statusIcone{{ $statusId }}"
                                                    name="icone"
                                                    class="form-control"
                                                    value="{{ $status['icone'] ?? '' }}"
                                                    maxlength="60"
                                                    placeholder="bi-clipboard-check"
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusCor{{ $statusId }}">Cor</label>
                                                <input
                                                    type="text"
                                                    id="statusCor{{ $statusId }}"
                                                    name="cor"
                                                    class="form-control"
                                                    value="{{ $status['cor'] ?? '' }}"
                                                    maxlength="30"
                                                    placeholder="#4da4ff"
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusOrdemFluxo{{ $statusId }}">Ordem no fluxo</label>
                                                <input
                                                    type="number"
                                                    id="statusOrdemFluxo{{ $statusId }}"
                                                    name="ordem_fluxo"
                                                    class="form-control"
                                                    value="{{ (int) ($status['ordem_fluxo'] ?? 0) }}"
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div>
                                                <label for="statusEstadoFluxoPadrao{{ $statusId }}">Estado de fluxo padrão</label>
                                                <input
                                                    type="text"
                                                    id="statusEstadoFluxoPadrao{{ $statusId }}"
                                                    name="estado_fluxo_padrao"
                                                    class="form-control"
                                                    value="{{ $status['estado_fluxo_padrao'] ?? '' }}"
                                                    maxlength="40"
                                                    @disabled(!$canEdit)
                                                >
                                            </div>

                                            <div class="col-span-full d-flex flex-wrap gap-4">
                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="status_final" value="0">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="status_final"
                                                        id="statusFinal{{ $statusId }}"
                                                        value="1"
                                                        @checked(!empty($status['status_final']))
                                                        @disabled(!$canEdit)
                                                    >
                                                    <label class="form-check-label" for="statusFinal{{ $statusId }}">Status final</label>
                                                </div>

                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="status_pausa" value="0">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="status_pausa"
                                                        id="statusPausa{{ $statusId }}"
                                                        value="1"
                                                        @checked(!empty($status['status_pausa']))
                                                        @disabled(!$canEdit)
                                                    >
                                                    <label class="form-check-label" for="statusPausa{{ $statusId }}">Status de pausa</label>
                                                </div>

                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="gera_evento_crm" value="0">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="gera_evento_crm"
                                                        id="statusGeraEventoCrm{{ $statusId }}"
                                                        value="1"
                                                        @checked(!empty($status['gera_evento_crm']))
                                                        @disabled(!$canEdit)
                                                    >
                                                    <label class="form-check-label" for="statusGeraEventoCrm{{ $statusId }}">Gera evento CRM</label>
                                                </div>

                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="ativo" value="0">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="ativo"
                                                        id="statusAtivo{{ $statusId }}"
                                                        value="1"
                                                        @checked($active)
                                                        @disabled(!$canEdit)
                                                    >
                                                    <label class="form-check-label" for="statusAtivo{{ $statusId }}">Status ativo</label>
                                                </div>
                                            </div>

                                            @if ($canEdit)
                                                <div class="col-span-full d-flex justify-content-end">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-save me-2"></i>
                                                        Salvar status
                                                    </button>
                                                </div>
                                            @endif
                                        </form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    @if ($canCreate)
        <section class="surface-card mb-4">
            <details>
                <summary class="d-flex align-items-center gap-2">
                    <i class="bi bi-plus-lg"></i>
                    <span>Novo status</span>
                </summary>

                <div class="p-3">
                    <form
                        method="post"
                        action="{{ route('knowledge.os-flow.status.store') }}"
                        class="desktop-form-grid"
                    >
                        @csrf

                        <div>
                            <label for="newStatusCodigo">Código *</label>
                            <input
                                type="text"
                                id="newStatusCodigo"
                                name="codigo"
                                class="form-control"
                                maxlength="80"
                                placeholder="ex.: aguardando_peca"
                                required
                            >
                            <div class="form-text">O código não poderá ser alterado após a criação.</div>
                        </div>

                        <div>
                            <label for="newStatusNome">Nome *</label>
                            <input type="text" id="newStatusNome" name="nome" class="form-control" maxlength="120" required>
                        </div>

                        <div>
                            <label for="newStatusGrupoMacro">Grupo macro *</label>
                            <input type="text" id="newStatusGrupoMacro" name="grupo_macro" class="form-control" maxlength="60" required>
                        </div>

                        <div>
                            <label for="newStatusIcone">Ícone</label>
                            <input type="text" id="newStatusIcone" name="icone" class="form-control" maxlength="60" placeholder="bi-clipboard-check">
                        </div>

                        <div>
                            <label for="newStatusCor">Cor</label>
                            <input type="text" id="newStatusCor" name="cor" class="form-control" maxlength="30" placeholder="#4da4ff">
                        </div>

                        <div>
                            <label for="newStatusOrdemFluxo">Ordem no fluxo</label>
                            <input type="number" id="newStatusOrdemFluxo" name="ordem_fluxo" class="form-control">
                        </div>

                        <div>
                            <label for="newStatusEstadoFluxoPadrao">Estado de fluxo padrão</label>
                            <input type="text" id="newStatusEstadoFluxoPadrao" name="estado_fluxo_padrao" class="form-control" maxlength="40">
                        </div>

                        <div class="col-span-full d-flex flex-wrap gap-4">
                            <div class="form-check form-switch">
                                <input type="hidden" name="status_final" value="0">
                                <input class="form-check-input" type="checkbox" name="status_final" id="newStatusFinal" value="1">
                                <label class="form-check-label" for="newStatusFinal">Status final</label>
                            </div>

                            <div class="form-check form-switch">
                                <input type="hidden" name="status_pausa" value="0">
                                <input class="form-check-input" type="checkbox" name="status_pausa" id="newStatusPausa" value="1">
                                <label class="form-check-label" for="newStatusPausa">Status de pausa</label>
                            </div>

                            <div class="form-check form-switch">
                                <input type="hidden" name="gera_evento_crm" value="0">
                                <input class="form-check-input" type="checkbox" name="gera_evento_crm" id="newStatusGeraEventoCrm" value="1" checked>
                                <label class="form-check-label" for="newStatusGeraEventoCrm">Gera evento CRM</label>
                            </div>

                            <div class="form-check form-switch">
                                <input type="hidden" name="ativo" value="0">
                                <input class="form-check-input" type="checkbox" name="ativo" id="newStatusAtivo" value="1" checked>
                                <label class="form-check-label" for="newStatusAtivo">Status ativo</label>
                            </div>
                        </div>

                        <div class="col-span-full d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg me-2"></i>
                                Criar status
                            </button>
                        </div>
                    </form>
                </div>
            </details>
        </section>
    @endif

    @php
        $transitionMatrix = $transitionMatrix ?? ['super_groups' => [], 'ordered_statuses' => []];
        $matrixSuperGroups = $transitionMatrix['super_groups'] ?? [];
        $orderedDestinos = $transitionMatrix['ordered_statuses'] ?? [];
        $matrixColCount = count($orderedDestinos);
    @endphp

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Matriz operacional de transições</h2>
                <p class="surface-subtitle">Marque para quais status cada situação pode avançar. Linhas e colunas estão agrupadas por super-grupo (Início / Execução / Término) e macrofase. A matriz alimenta o diagrama visual acima.</p>
            </div>
        </div>

        <form method="post" action="{{ route('knowledge.os-flow.transitions.update') }}">
            @csrf
            @method('PATCH')

            <div class="table-responsive matrix-scroll">
                <table class="table align-middle matrix-transitions">
                    <thead>
                    {{-- Faixa 1: super-grupos --}}
                    <tr>
                        <th rowspan="3" class="matrix-corner">Origem \ Destino</th>
                        @foreach ($matrixSuperGroups as $super)
                            <th
                                class="text-center matrix-supergroup"
                                colspan="{{ (int) ($super['span'] ?? 1) }}"
                                style="--matrix-accent: {{ $super['accent'] ?? '#6f5afc' }}; --matrix-soft-accent: {{ $super['soft_accent'] ?? 'rgba(111, 90, 252, 0.12)' }};"
                            >{{ $super['label'] ?? 'Grupo' }}</th>
                        @endforeach
                    </tr>
                    {{-- Faixa 2: macrofases --}}
                    <tr>
                        @foreach ($matrixSuperGroups as $super)
                            @foreach ($super['macros'] ?? [] as $macro)
                                <th
                                    class="text-center matrix-macro"
                                    colspan="{{ (int) ($macro['span'] ?? 1) }}"
                                    style="--matrix-accent: {{ $macro['accent'] ?? '#6f5afc' }}; --matrix-soft-accent: {{ $macro['soft_accent'] ?? 'rgba(111, 90, 252, 0.12)' }};"
                                >{{ $macro['label'] ?? 'Macrofase' }}</th>
                            @endforeach
                        @endforeach
                    </tr>
                    {{-- Faixa 3: status (colunas) --}}
                    <tr>
                        @foreach ($orderedDestinos as $destino)
                            <th class="text-center matrix-status-col">{{ $destino['nome'] }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($matrixSuperGroups as $super)
                        <tr class="matrix-band matrix-band-super" style="--matrix-accent: {{ $super['accent'] ?? '#6f5afc' }}; --matrix-soft-accent: {{ $super['soft_accent'] ?? 'rgba(111, 90, 252, 0.12)' }};">
                            <td colspan="{{ $matrixColCount + 1 }}">{{ $super['label'] ?? 'Grupo' }}</td>
                        </tr>
                        @foreach ($super['macros'] ?? [] as $macro)
                            <tr class="matrix-band matrix-band-macro" style="--matrix-accent: {{ $macro['accent'] ?? '#6f5afc' }}; --matrix-soft-accent: {{ $macro['soft_accent'] ?? 'rgba(111, 90, 252, 0.12)' }};">
                                <td colspan="{{ $matrixColCount + 1 }}">{{ $macro['label'] ?? 'Macrofase' }}</td>
                            </tr>
                            @foreach ($macro['statuses'] ?? [] as $origem)
                                <tr>
                                    <td class="matrix-row-head">
                                        <div class="fw-semibold">{{ $origem['nome'] }}</div>
                                        <small class="text-secondary">{{ $origem['codigo'] }}</small>
                                    </td>
                                    @foreach ($orderedDestinos as $destino)
                                        @if ($origem['id'] === $destino['id'])
                                            <td class="text-center matrix-diagonal">—</td>
                                        @else
                                            <td class="text-center">
                                                <input
                                                    type="checkbox"
                                                    class="form-check-input"
                                                    name="transitions[{{ $origem['id'] }}][]"
                                                    value="{{ $destino['id'] }}"
                                                    @checked(in_array($origem['id'] . '-' . $destino['id'], $transitionLookup, true))
                                                    @disabled(!$canEdit)
                                                >
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    @empty
                        <tr>
                            <td class="text-secondary">Nenhum status ativo para montar a matriz.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($canEdit)
                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>
                        Salvar matriz de transições
                    </button>
                </div>
            @endif
        </form>
    </section>
@endsection
