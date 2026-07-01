@extends('layouts.app')

@section('content')
    @php
        $dashboard = is_array($dashboard ?? null) ? $dashboard : [];
        $stats = is_array($dashboard['stats'] ?? null) ? $dashboard['stats'] : [];
        $heroCard = is_array($dashboard['heroCard'] ?? null) ? $dashboard['heroCard'] : [];
        $contextCard = is_array($dashboard['contextCard'] ?? null) ? $dashboard['contextCard'] : [];
        $charts = is_array($dashboard['charts'] ?? null) ? $dashboard['charts'] : [];
        $filters = is_array($dashboard['filters'] ?? null) ? $dashboard['filters'] : [];
        $recentOrders = is_array($dashboard['recentOrders'] ?? null) ? $dashboard['recentOrders'] : [];
        $lowStock = is_array($dashboard['lowStock'] ?? null) ? $dashboard['lowStock'] : [];
        $canCreateOrder = \App\Support\DesktopSession::can('os', 'criar');
        $canViewOrders = \App\Support\DesktopSession::can('os', 'visualizar');

        $formatNumber = static fn ($value): string => number_format((float) $value, 0, ',', '.');
        $formatMoney = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
        $heroValueType = (string) ($heroCard['value_type'] ?? 'text');
        $heroValue = $heroCard['value'] ?? '';
        $monthLabels = is_array($filters['months'] ?? null) ? $filters['months'] : [];
        $years = is_array($filters['years'] ?? null) ? $filters['years'] : [];
        $equipmentYears = is_array($filters['equipmentYears'] ?? null) ? $filters['equipmentYears'] : [];
        $contextLegend = is_array($contextCard['legend'] ?? null) ? $contextCard['legend'] : [];
        $financialChart = is_array($charts['financial'] ?? null) ? $charts['financial'] : [];
        $technicianChart = is_array($charts['technician'] ?? null) ? $charts['technician'] : [];
        $statusChart = is_array($charts['status'] ?? null) ? $charts['status'] : [];
        $monthlyChart = is_array($charts['monthly'] ?? null) ? $charts['monthly'] : [];
        $equipmentChart = is_array($charts['equipmentTypes'] ?? null) ? $charts['equipmentTypes'] : [];
    @endphp

    <section class="desktop-page-hero dashboard-page-hero">
        <div class="desktop-page-hero-copy">
            <h2>Dashboard</h2>
            <p>Prioridades e atalhos do dia com a mesma linguagem visual do legado.</p>
        </div>

        <div class="desktop-hero-actions">
            <a href="{{ route('dashboard.help') }}" class="btn btn-outline-light">
                <i class="bi bi-question-circle me-1"></i>
                Ajuda do dashboard
            </a>
        </div>
    </section>

    <section class="dashboard-kpi-grid mb-4" data-dashboard-kpi-grid>
        <article class="dashboard-kpi-card" data-dashboard-open-orders-card style="--dashboard-accent: #6f5afc;">
            <span class="dashboard-kpi-label">OS abertas</span>
            <div class="dashboard-kpi-value" data-dashboard-open-orders-value>{{ $formatNumber($stats['orders'] ?? 0) }}</div>
            <p class="dashboard-kpi-copy">Atendimentos em andamento sincronizados com a API central.</p>
            <i class="bi bi-clipboard-check-fill dashboard-kpi-icon"></i>
        </article>

        <article class="dashboard-kpi-card" data-dashboard-hero-card style="--dashboard-accent: {{ $heroCard['accent'] ?? '#16a34a' }};">
            <span class="dashboard-kpi-label" data-dashboard-hero-label>{{ $heroCard['label'] ?? 'Resumo operacional' }}</span>
            <div class="dashboard-kpi-value {{ $heroValueType === 'text' ? 'dashboard-kpi-value-text' : '' }}" data-dashboard-hero-value>
                {{ $heroValueType === 'money' ? $formatMoney($heroValue) : ($heroValue !== '' ? $heroValue : '—') }}
            </div>
            <p class="dashboard-kpi-copy" data-dashboard-hero-meta>{{ $heroCard['meta'] ?? 'Visão rápida do status atual do painel.' }}</p>
            <a
                href="{{ !empty($heroCard['action_url']) ? $heroCard['action_url'] : '#' }}"
                class="dashboard-kpi-action"
                data-dashboard-hero-action
                @if (empty($heroCard['action_url'])) hidden aria-hidden="true" tabindex="-1" @endif
            >
                {{ $heroCard['action_label'] ?? 'Abrir detalhe' }}
                <i class="bi bi-arrow-right-short"></i>
            </a>
            <i class="bi {{ $heroCard['icon'] ?? 'bi-graph-up-arrow' }} dashboard-kpi-icon" data-dashboard-hero-icon></i>
        </article>

        <article class="dashboard-kpi-card" data-dashboard-delivered-card style="--dashboard-accent: #f59e0b;">
            <span class="dashboard-kpi-label">Equipamento entregue</span>
            <div class="dashboard-kpi-value" data-dashboard-delivered-value>{{ $formatNumber($stats['equipamento_entregue_total'] ?? 0) }}</div>
            <p class="dashboard-kpi-copy">Ordens concluídas e baixadas com entrega técnica registrada.</p>
            <i class="bi bi-box2-heart-fill dashboard-kpi-icon"></i>
        </article>

        <article class="dashboard-kpi-card dashboard-summary-card" data-dashboard-summary-card style="--dashboard-accent: #4f46e5;">
            <div class="dashboard-kpi-summary-head">
                <span class="dashboard-kpi-label">Resumo geral</span>
                <i class="bi bi-building dashboard-kpi-icon"></i>
            </div>
            <div class="dashboard-summary-list">
                <div class="dashboard-summary-item">
                    <span>Equipamentos</span>
                    <strong data-dashboard-summary-equipments>{{ $formatNumber($stats['equipments'] ?? 0) }}</strong>
                </div>
                <div class="dashboard-summary-item">
                    <span>Clientes</span>
                    <strong data-dashboard-summary-clients>{{ $formatNumber($stats['clients'] ?? 0) }}</strong>
                </div>
                <div class="dashboard-summary-item">
                    <span>OS total</span>
                    <strong data-dashboard-summary-total-os>{{ $formatNumber($stats['total_os'] ?? 0) }}</strong>
                </div>
            </div>
        </article>
    </section>

    <section class="dashboard-panel dashboard-panel-lg mb-4" data-dashboard-monthly-panel>
        <div class="dashboard-panel-head">
            <div>
                <h2 data-dashboard-monthly-title>OS abertas x entregues reparadas por mês</h2>
                <p data-dashboard-monthly-subtitle>Comparativo mensal do fluxo operacional do novo ERP.</p>
            </div>

            <div class="dashboard-filter-inline">
                <label for="dashboardYear">Ano</label>
                <select
                    id="dashboardYear"
                    class="form-select form-select-sm"
                    data-dashboard-year-filter
                >
                    @foreach ($years as $year)
                        <option value="{{ $year }}" @selected((int) $year === (int) ($filters['year'] ?? $year))>
                            {{ $year }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="dashboard-chart-wrap dashboard-chart-wrap-lg" data-dashboard-monthly-chart-wrap>
            <canvas
                id="dashboardMonthlyChart"
                aria-label="OS abertas x entregues reparadas por mês"
                role="img"
            ></canvas>
        </div>

        <div class="dashboard-chart-legend-row" data-dashboard-monthly-legend>
            @foreach (($monthlyChart['series'] ?? []) as $series)
                <span class="dashboard-chart-legend" style="--legend-color: {{ $series['color'] ?? '#6f5afc' }};">
                    {{ $series['label'] ?? 'Série' }}
                </span>
            @endforeach
        </div>
    </section>

    <section class="dashboard-secondary-grid mb-4">
        <article class="dashboard-panel" data-dashboard-status-panel>
            <div class="dashboard-panel-head">
                <div>
                    <h2>OS por status</h2>
                    <p>Distribuição resumida para leitura rápida.</p>
                </div>
            </div>

            <div class="dashboard-chart-wrap dashboard-chart-wrap-donut">
                <canvas
                    id="dashboardStatusChart"
                    aria-label="Distribuição de OS por status"
                    role="img"
                ></canvas>
            </div>

            <div class="dashboard-status-legend" data-dashboard-status-legend>
                @foreach (($statusChart['items'] ?? []) as $item)
                    <div class="dashboard-status-legend-item">
                        <span class="dashboard-status-dot" style="--dashboard-dot-color: {{ $item['cor'] ?? '#6f5afc' }};"></span>
                        <div>
                            <strong>{{ $item['nome'] ?? 'Sem status' }}</strong>
                            <small>{{ $formatNumber($item['total'] ?? 0) }} OS</small>
                        </div>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="dashboard-panel" data-dashboard-equipment-panel>
            <div class="dashboard-panel-head">
                <div>
                    <h2>Tipos de Equipamento</h2>
                    <p>OS por tipo no período selecionado.</p>
                </div>

                <div class="dashboard-filter-stack">
                    <div class="dashboard-filter-inline">
                        <label for="dashboardEquipmentMonth">Mês</label>
                        <select
                            id="dashboardEquipmentMonth"
                            class="form-select form-select-sm"
                            data-dashboard-equipment-month-filter
                        >
                            @foreach ($monthLabels as $monthNumber => $monthLabel)
                                <option value="{{ $monthNumber }}" @selected((int) $monthNumber === (int) ($filters['equipmentMonth'] ?? $monthNumber))>
                                    {{ $monthLabel }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="dashboard-filter-inline">
                        <label for="dashboardEquipmentYear">Ano</label>
                        <select
                            id="dashboardEquipmentYear"
                            class="form-select form-select-sm"
                            data-dashboard-equipment-year-filter
                        >
                            @foreach ($equipmentYears as $year)
                                <option value="{{ $year }}" @selected((int) $year === (int) ($filters['equipmentYear'] ?? $year))>
                                    {{ $year }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="dashboard-chart-wrap dashboard-chart-wrap-bars">
                <canvas
                    id="dashboardEquipmentChart"
                    aria-label="OS por tipo de equipamento"
                    role="img"
                ></canvas>
            </div>
        </article>

        <article class="dashboard-panel" data-dashboard-context-panel>
            <div class="dashboard-panel-head">
                <div>
                    <h2 data-dashboard-context-title>{{ $contextCard['title'] ?? 'Resumo financeiro' }}</h2>
                    <p data-dashboard-context-subtitle>{{ $contextCard['subtitle'] ?? 'Comparativo operacional do mês corrente.' }}</p>
                </div>
            </div>

            <div class="dashboard-chart-wrap dashboard-chart-wrap-context">
                <canvas
                    id="dashboardContextChart"
                    aria-label="{{ $contextCard['title'] ?? 'Resumo do contexto' }}"
                    role="img"
                ></canvas>
            </div>

            <div class="dashboard-chart-legend-row" data-dashboard-context-legend>
                @foreach ($contextLegend as $legend)
                    <span class="dashboard-chart-legend" style="--legend-color: {{ $legend['color'] ?? '#6f5afc' }};">
                        {{ $legend['label'] ?? 'Série' }}
                    </span>
                @endforeach
            </div>

            <div class="dashboard-context-metrics" data-dashboard-context-metrics>
                @if (($contextCard['type'] ?? '') === 'financial')
                    <div class="dashboard-context-metric">
                        <span>Receitas</span>
                        <strong>{{ $formatMoney($financialChart['receitas'] ?? 0) }}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Despesas</span>
                        <strong>{{ $formatMoney($financialChart['despesas'] ?? 0) }}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Resultado caixa</span>
                        <strong>{{ $formatMoney($financialChart['resultado_caixa'] ?? 0) }}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Pendentes</span>
                        <strong>{{ $formatMoney($financialChart['pendentes'] ?? 0) }}</strong>
                    </div>
                @else
                    <div class="dashboard-context-metric">
                        <span>Técnico destaque</span>
                        <strong>{{ $technicianChart['highlight_name'] ?? 'Nenhum técnico' }}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>OS em aberto</span>
                        <strong>{{ $formatNumber($technicianChart['highlight_total'] ?? 0) }}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Comissões</span>
                        <strong>{{ $formatMoney($technicianChart['commission_total'] ?? 0) }}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Período</span>
                        <strong>{{ ($technicianChart['month'] ?? date('n')) . '/' . ($technicianChart['year'] ?? date('Y')) }}</strong>
                    </div>
                @endif
            </div>
        </article>
    </section>

    <section class="dashboard-panel mb-4" data-dashboard-orders-panel>
        <div class="dashboard-panel-head dashboard-panel-head-end">
            <div>
                <h2>Últimas Ordens de Serviço</h2>
                <p>Visão operacional pronta para técnico e gestão.</p>
            </div>

            @if ($canCreateOrder)
                <a href="{{ route('orders.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>
                    Nova OS
                </a>
            @endif
        </div>

        <div data-dashboard-orders-slot>
            @if ($canViewOrders && $recentOrders !== [])
                <div class="table-responsive">
                    <table class="table table-stack align-middle dashboard-orders-table">
                        <thead>
                        <tr>
                            <th>No OS</th>
                            <th>Cliente</th>
                            <th>Equipamento</th>
                            <th>Status</th>
                            <th>Dias</th>
                            <th>Data</th>
                            <th class="text-end">Ação</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($recentOrders as $order)
                            @php
                                $orderId = (int) ($order['id'] ?? 0);
                                $orderNumber = (string) ($order['numero_os'] ?? '#');
                                $clienteNome = trim((string) data_get($order, 'cliente_nome', ''));
                                $equipamentoResumo = trim((string) data_get($order, 'equipamento_resumo_tecnico', data_get($order, 'equipamento.resumo_tecnico', data_get($order, 'equipamento_nome', ''))));
                                $statusNome = trim((string) data_get($order, 'status_nome', ''));
                                $statusCor = (string) data_get($order, 'status_cor', '#64748b');
                                $previewUrl = route('orders.preview', $orderId);
                                $fullUrl = route('orders.show', $orderId);
                            @endphp
                            <tr>
                                <td data-label="No OS">
                                    <a href="{{ $fullUrl }}" class="fw-semibold">{{ $orderNumber }}</a>
                                </td>
                                <td data-label="Cliente">
                                    {{ $clienteNome !== '' ? $clienteNome : 'Não informado' }}
                                </td>
                                <td data-label="Equipamento">
                                    {{ $equipamentoResumo !== '' ? $equipamentoResumo : 'Sem resumo' }}
                                </td>
                                <td data-label="Status">
                                    @include('layouts.partials.status-pill', [
                                        'label' => $statusNome !== '' ? $statusNome : 'Sem status',
                                        'color' => $statusCor,
                                    ])
                                </td>
                                <td data-label="Dias">
                                    <span class="dashboard-age-pill">{{ $formatNumber($order['dias_em_aberto'] ?? 0) }}d</span>
                                </td>
                                <td data-label="Data">{{ $order['data_label'] ?? 'Sem data' }}</td>
                                <td data-label="Ação" class="text-end">
                                    <div class="dashboard-order-actions">
                                        <button
                                            type="button"
                                            class="btn btn-soft btn-sm"
                                            data-os-modal-url="{{ $previewUrl }}"
                                            data-os-modal-title="{{ $orderNumber }}"
                                            data-os-open-full-url="{{ $fullUrl }}"
                                        >
                                            <i class="bi bi-eye me-1"></i>
                                            Abrir
                                        </button>
                                        <a href="{{ $fullUrl }}" class="btn btn-outline-light btn-sm">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-clipboard-x',
                    'title' => 'Sem ordens para mostrar',
                    'message' => 'Assim que a API retornar OS disponíveis para este usuário, elas aparecerão aqui.',
                ])
            @endif
        </div>
    </section>

    <section class="dashboard-panel dashboard-low-stock mb-4" data-dashboard-low-stock-panel>
        <div class="dashboard-panel-head dashboard-panel-head-end">
            <div>
                <h2>Alerta de estoque baixo</h2>
                <p>Itens críticos retornados pela API central para ação rápida.</p>
            </div>
        </div>

        <div data-dashboard-low-stock-slot>
            @if ($lowStock !== [])
                <div class="dashboard-low-stock-list">
                    @foreach ($lowStock as $item)
                        <article class="dashboard-low-stock-item">
                            <div>
                                <strong>{{ $item['nome'] ?? $item['produto_nome'] ?? 'Item sem nome' }}</strong>
                                <span>{{ $item['estoque_atual'] ?? $item['quantidade_atual'] ?? 0 }} em estoque</span>
                            </div>

                            @if (\App\Support\DesktopSession::can('estoque', 'editar') && !empty($item['edit_url']))
                                <a href="{{ $item['edit_url'] }}" class="btn btn-outline-light btn-sm">Editar</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            @else
                @include('layouts.partials.empty-state', [
                    'icon' => 'bi-box-seam',
                    'title' => 'Sem alertas de estoque',
                    'message' => 'Quando a API retornar itens críticos, eles aparecerão aqui.',
                ])
            @endif
        </div>
    </section>

    @php
        $dashboardBootstrap = [
            'dataUrl' => route('dashboard.data'),
        ];
    @endphp

    <script>
        window.__DESKTOP_DASHBOARD = {!! json_encode($dashboardBootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
    </script>
@endsection

@section('scripts')
    <script src="{{ asset('assets/libs/chartjs/chart.umd.min.js') }}"></script>
    <script src="{{ asset('assets/js/dashboard.js') }}"></script>
@endsection

@push('modals')
    <div class="modal fade" id="dashboardOsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
            <div class="modal-content modal-shell dashboard-os-modal-shell">
                <div class="modal-header">
                    <div>
                        <p class="desktop-eyebrow mb-1">Ordem de serviço</p>
                        <h5 class="modal-title" id="dashboardOsModalTitle">Pré-visualização</h5>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="#" id="dashboardOsModalOpenFull" target="_blank" rel="noreferrer" class="btn btn-soft btn-sm">
                            Abrir página cheia
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                </div>

                <div class="modal-body p-0 position-relative">
                    <div id="dashboardOsModalLoading" class="dashboard-os-modal-loading">
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        <span>Carregando OS...</span>
                    </div>
                    <iframe
                        id="dashboardOsModalFrame"
                        class="dashboard-os-modal-frame"
                        title="Pré-visualização da ordem de serviço"
                        loading="lazy"
                    ></iframe>
                </div>
            </div>
        </div>
    </div>
@endpush
