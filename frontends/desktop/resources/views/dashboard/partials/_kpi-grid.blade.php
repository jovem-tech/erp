<section class="dashboard-kpi-grid mb-4" data-dashboard-kpi-grid>
    <article class="dashboard-kpi-card" data-dashboard-open-orders-card style="--dashboard-accent: #6f5afc;">
        <span class="dashboard-kpi-label">OS abertas</span>
        <div class="dashboard-kpi-value" data-dashboard-open-orders-value>
            <span class="dashboard-skeleton dashboard-skeleton-value"></span>
        </div>
        <p class="dashboard-kpi-copy">Atendimentos em andamento sincronizados com a API central.</p>
        <i class="bi bi-clipboard-check-fill dashboard-kpi-icon"></i>
    </article>

    <article class="dashboard-kpi-card" data-dashboard-hero-card style="--dashboard-accent: {{ $heroCard['accent'] ?? '#16a34a' }};">
        <span class="dashboard-kpi-label" data-dashboard-hero-label>{{ $heroCard['label'] ?? 'Resumo operacional' }}</span>
        <div class="dashboard-kpi-value" data-dashboard-hero-value>
            <span class="dashboard-skeleton dashboard-skeleton-value"></span>
        </div>
        {{-- Só o faturamento ganha comparação: é a única métrica com mês
             anterior real no payload (stats.faturamento_mes_anterior). Inventar
             uma variação para OS abertas ou resultado de caixa seria fabricar
             número em cima de dado que o backend não calcula.

             O valor é faturamento por competência (OS entregues + vendas +
             serviços), não o caixa do mês: a legenda logo abaixo mostra quanto
             disso já foi recebido, e os dois divergirem é o esperado. --}}
        <p class="dashboard-kpi-trend" data-dashboard-hero-trend hidden></p>
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

    {{-- Alterna entre "Despesas pagas" (regime de caixa) e "Equipamento
         entregue" conforme has_financial_access — mesmo critério do hero card
         logo acima. Só o valor nasce como skeleton: rótulo/copy/ícone chegam
         prontos do primeiro render (default) e o JS os substitui depois. --}}
    <article class="dashboard-kpi-card" data-dashboard-secondary-card style="--dashboard-accent: {{ $secondaryCard['accent'] ?? '#f59e0b' }};">
        <span class="dashboard-kpi-label" data-dashboard-secondary-label>{{ $secondaryCard['label'] ?? 'Equipamento entregue' }}</span>
        <div class="dashboard-kpi-value" data-dashboard-secondary-value>
            <span class="dashboard-skeleton dashboard-skeleton-value"></span>
        </div>
        <p class="dashboard-kpi-trend" data-dashboard-secondary-trend hidden></p>
        <p class="dashboard-kpi-copy" data-dashboard-secondary-meta>{{ $secondaryCard['meta'] ?? 'Ordens concluídas e baixadas com entrega técnica registrada.' }}</p>
        <i class="bi {{ $secondaryCard['icon'] ?? 'bi-box2-heart-fill' }} dashboard-kpi-icon" data-dashboard-secondary-icon></i>
    </article>

    <article class="dashboard-kpi-card dashboard-summary-card" data-dashboard-summary-card style="--dashboard-accent: #4f46e5;">
        <div class="dashboard-kpi-summary-head">
            <span class="dashboard-kpi-label">Resumo geral</span>
            <i class="bi bi-building dashboard-kpi-icon"></i>
        </div>
        <div class="dashboard-summary-list">
            <div class="dashboard-summary-item">
                <span>Equipamentos</span>
                <strong data-dashboard-summary-equipments><span class="dashboard-skeleton dashboard-skeleton-inline"></span></strong>
            </div>
            <div class="dashboard-summary-item">
                <span>Clientes</span>
                <strong data-dashboard-summary-clients><span class="dashboard-skeleton dashboard-skeleton-inline"></span></strong>
            </div>
            <div class="dashboard-summary-item">
                <span>OS total</span>
                <strong data-dashboard-summary-total-os><span class="dashboard-skeleton dashboard-skeleton-inline"></span></strong>
            </div>
        </div>
    </article>
</section>
