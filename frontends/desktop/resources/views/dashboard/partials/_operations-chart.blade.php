<article class="dashboard-panel dashboard-panel-lg" data-dashboard-monthly-panel>
    <div class="dashboard-panel-head">
        <div>
            <h2 data-dashboard-monthly-title>Operação e resultado financeiro</h2>
            {{-- O subtítulo diz de que lado está cada escala de propósito: o
                 gráfico tem dois eixos com unidades diferentes (contagem de OS e
                 reais), e sem essa indicação o leitor compara alturas que não
                 são comparáveis. --}}
            <p data-dashboard-monthly-subtitle>
                Mês a mês: volume de OS na escala à esquerda, faturamento e lucro líquido em reais à direita.
            </p>
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

    {{-- Legenda antes do canvas, mesmo lugar do gráfico "Evolução financeira"
         logo abaixo: com 4 séries em dois eixos, o leitor precisa saber o que
         está vendo ANTES de encarar o gráfico, não depois de rolar a tela. --}}
    <div class="dashboard-chart-legend-row" data-dashboard-monthly-legend></div>

    <div class="dashboard-chart-wrap dashboard-chart-wrap-lg" data-dashboard-monthly-chart-wrap>
        <canvas id="dashboardMonthlyChart"
                aria-label="OS abertas e entregues reparadas, faturamento e lucro líquido por mês"
                role="img"></canvas>
    </div>
</article>
