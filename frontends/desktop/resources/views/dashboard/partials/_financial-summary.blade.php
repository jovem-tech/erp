<article class="dashboard-panel" data-dashboard-context-panel>
    <div class="dashboard-panel-head">
        <div>
            <h2 data-dashboard-context-title>{{ $contextCard['title'] ?? 'Resumo financeiro' }}</h2>
            <p data-dashboard-context-subtitle>{{ $contextCard['subtitle'] ?? 'Comparativo operacional do mês corrente.' }}</p>
        </div>
    </div>

    {{-- Resultado de caixa em destaque: é a resposta à pergunta "como está o
         caixa?". Receitas, despesas e pendentes continuam visíveis logo abaixo,
         como leitura secundária. --}}
    <div class="dashboard-context-headline" data-dashboard-context-headline>
        <span class="dashboard-kpi-label" data-dashboard-context-headline-label>Resultado de caixa</span>
        <strong class="dashboard-context-headline-value" data-dashboard-context-headline-value>
            <span class="dashboard-skeleton dashboard-skeleton-value"></span>
        </strong>
    </div>

    <div class="dashboard-chart-wrap dashboard-chart-wrap-context">
        <canvas id="dashboardContextChart" aria-label="{{ $contextCard['title'] ?? 'Resumo do contexto' }}" role="img"></canvas>
    </div>

    <div class="dashboard-chart-legend-row" data-dashboard-context-legend></div>

    <div class="dashboard-context-metrics" data-dashboard-context-metrics></div>
</article>
