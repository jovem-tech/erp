(() => {
    const state = window.__DESKTOP_DASHBOARD || {};
    const dataUrl = state.dataUrl || '/dashboard/dados';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const monthlyCanvas = document.getElementById('dashboardMonthlyChart');
    const statusCanvas = document.getElementById('dashboardStatusChart');
    const equipmentCanvas = document.getElementById('dashboardEquipmentChart');
    const contextCanvas = document.getElementById('dashboardContextChart');
    const yearFilter = document.querySelector('[data-dashboard-year-filter]');
    const equipmentMonthFilter = document.querySelector('[data-dashboard-equipment-month-filter]');
    const equipmentYearFilter = document.querySelector('[data-dashboard-equipment-year-filter]');
    const monthlyLegend = document.querySelector('[data-dashboard-monthly-legend]');
    const statusLegend = document.querySelector('[data-dashboard-status-legend]');
    const contextLegend = document.querySelector('[data-dashboard-context-legend]');
    const monthlyChartWrap = document.querySelector('[data-dashboard-monthly-chart-wrap]');
    const openOrdersCard = document.querySelector('[data-dashboard-open-orders-card]');
    const heroCard = document.querySelector('[data-dashboard-hero-card]');
    const deliveredCard = document.querySelector('[data-dashboard-delivered-card]');
    const summaryCard = document.querySelector('[data-dashboard-summary-card]');
    const contextPanel = document.querySelector('[data-dashboard-context-panel]');
    const ordersSlot = document.querySelector('[data-dashboard-orders-slot]');
    const lowStockSlot = document.querySelector('[data-dashboard-low-stock-slot]');

    const chartInstances = {
        monthly: null,
        status: null,
        equipment: null,
        context: null,
    };

    const formatMoney = (value) => new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number(value ?? 0));

    const formatNumber = (value) => new Intl.NumberFormat('pt-BR', {
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    const destroyChart = (key) => {
        if (chartInstances[key]) {
            chartInstances[key].destroy();
            chartInstances[key] = null;
        }
    };

    const createLineChart = (canvas, summary) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const points = Array.isArray(summary?.points) ? summary.points : [];
        const labels = points.map((point) => point.label || '');
        const series = Array.isArray(summary?.series) ? summary.series : [];

        destroyChart('monthly');
        chartInstances.monthly = new Chart(canvas, {
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
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                    },
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                        ticks: {
                            color: '#94a3b8',
                        },
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(148, 163, 184, 0.14)',
                        },
                        ticks: {
                            color: '#94a3b8',
                            precision: 0,
                        },
                    },
                },
            },
        });
    };

    const createDoughnutChart = (canvas, summary) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const items = Array.isArray(summary?.items) ? summary.items : [];
        const labels = items.map((item) => item.nome || 'Sem status');
        const data = items.map((item) => Number(item.total ?? 0));
        const colors = items.map((item) => item.cor || '#6f5afc');

        destroyChart('status');
        chartInstances.status = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [
                    {
                        data,
                        backgroundColor: colors,
                        borderWidth: 0,
                        hoverOffset: 6,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                    },
                },
            },
        });
    };

    const createBarChart = (canvas, summary, options = {}) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const items = Array.isArray(summary?.items) ? summary.items : [];
        const labelsFromSummary = Array.isArray(summary?.labels) ? summary.labels : [];
        const valuesFromSummary = Array.isArray(summary?.values) ? summary.values : [];
        const labels = items.length > 0
            ? items.map((item) => item.tipo_nome || item.nome || 'Sem tipo')
            : labelsFromSummary;
        const data = items.length > 0
            ? items.map((item) => Number(item.total ?? item.value ?? 0))
            : valuesFromSummary.map((value) => Number(value ?? 0));

        destroyChart(options.key || 'equipment');
        chartInstances[options.key || 'equipment'] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: summary?.series?.[0]?.label || options.label || 'Valores',
                        data,
                        backgroundColor: summary?.series?.[0]?.backgroundColor || options.color || '#3b82f6',
                        borderRadius: 10,
                        maxBarThickness: options.maxBarThickness || 36,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: options.indexAxis || 'x',
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                    },
                },
                scales: options.indexAxis === 'y'
                    ? {
                        x: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(148, 163, 184, 0.14)',
                            },
                            ticks: {
                                color: '#94a3b8',
                                precision: 0,
                            },
                        },
                        y: {
                            grid: {
                                display: false,
                            },
                            ticks: {
                                color: '#94a3b8',
                            },
                        },
                    }
                    : {
                        x: {
                            grid: {
                                display: false,
                            },
                            ticks: {
                                color: '#94a3b8',
                            },
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(148, 163, 184, 0.14)',
                            },
                            ticks: {
                                color: '#94a3b8',
                                precision: 0,
                            },
                        },
                    },
            },
        });
    };

    const setSelectOptions = (select, options, selectedValue) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        const fragment = document.createDocumentFragment();
        options.forEach(({ value, label }) => {
            const option = document.createElement('option');
            option.value = String(value);
            option.textContent = String(label ?? value);
            fragment.appendChild(option);
        });

        select.replaceChildren(fragment);

        if (selectedValue !== undefined && selectedValue !== null && selectedValue !== '') {
            select.value = String(selectedValue);
        }
    };

    const buildEmptyState = (icon, title, message) => `
        <div class="empty-state-shell">
            <i class="bi ${escapeHtml(icon)}"></i>
            <h3>${escapeHtml(title)}</h3>
            <p>${escapeHtml(message)}</p>
        </div>
    `;

    const buildOrderUrl = (orderId, preview = false) => {
        const numericId = Number(orderId ?? 0);
        if (!Number.isFinite(numericId) || numericId <= 0) {
            return '#';
        }

        return new URL(`/os/${numericId}${preview ? '/preview' : ''}`, window.location.origin).toString();
    };

    const setMonthlyLoadingState = (isLoading) => {
        if (!monthlyChartWrap) {
            return;
        }

        monthlyChartWrap.classList.toggle('desktop-chart-placeholder', Boolean(isLoading));
    };

    const isJsonContentType = (contentType) => String(contentType || '').toLowerCase().includes('json');

    const renderKpiCards = (summary) => {
        const stats = summary?.stats || {};
        const hero = summary?.heroCard || {};

        if (openOrdersCard) {
            const value = openOrdersCard.querySelector('[data-dashboard-open-orders-value]');
            if (value) {
                value.textContent = formatNumber(stats.orders ?? 0);
            }
        }

        if (heroCard) {
            const label = heroCard.querySelector('[data-dashboard-hero-label]');
            const value = heroCard.querySelector('[data-dashboard-hero-value]');
            const meta = heroCard.querySelector('[data-dashboard-hero-meta]');
            const icon = heroCard.querySelector('[data-dashboard-hero-icon]');
            const action = heroCard.querySelector('[data-dashboard-hero-action]');

            heroCard.style.setProperty('--dashboard-accent', hero.accent || '#16a34a');

            if (label) {
                label.textContent = hero.label || 'Resumo operacional';
            }

            if (value) {
                const heroValue = hero.value_type === 'money'
                    ? formatMoney(hero.value ?? 0)
                    : String(hero.value ?? '').trim() || '—';
                value.classList.toggle('dashboard-kpi-value-text', hero.value_type === 'text');
                value.textContent = heroValue;
            }

            if (meta) {
                meta.textContent = hero.meta || 'Visão rápida do status atual do painel.';
            }

            if (icon) {
                icon.className = `bi ${hero.icon || 'bi-graph-up-arrow'} dashboard-kpi-icon`;
            }

            if (action) {
                const actionLabel = hero.action_label || 'Abrir detalhe';
                action.innerHTML = `${escapeHtml(actionLabel)} <i class="bi bi-arrow-right-short"></i>`;
                if (hero.action_url) {
                    action.href = hero.action_url;
                    action.hidden = false;
                    action.removeAttribute('aria-hidden');
                    action.removeAttribute('tabindex');
                } else {
                    action.hidden = true;
                    action.setAttribute('aria-hidden', 'true');
                    action.setAttribute('tabindex', '-1');
                    action.href = '#';
                }
            }
        }

        if (deliveredCard) {
            const value = deliveredCard.querySelector('[data-dashboard-delivered-value]');
            if (value) {
                value.textContent = formatNumber(stats.equipamento_entregue_total ?? 0);
            }
        }

        if (summaryCard) {
            const values = summaryCard.querySelectorAll('[data-dashboard-summary-equipments], [data-dashboard-summary-clients], [data-dashboard-summary-total-os]');
            if (values.length >= 3) {
                values[0].textContent = formatNumber(stats.equipments ?? 0);
                values[1].textContent = formatNumber(stats.clients ?? 0);
                values[2].textContent = formatNumber(stats.total_os ?? 0);
            }
        }
    };

    const renderContextLegend = (summary) => {
        if (!contextLegend) {
            return;
        }

        const items = Array.isArray(summary?.contextCard?.legend) ? summary.contextCard.legend : [];
        contextLegend.innerHTML = items.map((item) => `
            <span class="dashboard-chart-legend" style="--legend-color: ${escapeHtml(item.color || '#6f5afc')}">
                ${escapeHtml(item.label || 'Série')}
            </span>
        `).join('');
    };

    const renderContextCard = (summary) => {
        if (!contextPanel) {
            return;
        }

        const contextCardSummary = summary?.contextCard || {};
        const financial = summary?.charts?.financial || {};
        const technician = summary?.charts?.technician || {};
        const title = contextPanel.querySelector('[data-dashboard-context-title]');
        const subtitle = contextPanel.querySelector('[data-dashboard-context-subtitle]');
        const metrics = contextPanel.querySelector('[data-dashboard-context-metrics]');

        if (title) {
            title.textContent = contextCardSummary.title || 'Resumo financeiro';
        }

        if (subtitle) {
            subtitle.textContent = contextCardSummary.subtitle || 'Comparativo operacional do mês corrente.';
        }

        if (metrics) {
            if (contextCardSummary.type === 'financial') {
                metrics.innerHTML = `
                    <div class="dashboard-context-metric">
                        <span>Receitas</span>
                        <strong>${formatMoney(financial.receitas ?? 0)}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Despesas</span>
                        <strong>${formatMoney(financial.despesas ?? 0)}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Resultado caixa</span>
                        <strong>${formatMoney(financial.resultado_caixa ?? 0)}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Pendentes</span>
                        <strong>${formatMoney(financial.pendentes ?? 0)}</strong>
                    </div>
                `;
            } else {
                metrics.innerHTML = `
                    <div class="dashboard-context-metric">
                        <span>Técnico destaque</span>
                        <strong>${escapeHtml(technician.highlight_name || 'Nenhum técnico')}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>OS em aberto</span>
                        <strong>${formatNumber(technician.highlight_total ?? 0)}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Comissões</span>
                        <strong>${formatMoney(technician.commission_total ?? 0)}</strong>
                    </div>
                    <div class="dashboard-context-metric">
                        <span>Período</span>
                        <strong>${Number(technician.month ?? new Date().getMonth() + 1)}/${Number(technician.year ?? new Date().getFullYear())}</strong>
                    </div>
                `;
            }
        }
    };

    const renderOrders = (summary) => {
        if (!ordersSlot) {
            return;
        }

        const orders = Array.isArray(summary?.recentOrders) ? summary.recentOrders : [];
        if (orders.length === 0) {
            ordersSlot.innerHTML = buildEmptyState(
                'bi-clipboard-x',
                'Sem ordens para mostrar',
                'Assim que a API retornar OS disponíveis para este usuário, elas aparecerão aqui.'
            );
            return;
        }

        const rows = orders.map((order) => {
            const orderId = Number(order.id ?? 0);
            const orderNumber = escapeHtml(order.numero_os || '#');
            const clienteNome = escapeHtml(String(order.cliente_nome ?? '').trim() || 'Não informado');
            const equipamentoResumo = escapeHtml(String(order.equipamento_resumo_tecnico ?? order.equipamento_nome ?? '').trim() || 'Sem resumo');
            const statusNome = escapeHtml(String(order.status_nome ?? '').trim() || 'Sem status');
            const statusCor = escapeHtml(String(order.status_cor ?? '#64748b'));
            const dataLabel = escapeHtml(String(order.data_label ?? 'Sem data'));
            const days = formatNumber(order.dias_em_aberto ?? 0);
            const previewUrl = buildOrderUrl(orderId, true);
            const fullUrl = buildOrderUrl(orderId, false);

            return `
                <tr>
                    <td data-label="No OS">
                        <a href="${fullUrl}" class="fw-semibold">${orderNumber}</a>
                    </td>
                    <td data-label="Cliente">
                        ${clienteNome}
                    </td>
                    <td data-label="Equipamento">
                        ${equipamentoResumo}
                    </td>
                    <td data-label="Status">
                        <span class="status-pill" style="--status-color: ${statusCor}">
                            <span>${statusNome}</span>
                        </span>
                    </td>
                    <td data-label="Dias">
                        <span class="dashboard-age-pill">${days}d</span>
                    </td>
                    <td data-label="Data">${dataLabel}</td>
                    <td data-label="Ação" class="text-end">
                        <div class="dashboard-order-actions">
                            <button
                                type="button"
                                class="btn btn-soft btn-sm"
                                data-os-modal-url="${previewUrl}"
                                data-os-modal-title="${orderNumber}"
                                data-os-open-full-url="${fullUrl}"
                            >
                                <i class="bi bi-eye me-1"></i>
                                Abrir
                            </button>
                            <a href="${fullUrl}" class="btn btn-outline-light btn-sm">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        ordersSlot.innerHTML = `
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
                    ${rows}
                    </tbody>
                </table>
            </div>
        `;
    };

    const renderLowStock = (summary) => {
        if (!lowStockSlot) {
            return;
        }

        const lowStock = Array.isArray(summary?.lowStock) ? summary.lowStock : [];
        if (lowStock.length === 0) {
            lowStockSlot.innerHTML = buildEmptyState(
                'bi-box-seam',
                'Sem alertas de estoque',
                'Quando a API retornar itens críticos, eles aparecerão aqui.'
            );
            return;
        }

        lowStockSlot.innerHTML = `
            <div class="dashboard-low-stock-list">
                ${lowStock.map((item) => {
                    const title = escapeHtml(String(item.nome ?? item.produto_nome ?? 'Item sem nome'));
                    const quantity = formatNumber(item.estoque_atual ?? item.quantidade_atual ?? 0);
                    const editUrl = String(item.edit_url ?? '').trim();

                    return `
                        <article class="dashboard-low-stock-item">
                            <div>
                                <strong>${title}</strong>
                                <span>${quantity} em estoque</span>
                            </div>

                            ${editUrl !== '' ? `<a href="${escapeHtml(editUrl)}" class="btn btn-outline-light btn-sm">Editar</a>` : ''}
                        </article>
                    `;
                }).join('')}
            </div>
        `;
    };

    const applyFilterOptions = (filters) => {
        if (!filters || typeof filters !== 'object') {
            return;
        }

        if (yearFilter instanceof HTMLSelectElement) {
            const years = Array.isArray(filters.years) ? filters.years : [];
            if (years.length > 0) {
                setSelectOptions(yearFilter, years.map((year) => ({
                    value: year,
                    label: String(year),
                })), filters.year);
            }
        }

        if (equipmentMonthFilter instanceof HTMLSelectElement) {
            const months = filters.months && typeof filters.months === 'object'
                ? Object.entries(filters.months)
                : [];
            if (months.length > 0) {
                setSelectOptions(equipmentMonthFilter, months.map(([value, label]) => ({
                    value,
                    label,
                })), filters.equipmentMonth);
            }
        }

        if (equipmentYearFilter instanceof HTMLSelectElement) {
            const equipmentYears = Array.isArray(filters.equipmentYears) ? filters.equipmentYears : [];
            if (equipmentYears.length > 0) {
                setSelectOptions(equipmentYearFilter, equipmentYears.map((year) => ({
                    value: year,
                    label: String(year),
                })), filters.equipmentYear);
            }
        }
    };

    const renderMonthlyLegend = (summary) => {
        if (!monthlyLegend) {
            return;
        }

        const series = Array.isArray(summary?.series) ? summary.series : [];
        monthlyLegend.innerHTML = series.map((item) => `
            <span class="dashboard-chart-legend" style="--legend-color: ${item.color || '#6f5afc'};">
                ${item.label || 'Série'}
            </span>
        `).join('');
    };

    const renderStatusLegend = (summary) => {
        if (!statusLegend) {
            return;
        }

        const items = Array.isArray(summary?.items) ? summary.items : [];
        statusLegend.innerHTML = items.map((item) => `
            <div class="dashboard-status-legend-item">
                <span class="dashboard-status-dot" style="--dashboard-dot-color: ${item.cor || '#6f5afc'};"></span>
                <div>
                    <strong>${escapeHtml(item.nome || 'Sem status')}</strong>
                    <small>${formatNumber(item.total ?? 0)} OS</small>
                </div>
            </div>
        `).join('');
    };

    const applySummary = (summary) => {
        renderKpiCards(summary);
        renderContextCard(summary);
        renderContextLegend(summary);
        renderOrders(summary);
        renderLowStock(summary);
        createLineChart(monthlyCanvas, summary?.charts?.monthly);
        createDoughnutChart(statusCanvas, summary?.charts?.status);
        createBarChart(equipmentCanvas, summary?.charts?.equipmentTypes, {
            key: 'equipment',
            label: 'OS por tipo',
            color: ['#3b82f6', '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#64748b'],
            indexAxis: 'y',
            maxBarThickness: 42,
        });
        const contextColors = Array.isArray(summary?.contextCard?.legend)
            ? summary.contextCard.legend.map((item) => item.color || '#22c55e')
            : '#22c55e';
        createBarChart(contextCanvas, summary?.contextCard?.chart, {
            key: 'context',
            label: summary?.contextCard?.title || 'Resumo',
            color: contextColors,
            indexAxis: 'y',
            maxBarThickness: 30,
        });

        renderMonthlyLegend(summary?.charts?.monthly);
        renderStatusLegend(summary?.charts?.status);
        setMonthlyLoadingState(false);
    };

    const readFilters = () => ({
        ano: yearFilter instanceof HTMLSelectElement ? yearFilter.value : '',
        equip_mes: equipmentMonthFilter instanceof HTMLSelectElement ? equipmentMonthFilter.value : '',
        equip_ano: equipmentYearFilter instanceof HTMLSelectElement ? equipmentYearFilter.value : '',
    });

    const syncDashboard = async () => {
        const params = new URLSearchParams();
        const filters = readFilters();
        setMonthlyLoadingState(true);

        Object.entries(filters).forEach(([key, value]) => {
            if (value !== '') {
                params.set(key, value);
            }
        });

        try {
            const response = await fetch(`${dataUrl}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
                credentials: 'same-origin',
            });

            if (response.status === 401) {
                window.location.href = '/login';
                return;
            }

            if (response.status === 403) {
                window.location.href = '/dashboard';
                return;
            }

            if (!response.ok) {
                setMonthlyLoadingState(false);
                return;
            }

            const contentType = String(response.headers.get('content-type') || '');

            if (response.redirected || !isJsonContentType(contentType)) {
                setMonthlyLoadingState(false);

                console.warn('[DESKTOP][DASHBOARD] Resposta inesperada ao atualizar widgets.', {
                    status: response.status,
                    redirected: response.redirected,
                    contentType,
                    url: response.url,
                });

                if (response.redirected && response.url) {
                    window.location.href = response.url;
                }

                return;
            }

            const payload = await response.json();
            const summary = payload?.data || payload || {};

            window.__DESKTOP_DASHBOARD = {
                ...(window.__DESKTOP_DASHBOARD || {}),
                ...summary,
            };

            applyFilterOptions(summary?.filters);
            applySummary(summary);
        } catch (error) {
            setMonthlyLoadingState(false);

            if (window.DesktopUi && typeof window.DesktopUi.logError === 'function') {
                window.DesktopUi.logError('dashboard.syncDashboard', error);
            } else {
                console.error('[DESKTOP][DASHBOARD] Falha ao atualizar os widgets.', error);
            }
        }
    };

    const bindFilters = () => {
        [yearFilter, equipmentMonthFilter, equipmentYearFilter].forEach((element) => {
            if (!(element instanceof HTMLSelectElement)) {
                return;
            }

            if (typeof window.jQuery !== 'undefined' && window.jQuery.fn && typeof window.jQuery.fn.on === 'function') {
                window.jQuery(element).on('change', syncDashboard);
                return;
            }

            element.addEventListener('change', syncDashboard);
        });
    };

    const bootstrapDashboard = () => {
        bindFilters();
        setMonthlyLoadingState(true);
        syncDashboard();
    };

    document.addEventListener('DOMContentLoaded', bootstrapDashboard);
})();
