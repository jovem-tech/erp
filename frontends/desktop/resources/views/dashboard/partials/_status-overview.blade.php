<article class="dashboard-panel" data-dashboard-status-panel>
    <div class="dashboard-panel-head">
        <div>
            <h2>OS por status</h2>
            <p>Agrupado por macrofase. Clique para abrir a lista filtrada.</p>
        </div>
        <span class="dashboard-panel-total" data-dashboard-status-total></span>
    </div>

    {{-- O donut passa a desenhar as macrofases (grupo_macro), não os catorze
         status individuais. É agrupamento apenas visual: os status do catálogo
         seguem intactos no banco, no payload (`items`) e no detalhamento abaixo
         da legenda. Antes, a legenda era uma parede de catorze linhas que
         ninguém lê de relance. --}}
    <div class="dashboard-status-chart-layout">
        <div class="dashboard-chart-wrap dashboard-chart-wrap-donut">
            <canvas id="dashboardStatusChart" aria-label="Distribuição de OS por macrofase" role="img"></canvas>
        </div>

        <div class="dashboard-status-legend" data-dashboard-status-legend></div>
    </div>
</article>
