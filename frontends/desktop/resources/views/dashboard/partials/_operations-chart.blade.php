<article class="dashboard-panel dashboard-panel-lg" data-dashboard-monthly-panel>
    <div class="dashboard-panel-head">
        <div>
            <h2 data-dashboard-monthly-title>OS abertas x entregues reparadas</h2>
            <p data-dashboard-monthly-subtitle>Comparativo mensal do fluxo operacional do novo ERP.</p>
        </div>

        <div class="dashboard-filter-inline">
            <label for="dashboardYear">Ano</label>
            <select id="dashboardYear" class="form-select form-select-sm" data-dashboard-year-filter>
                @foreach ($years as $year)
                    <option value="{{ $year }}" @selected((int) $year === (int) ($filters['year'] ?? $year))>
                        {{ $year }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="dashboard-chart-wrap dashboard-chart-wrap-lg" data-dashboard-monthly-chart-wrap>
        <canvas id="dashboardMonthlyChart" aria-label="OS abertas x entregues reparadas por mês" role="img"></canvas>
    </div>

    <div class="dashboard-chart-legend-row" data-dashboard-monthly-legend></div>
</article>
