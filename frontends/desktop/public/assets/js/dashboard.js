/**
 * Hidratação do dashboard.
 *
 * O Blade renderiza a casca com skeletons e este arquivo preenche tudo a partir
 * de /dashboard/dados, numa única requisição agregada. As fábricas de gráfico
 * ficam em dashboard-charts.js.
 */
(() => {
    const state = window.__DESKTOP_DASHBOARD || {};
    const dataUrl = state.dataUrl || '/dashboard/dados';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const charts = window.DesktopDashboardCharts || {};

    const el = (selector) => document.querySelector(selector);

    const nodes = {
        monthlyCanvas: document.getElementById('dashboardMonthlyChart'),
        statusCanvas: document.getElementById('dashboardStatusChart'),
        equipmentCanvas: document.getElementById('dashboardEquipmentChart'),
        contextCanvas: document.getElementById('dashboardContextChart'),
        yearFilter: el('[data-dashboard-year-filter]'),
        equipmentYearFilter: el('[data-dashboard-equipment-year-filter]'),
        monthlyLegend: el('[data-dashboard-monthly-legend]'),
        statusLegend: el('[data-dashboard-status-legend]'),
        statusTotal: el('[data-dashboard-status-total]'),
        equipmentLegend: el('[data-dashboard-equipment-legend]'),
        contextLegend: el('[data-dashboard-context-legend]'),
        monthlyChartWrap: el('[data-dashboard-monthly-chart-wrap]'),
        attentionSlot: el('[data-dashboard-attention-slot]'),
        openOrdersCard: el('[data-dashboard-open-orders-card]'),
        heroCard: el('[data-dashboard-hero-card]'),
        secondaryCard: el('[data-dashboard-secondary-card]'),
        summaryCard: el('[data-dashboard-summary-card]'),
        contextPanel: el('[data-dashboard-context-panel]'),
        ordersSlot: el('[data-dashboard-orders-slot]'),
        lowStockSlot: el('[data-dashboard-low-stock-slot]'),
        lowStockAll: el('[data-dashboard-low-stock-all]'),
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

    const setText = (node, value) => {
        if (node) {
            node.textContent = value;
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
        nodes.monthlyChartWrap?.classList.toggle('desktop-chart-placeholder', Boolean(isLoading));
    };

    const isJsonContentType = (contentType) => String(contentType || '').toLowerCase().includes('json');

    // ------------------------------------------------------------------
    // Painel de atenção
    // ------------------------------------------------------------------

    /**
     * Marcação idêntica à do partial _attention-chip.blade.php. Quando as duas
     * divergem, o painel troca de layout na hidratação e o usuário vê um salto.
     */
    const buildAttentionChip = (chip) => `
        <a class="dashboard-attention-chip tone-${escapeHtml(chip.tone || 'info')}" href="${escapeHtml(chip.url || '#')}">
            <span class="dashboard-attention-value">
                <span class="dashboard-attention-dot" aria-hidden="true"></span>
                ${escapeHtml(chip.value ?? '')}
            </span>
            <span class="dashboard-attention-label">${escapeHtml(chip.label ?? '')}</span>
            <span class="dashboard-attention-action">
                ${escapeHtml(chip.action_label || 'Abrir')} <i class="bi bi-arrow-right-short"></i>
            </span>
        </a>
    `;

    const renderAttention = (summary) => {
        if (!nodes.attentionSlot) {
            return;
        }

        const items = Array.isArray(summary?.attention) ? summary.attention : [];

        // Nada pendente é uma boa notícia, e o painel diz isso — em vez de
        // exibir "0 compromissos atrasados" como se fosse um problema.
        if (items.length === 0) {
            nodes.attentionSlot.innerHTML = `
                <div class="dashboard-attention-clear">
                    <i class="bi bi-check-circle-fill"></i>
                    <div>
                        <strong>Tudo certo por aqui</strong>
                        <span>Nenhuma pendência crítica requer sua atenção.</span>
                    </div>
                </div>
            `;
            return;
        }

        nodes.attentionSlot.innerHTML = items.map(buildAttentionChip).join('');
    };

    // ------------------------------------------------------------------
    // Indicadores
    // ------------------------------------------------------------------

    const renderKpiCards = (summary) => {
        const stats = summary?.stats || {};
        const hero = summary?.heroCard || {};
        const trend = summary?.revenueTrend || null;

        setText(nodes.openOrdersCard?.querySelector('[data-dashboard-open-orders-value]'), formatNumber(stats.orders ?? 0));

        if (nodes.heroCard) {
            const value = nodes.heroCard.querySelector('[data-dashboard-hero-value]');
            const icon = nodes.heroCard.querySelector('[data-dashboard-hero-icon]');
            const action = nodes.heroCard.querySelector('[data-dashboard-hero-action]');
            const trendNode = nodes.heroCard.querySelector('[data-dashboard-hero-trend]');

            nodes.heroCard.style.setProperty('--dashboard-accent', hero.accent || '#16a34a');
            setText(nodes.heroCard.querySelector('[data-dashboard-hero-label]'), hero.label || 'Resumo operacional');
            setText(nodes.heroCard.querySelector('[data-dashboard-hero-meta]'), hero.meta || 'Visão rápida do status atual do painel.');

            if (value) {
                const heroValue = hero.value_type === 'money'
                    ? formatMoney(hero.value ?? 0)
                    : String(hero.value ?? '').trim() || '—';
                value.classList.toggle('dashboard-kpi-value-text', hero.value_type === 'text');
                value.textContent = heroValue;
            }

            if (icon) {
                icon.className = `bi ${hero.icon || 'bi-graph-up-arrow'} dashboard-kpi-icon`;
            }

            // A tendência só existe para faturamento e some quando não há mês
            // anterior com que comparar.
            if (trendNode) {
                if (trend && hero.type === 'financial') {
                    trendNode.textContent = trend.label;
                    trendNode.className = `dashboard-kpi-trend is-${trend.direction}`;
                    trendNode.hidden = false;
                } else {
                    trendNode.hidden = true;
                }
            }

            if (action) {
                action.innerHTML = `${escapeHtml(hero.action_label || 'Abrir detalhe')} <i class="bi bi-arrow-right-short"></i>`;
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

        if (nodes.secondaryCard) {
            const card = summary?.secondaryCard || {};
            const value = nodes.secondaryCard.querySelector('[data-dashboard-secondary-value]');
            const icon = nodes.secondaryCard.querySelector('[data-dashboard-secondary-icon]');
            const trendNode = nodes.secondaryCard.querySelector('[data-dashboard-secondary-trend]');

            nodes.secondaryCard.style.setProperty('--dashboard-accent', card.accent || '#f59e0b');
            setText(nodes.secondaryCard.querySelector('[data-dashboard-secondary-label]'), card.label || 'Equipamento entregue');
            setText(
                nodes.secondaryCard.querySelector('[data-dashboard-secondary-meta]'),
                card.meta || 'Ordens concluídas e baixadas com entrega técnica registrada.'
            );

            if (value) {
                value.textContent = card.value_type === 'money' ? formatMoney(card.value ?? 0) : formatNumber(card.value ?? 0);
            }

            if (icon) {
                icon.className = `bi ${card.icon || 'bi-box2-heart-fill'} dashboard-kpi-icon`;
            }

            // "good" já vem invertido do backend: para despesa, cair é a
            // notícia boa (verde), diferente da tendência de faturamento.
            if (trendNode) {
                const cardTrend = card.trend;
                if (cardTrend) {
                    trendNode.textContent = cardTrend.label;
                    trendNode.className = `dashboard-kpi-trend is-${cardTrend.good ? 'up' : 'down'}`;
                    trendNode.hidden = false;
                } else {
                    trendNode.hidden = true;
                }
            }
        }

        if (nodes.summaryCard) {
            setText(nodes.summaryCard.querySelector('[data-dashboard-summary-equipments]'), formatNumber(stats.equipments ?? 0));
            setText(nodes.summaryCard.querySelector('[data-dashboard-summary-clients]'), formatNumber(stats.clients ?? 0));
            setText(nodes.summaryCard.querySelector('[data-dashboard-summary-total-os]'), formatNumber(stats.total_os ?? 0));
        }
    };

    // ------------------------------------------------------------------
    // Resumo financeiro / contexto
    // ------------------------------------------------------------------

    const renderContextCard = (summary) => {
        if (!nodes.contextPanel) {
            return;
        }

        const contextCard = summary?.contextCard || {};
        const financial = summary?.charts?.financial || {};
        const technician = summary?.charts?.technician || {};
        const isFinancial = contextCard.type === 'financial';
        const metrics = nodes.contextPanel.querySelector('[data-dashboard-context-metrics]');
        const headlineLabel = nodes.contextPanel.querySelector('[data-dashboard-context-headline-label]');
        const headlineValue = nodes.contextPanel.querySelector('[data-dashboard-context-headline-value]');

        setText(nodes.contextPanel.querySelector('[data-dashboard-context-title]'), contextCard.title || 'Resumo financeiro');
        setText(nodes.contextPanel.querySelector('[data-dashboard-context-subtitle]'), contextCard.subtitle || 'Comparativo operacional do mês corrente.');

        if (headlineLabel && headlineValue) {
            if (isFinancial) {
                const result = Number(financial.resultado_caixa ?? 0);
                headlineLabel.textContent = 'Resultado de caixa';
                headlineValue.textContent = formatMoney(result);
                headlineValue.className = `dashboard-context-headline-value ${result < 0 ? 'is-negative' : 'is-positive'}`;
            } else {
                headlineLabel.textContent = 'Técnico destaque';
                headlineValue.textContent = technician.highlight_name || 'Nenhum técnico';
                headlineValue.className = 'dashboard-context-headline-value';
            }
        }

        if (!metrics) {
            return;
        }

        const rows = isFinancial
            ? [
                ['Receitas', formatMoney(financial.receitas ?? 0)],
                ['Despesas', formatMoney(financial.despesas ?? 0)],
                // "Pendentes" soma contas a PAGAR pendentes/parciais vencendo até
                // o fim do mês — o rótulo diz "a pagar" para não ser lido como
                // dinheiro a entrar.
                ['Pendentes a pagar', formatMoney(financial.pendentes ?? 0)],
            ]
            : [
                ['OS em aberto', formatNumber(technician.highlight_total ?? 0)],
                ['Comissões', formatMoney(technician.commission_total ?? 0)],
                ['Período', `${Number(technician.month ?? new Date().getMonth() + 1)}/${Number(technician.year ?? new Date().getFullYear())}`],
            ];

        metrics.innerHTML = rows.map(([label, value]) => `
            <div class="dashboard-context-metric">
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(value)}</strong>
            </div>
        `).join('');
    };

    // ------------------------------------------------------------------
    // Tabela de OS
    // ------------------------------------------------------------------

    const buildPriorityMarker = (priority) => {
        if (!priority || !priority.tone) {
            return '';
        }

        return `<span class="dashboard-order-priority tone-${escapeHtml(priority.tone)}" title="${escapeHtml(priority.label || '')}" aria-label="${escapeHtml(priority.label || '')}"></span>`;
    };

    const renderOrders = (summary) => {
        if (!nodes.ordersSlot) {
            return;
        }

        const orders = Array.isArray(summary?.recentOrders) ? summary.recentOrders : [];
        if (orders.length === 0) {
            nodes.ordersSlot.innerHTML = buildEmptyState(
                'bi-clipboard-x',
                'Nenhuma ordem de serviço encontrada',
                'Quando novas OS forem cadastradas, elas aparecerão aqui.'
            );
            return;
        }

        const rows = orders.map((order) => {
            const orderId = Number(order.id ?? 0);
            const orderNumber = escapeHtml(order.numero_os || '#');
            const fullUrl = buildOrderUrl(orderId, false);
            const previewUrl = buildOrderUrl(orderId, true);

            return `
                <tr>
                    <td data-label="No OS">
                        <div class="dashboard-order-number">
                            ${buildPriorityMarker(order.priority)}
                            <a href="${fullUrl}" class="fw-semibold">${orderNumber}</a>
                        </div>
                    </td>
                    <td data-label="Cliente">${escapeHtml(String(order.cliente_nome ?? '').trim() || 'Não informado')}</td>
                    <td data-label="Equipamento">${escapeHtml(String(order.equipamento_resumo_tecnico ?? order.equipamento_nome ?? '').trim() || 'Sem resumo')}</td>
                    <td data-label="Status">
                        <span class="status-pill" style="--status-color: ${escapeHtml(String(order.status_cor ?? '#64748b'))}">
                            <span>${escapeHtml(String(order.status_nome ?? '').trim() || 'Sem status')}</span>
                        </span>
                    </td>
                    <td data-label="Dias"><span class="dashboard-age-pill">${formatNumber(order.dias_em_aberto ?? 0)}d</span></td>
                    <td data-label="Data">${escapeHtml(String(order.data_label ?? 'Sem data'))}</td>
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
                            <a href="${fullUrl}" class="btn btn-outline-light btn-sm" aria-label="Abrir OS ${orderNumber} em página cheia">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        nodes.ordersSlot.innerHTML = `
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
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    };

    // ------------------------------------------------------------------
    // Estoque
    // ------------------------------------------------------------------

    const renderLowStock = (summary) => {
        if (!nodes.lowStockSlot) {
            return;
        }

        const lowStock = Array.isArray(summary?.lowStock) ? summary.lowStock : [];
        const total = Number(summary?.lowStockTotal ?? lowStock.length);

        if (nodes.lowStockAll) {
            nodes.lowStockAll.hidden = total <= lowStock.length;
        }

        if (lowStock.length === 0) {
            nodes.lowStockSlot.innerHTML = buildEmptyState(
                'bi-check-circle',
                'Estoque em dia',
                'Nenhum produto está abaixo do estoque mínimo.'
            );
            return;
        }

        nodes.lowStockSlot.innerHTML = `
            <div class="dashboard-low-stock-list">
                ${lowStock.map((item) => {
                    const quantity = Number(item.quantidade_atual ?? 0);
                    const minimum = Number(item.estoque_minimo ?? 0);
                    const unit = escapeHtml(String(item.unidade ?? 'UN'));

                    return `
                        <article class="dashboard-low-stock-item ${quantity <= 0 ? 'is-critical' : ''}">
                            <div>
                                <strong>${escapeHtml(String(item.nome ?? 'Item sem nome'))}</strong>
                                <span>${formatNumber(quantity)} ${unit} em estoque · mínimo ${formatNumber(minimum)}</span>
                            </div>
                        </article>
                    `;
                }).join('')}
            </div>
        `;
    };

    // ------------------------------------------------------------------
    // Legendas
    // ------------------------------------------------------------------

    const renderMonthlyLegend = (monthly) => {
        if (!nodes.monthlyLegend) {
            return;
        }

        const series = Array.isArray(monthly?.series) ? monthly.series : [];
        nodes.monthlyLegend.innerHTML = series.map((item) => `
            <span class="dashboard-chart-legend" style="--legend-color: ${escapeHtml(item.color || '#6f5afc')};">
                ${escapeHtml(item.label || 'Série')}
            </span>
        `).join('');
    };

    /**
     * Legenda do donut: uma linha por macrofase, com os status reais que caem
     * nela como detalhamento. Preserva a informação que a versão anterior
     * despejava como catorze linhas soltas, mas em dois níveis de leitura.
     */
    const renderStatusLegend = (status) => {
        const groups = Array.isArray(status?.groups) ? status.groups : [];
        const total = Number(status?.total ?? 0);

        setText(nodes.statusTotal, total > 0 ? `${formatNumber(total)} OS em aberto` : '');

        if (!nodes.statusLegend) {
            return;
        }

        if (groups.length === 0) {
            nodes.statusLegend.innerHTML = buildEmptyState(
                'bi-pie-chart',
                'Sem OS em aberto',
                'Quando houver ordens em andamento, a distribuição aparecerá aqui.'
            );
            return;
        }

        nodes.statusLegend.innerHTML = groups.map((group) => {
            const share = total > 0 ? ((Number(group.total ?? 0) / total) * 100).toFixed(1).replace('.', ',') : '0,0';
            const detail = (Array.isArray(group.itens) ? group.itens : [])
                .map((item) => `${escapeHtml(item.nome)} (${formatNumber(item.total)})`)
                .join(' · ');

            const inner = `
                <span class="dashboard-status-dot" style="--dashboard-dot-color: ${escapeHtml(group.cor || '#6f5afc')};"></span>
                <div>
                    <strong>${escapeHtml(group.nome || 'Sem grupo')}</strong>
                    <small>${formatNumber(group.total ?? 0)} OS · ${share}%</small>
                    <small class="dashboard-status-detail">${detail}</small>
                </div>
            `;

            return group.url
                ? `<a class="dashboard-status-legend-item" href="${escapeHtml(group.url)}">${inner}</a>`
                : `<div class="dashboard-status-legend-item">${inner}</div>`;
        }).join('');
    };

    const renderEquipmentLegend = (equipment) => {
        if (!nodes.equipmentLegend) {
            return;
        }

        const series = Array.isArray(equipment?.series) ? equipment.series : [];
        nodes.equipmentLegend.innerHTML = series.map((item) => {
            const total = Array.isArray(item.data)
                ? item.data.reduce((sum, value) => sum + Number(value ?? 0), 0)
                : Number(item.total ?? 0);
            const color = item.backgroundColor || item.color || '#3b82f6';

            return `
                <span class="dashboard-equipment-legend-item" style="--legend-color: ${escapeHtml(color)}">
                    <span></span>
                    ${escapeHtml(item.label || 'Sem tipo')} · ${formatNumber(total)} OS
                </span>
            `;
        }).join('');
    };

    // ------------------------------------------------------------------
    // Ciclo
    // ------------------------------------------------------------------

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

    const applyFilterOptions = (filters) => {
        if (!filters || typeof filters !== 'object') {
            return;
        }

        const asOptions = (years) => years.map((year) => ({ value: year, label: String(year) }));

        if (Array.isArray(filters.years) && filters.years.length > 0) {
            setSelectOptions(nodes.yearFilter, asOptions(filters.years), filters.year);
        }

        if (Array.isArray(filters.equipmentYears) && filters.equipmentYears.length > 0) {
            setSelectOptions(nodes.equipmentYearFilter, asOptions(filters.equipmentYears), filters.equipmentYear);
        }
    };

    const applySummary = (summary) => {
        renderAttention(summary);
        renderKpiCards(summary);
        renderContextCard(summary);
        renderOrders(summary);
        renderLowStock(summary);

        charts.createLineChart?.(nodes.monthlyCanvas, summary?.charts?.monthly);
        charts.createDoughnutChart?.(nodes.statusCanvas, summary?.charts?.status?.groups);
        charts.createStackedBarChart?.(nodes.equipmentCanvas, summary?.charts?.equipmentTypes);

        const contextLegendItems = Array.isArray(summary?.contextCard?.legend) ? summary.contextCard.legend : [];
        const isFinancial = summary?.contextCard?.type === 'financial';
        charts.createBarChart?.(nodes.contextCanvas, summary?.contextCard?.chart, {
            label: summary?.contextCard?.title || 'Resumo',
            color: contextLegendItems.map((item) => item.color || '#22c55e'),
            indexAxis: 'y',
            maxBarThickness: 30,
            formatValue: isFinancial ? formatMoney : formatNumber,
        });

        if (nodes.contextLegend) {
            nodes.contextLegend.innerHTML = contextLegendItems.map((item) => `
                <span class="dashboard-chart-legend" style="--legend-color: ${escapeHtml(item.color || '#6f5afc')}">
                    ${escapeHtml(item.label || 'Série')}
                </span>
            `).join('');
        }

        renderMonthlyLegend(summary?.charts?.monthly);
        renderStatusLegend(summary?.charts?.status);
        renderEquipmentLegend(summary?.charts?.equipmentTypes);
        setMonthlyLoadingState(false);
    };

    /**
     * Sem isto uma falha de rede deixa os skeletons animando para sempre, e o
     * usuário fica olhando um bloco cinza sem saber que a carga quebrou.
     */
    const renderLoadFailure = () => {
        const message = buildEmptyState(
            'bi-wifi-off',
            'Não foi possível carregar o painel',
            'Verifique a conexão com a API central e atualize a página.'
        );

        if (nodes.attentionSlot) {
            nodes.attentionSlot.innerHTML = message;
        }

        [nodes.ordersSlot, nodes.lowStockSlot].forEach((slot) => {
            if (slot && slot.querySelector('.dashboard-skeleton')) {
                slot.innerHTML = message;
            }
        });

        document.querySelectorAll('.dashboard-skeleton').forEach((skeleton) => {
            skeleton.replaceWith(document.createTextNode('—'));
        });
    };

    const readFilters = () => ({
        ano: nodes.yearFilter instanceof HTMLSelectElement ? nodes.yearFilter.value : '',
        equip_ano: nodes.equipmentYearFilter instanceof HTMLSelectElement ? nodes.equipmentYearFilter.value : '',
    });

    const syncDashboard = async () => {
        const params = new URLSearchParams();
        setMonthlyLoadingState(true);

        Object.entries(readFilters()).forEach(([key, value]) => {
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
                renderLoadFailure();
                return;
            }

            const contentType = String(response.headers.get('content-type') || '');

            if (response.redirected || !isJsonContentType(contentType)) {
                setMonthlyLoadingState(false);
                renderLoadFailure();

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
            renderLoadFailure();

            if (window.DesktopUi && typeof window.DesktopUi.logError === 'function') {
                window.DesktopUi.logError('dashboard.syncDashboard', error);
            } else {
                console.error('[DESKTOP][DASHBOARD] Falha ao atualizar os widgets.', error);
            }
        }
    };

    const bindFilters = () => {
        [nodes.yearFilter, nodes.equipmentYearFilter].forEach((element) => {
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

    document.addEventListener('DOMContentLoaded', () => {
        bindFilters();
        setMonthlyLoadingState(true);
        syncDashboard();
    });
})();
