/**
 * Fábricas de gráfico do dashboard.
 *
 * Separado de dashboard.js porque os dois arquivos mudam por motivos
 * diferentes: aqui mora a configuração do Chart.js (escalas, tooltip, cores),
 * lá mora o ciclo de fetch e a montagem do HTML dos painéis.
 */
(() => {
    const instances = {
        monthly: null,
        status: null,
        equipment: null,
        context: null,
    };

    const formatNumber = (value) => new Intl.NumberFormat('pt-BR', {
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));

    const destroy = (key) => {
        if (instances[key]) {
            instances[key].destroy();
            instances[key] = null;
        }
    };

    const baseTooltip = {
        backgroundColor: '#1f2937',
        titleColor: '#fff',
        bodyColor: '#fff',
        padding: 10,
        displayColors: true,
    };

    /**
     * Cor de fundo real do card, lida do tema ativo. Era um '#f8fafc' fixo, que
     * desenhava um vinco claro entre os segmentos empilhados em qualquer tema
     * escuro — o separador precisa ser a cor da superfície, não branco.
     */
    const surfaceColor = () => {
        const value = getComputedStyle(document.documentElement)
            .getPropertyValue('--desktop-surface')
            .trim();

        return value !== '' ? value : '#f8fafc';
    };

    const navigateTo = (url) => {
        if (typeof url !== 'string' || url === '') {
            return;
        }

        // Sem esta marcação o guard de sessão interpreta a saída da página como
        // "navegador fechado" e desloga o usuário no meio do clique.
        window.erpMarkInternalNavigation?.();
        window.location.assign(url);
    };

    const createLineChart = (canvas, summary) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const points = Array.isArray(summary?.points) ? summary.points : [];
        const labels = points.map((point) => point.label || '');
        const series = Array.isArray(summary?.series) ? summary.series : [];

        destroy('monthly');
        instances.monthly = new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: series.map((item) => ({
                    label: item.label || '',
                    data: Array.isArray(item.data) ? item.data : [],
                    borderColor: item.color || '#6f5afc',
                    backgroundColor: item.backgroundColor || 'rgba(111, 90, 252, 0.18)',
                    tension: 0.38,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: item.color || '#6f5afc',
                    borderWidth: 3,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...baseTooltip,
                        callbacks: {
                            label: (context) => ` ${context.dataset.label}: ${formatNumber(context.parsed.y)}`,
                            // O saldo é a leitura que interessa ao gestor: entrou
                            // mais do que saiu neste mês, ou o contrário? Ler isso
                            // subtraindo duas linhas de olho é trabalho manual.
                            footer: (items) => {
                                if (items.length < 2) {
                                    return '';
                                }

                                const opened = Number(items[0]?.parsed?.y ?? 0);
                                const delivered = Number(items[1]?.parsed?.y ?? 0);
                                const balance = opened - delivered;

                                return `Saldo: ${balance >= 0 ? '+' : ''}${formatNumber(balance)}`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#94a3b8' } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.14)' },
                        ticks: { color: '#94a3b8', precision: 0 },
                    },
                },
            },
        });
    };

    /**
     * Donut das macrofases. Recebe `groups` (agrupamento visual montado no
     * DashboardService) e navega para a listagem de OS filtrada no clique.
     */
    const createDoughnutChart = (canvas, groups) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const items = Array.isArray(groups) ? groups : [];
        const total = items.reduce((sum, item) => sum + Number(item.total ?? 0), 0);

        destroy('status');
        instances.status = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: items.map((item) => item.nome || 'Sem status'),
                datasets: [
                    {
                        data: items.map((item) => Number(item.total ?? 0)),
                        backgroundColor: items.map((item) => item.cor || '#6f5afc'),
                        borderWidth: 0,
                        hoverOffset: 6,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                onHover: (event, elements) => {
                    const target = event?.native?.target;
                    if (target) {
                        target.style.cursor = elements.length > 0 && items[elements[0].index]?.url
                            ? 'pointer'
                            : 'default';
                    }
                },
                onClick: (event, elements) => {
                    if (elements.length === 0) {
                        return;
                    }

                    navigateTo(items[elements[0].index]?.url);
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...baseTooltip,
                        callbacks: {
                            label: (context) => {
                                const value = Number(context.parsed ?? 0);
                                const share = total > 0 ? (value / total) * 100 : 0;

                                return ` ${formatNumber(value)} OS · ${share.toFixed(1).replace('.', ',')}%`;
                            },
                        },
                    },
                },
            },
        });
    };

    const createStackedBarChart = (canvas, summary) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const labels = Array.isArray(summary?.labels) ? summary.labels : [];
        const series = Array.isArray(summary?.series) ? summary.series : [];
        const stackSurfaceColor = surfaceColor();
        const lastSeriesIndex = series.length - 1;

        destroy('equipment');
        instances.equipment = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: series.map((item, seriesIndex) => ({
                    label: item.label || 'Sem tipo',
                    data: Array.isArray(item.data) ? item.data.map((value) => Number(value ?? 0)) : [],
                    backgroundColor: item.backgroundColor || item.color || '#3b82f6',
                    borderColor: stackSurfaceColor,
                    borderWidth: 2,
                    // Só o topo da pilha arredonda; arredondar cada segmento faria
                    // a coluna parecer uma sequência de pílulas soltas.
                    borderRadius: seriesIndex === lastSeriesIndex
                        ? { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 }
                        : 0,
                    borderSkipped: false,
                    maxBarThickness: 24,
                    stack: 'equipment',
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...baseTooltip,
                        // Sem itemSort/filter o tooltip lista os nove tipos, quase
                        // todos zerados, e cobre metade do gráfico.
                        filter: (context) => Number(context.parsed?.y ?? 0) > 0,
                        callbacks: {
                            label: (context) => ` ${context.dataset.label}: ${formatNumber(context.parsed.y)} OS`,
                            footer: (items) => {
                                const total = items.reduce((sum, item) => sum + Number(item.parsed?.y ?? 0), 0);

                                return `Total: ${formatNumber(total)} OS`;
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { color: '#94a3b8' } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.14)' },
                        ticks: { color: '#94a3b8', precision: 0 },
                    },
                },
            },
        });
    };

    const createBarChart = (canvas, summary, options = {}) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const labels = Array.isArray(summary?.labels) ? summary.labels : [];
        const data = (Array.isArray(summary?.values) ? summary.values : []).map((value) => Number(value ?? 0));

        destroy('context');
        instances.context = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: options.label || 'Valores',
                        data,
                        backgroundColor: options.color || '#3b82f6',
                        borderRadius: 10,
                        maxBarThickness: options.maxBarThickness || 30,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: options.indexAxis || 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...baseTooltip,
                        callbacks: {
                            label: (context) => ` ${options.formatValue
                                ? options.formatValue(context.parsed.x ?? context.parsed.y)
                                : formatNumber(context.parsed.x ?? context.parsed.y)}`,
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.14)' },
                        ticks: { color: '#94a3b8' },
                    },
                    y: { grid: { display: false }, ticks: { color: '#94a3b8' } },
                },
            },
        });
    };

    window.DesktopDashboardCharts = {
        createLineChart,
        createDoughnutChart,
        createStackedBarChart,
        createBarChart,
        navigateTo,
    };
})();
