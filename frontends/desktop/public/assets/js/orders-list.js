(function () {
    const config = window.__DESKTOP_ORDER_LIST || {};

    const pusherKey = String(config.pusherKey || '').trim();
    const channelName = String(config.channelName || 'orders').trim();
    const authUrl = String(config.broadcastAuthUrl || '').trim();
    const hasFilters = Boolean(config.hasFilters);
    const showUrlBase = String(config.ordersShowUrlBase || '/os/').trim();
    const csrfToken = String(config.csrfToken || '').trim();
    const canCreateBudget = Boolean(config.canCreateBudget);
    const canEditOrder = Boolean(config.canEditOrder);
    const budgetCreateUrlBase = String(config.budgetCreateUrlBase || '/orcamentos/novo?os_id=').trim();
    const orderEditUrlTemplate = String(config.ordersEditUrlTemplate || '/os/__ORDER__/editar').trim();
    const orderClosureUrlTemplate = String(config.ordersClosureUrlTemplate || '/os/__ORDER__/baixa').trim();
    const orderStatusUrlTemplate = String(config.ordersStatusUpdateUrlTemplate || '/os/__ORDER__/status').trim();

    if (pusherKey === '' || authUrl === '' || csrfToken === '' || typeof Pusher === 'undefined') {
        return;
    }

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');

    const buildUrl = (template, orderId) => String(template || '').replaceAll('__ORDER__', String(orderId));

    const showToast = (title) => {
        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({
            toast: true,
            position: 'top-end',
            timer: 5000,
            timerProgressBar: true,
            showConfirmButton: false,
            icon: 'info',
            title,
            customClass: { popup: 'swal-desktop-toast' },
        });
    };

    const updateCounter = () => {
        const chip = document.querySelector('.surface-card-header .desktop-chip');
        if (!(chip instanceof HTMLElement)) {
            return;
        }

        const current = parseInt(chip.textContent.replace(/\D/g, ''), 10) || 0;
        const next = current + 1;
        chip.textContent = next.toLocaleString('pt-BR') + ' resultados';
    };

    const buildStatusActions = (orderId, nextStatusOptions) => {
        if (!canEditOrder || !Array.isArray(nextStatusOptions) || nextStatusOptions.length === 0) {
            return '';
        }

        const numeroOs = escapeHtml(String(orderId));

        return `
            <li><hr class="dropdown-divider"></li>
            <li>
                <button type="button" class="dropdown-item"
                    data-bs-toggle="modal"
                    data-bs-target="#orderStatusModal"
                    data-order-id="${orderId}"
                    data-order-numero="${numeroOs}">
                    <i class="bi bi-arrow-left-right me-2"></i>
                    Alterar status
                </button>
            </li>
        `;
    };

    const buildRow = (order) => {
        const orderId = Number(order.id || 0);
        const numeroOs = escapeHtml(order.numero_os || ('#' + orderId));
        const clienteNome = escapeHtml(order.cliente_nome || 'Nao informado');
        const clienteTel = escapeHtml(order.cliente_telefone || '');
        const equipResumo = escapeHtml(order.equipamento_resumo || 'Sem resumo tecnico');
        const equipSerie = escapeHtml(order.equipamento_serie || '');
        const statusNome = escapeHtml(order.status_nome || 'Triagem');
        const statusCor = escapeHtml(order.status_cor || '#64748b');
        const dataEntrada = escapeHtml(order.data_entrada || '');
        const showUrl = escapeHtml(showUrlBase + orderId);
        const nextStatusOptions = Array.isArray(order.proximas_etapas) ? order.proximas_etapas : [];
        const canCloseOrder = canEditOrder && !['encerrado', 'cancelado'].includes(String(order.estado_fluxo || '').trim());
        const budgetUrl = escapeHtml(`${budgetCreateUrlBase}${orderId}`);
        const editUrl = escapeHtml(buildUrl(orderEditUrlTemplate, orderId));
        const closureUrl = escapeHtml(buildUrl(orderClosureUrlTemplate, orderId));
        const statusActions = buildStatusActions(orderId, nextStatusOptions);

        const whatsappHtml = clienteTel !== ''
            ? `<a href="https://wa.me/55${clienteTel.replace(/\D/g, '')}" target="_blank" rel="noreferrer" class="text-decoration-none small">
                   <i class="bi bi-whatsapp me-1"></i>${clienteTel}
               </a>`
            : '';

        const tr = document.createElement('tr');
        tr.dataset.orderId = String(orderId);
        tr.innerHTML = `
            <td data-label="Foto / OS">
                <div class="os-photo-cell">
                    <span class="equipment-list-photo-placeholder" aria-hidden="true">
                        <i class="bi bi-camera"></i>
                    </span>
                    <div>
                        <a href="${showUrl}" class="fw-semibold">
                            ${numeroOs}
                            <span class="os-badge-novo">Novo</span>
                        </a>
                        <div class="text-secondary small">Recem criada</div>
                    </div>
                </div>
            </td>
            <td data-label="Cliente">
                <div>${clienteNome}</div>
                ${whatsappHtml}
            </td>
            <td data-label="Equipamento">
                <div>${equipResumo}</div>
                <small class="text-secondary">${equipSerie !== '' ? 'S/N ' + equipSerie : 'Serie nao informada'}</small>
            </td>
            <td data-label="Datas">
                <div class="os-dates-cell">
                    <div><span class="text-secondary small">Entrada:</span> ${dataEntrada !== '' ? dataEntrada : 'Agora'}</div>
                </div>
            </td>
            <td data-label="Status / Orçamento">
                <div class="os-status-cell">
                    <span class="status-pill" style="--pill-color:${statusCor}">${statusNome}</span>
                    <span class="text-secondary small">Sem orçamento</span>
                </div>
            </td>
            <td data-label="Valor">
                <div class="os-value-cell">
                    <strong>Não informado</strong>
                    <div class="text-secondary small">Sem cobrança</div>
                </div>
            </td>
            <td data-label="Ações" class="text-end">
                <div class="dropdown os-actions-dropdown">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-light dropdown-toggle os-actions-toggle"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                    >
                        <span>Ações</span>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end os-actions-menu">
                        <li>
                            <a href="${showUrl}" class="dropdown-item">
                                <i class="bi bi-eye me-2"></i>
                                Detalhe
                            </a>
                        </li>
                        ${canCreateBudget ? `
                            <li>
                                <a href="${budgetUrl}" class="dropdown-item">
                                    <i class="bi bi-receipt me-2"></i>
                                    Gerar orçamento
                                </a>
                            </li>
                        ` : ''}
                        ${canEditOrder ? `
                            <li>
                                <a href="${editUrl}" class="dropdown-item">
                                    <i class="bi bi-pencil-square me-2"></i>
                                    Editar
                                </a>
                            </li>
                        ` : ''}
                        ${canCloseOrder ? `
                            <li>
                                <a href="${closureUrl}" class="dropdown-item">
                                    <i class="bi bi-box-seam me-2"></i>
                                    Baixa
                                </a>
                            </li>
                        ` : ''}
                        ${statusActions}
                    </ul>
                </div>
            </td>
        `;

        return tr;
    };

    const handleOrderCreated = (order) => {
        const numeroOs = String(order.numero_os || '').trim() || ('#' + order.id);
        const clienteNome = String(order.cliente_nome || '').trim() || 'cliente';
        showToast(`Nova OS criada: ${numeroOs} - ${clienteNome}`);
        updateCounter();

        if (hasFilters) {
            return;
        }

        const tbody = document.querySelector('table.table tbody');
        if (!(tbody instanceof HTMLElement)) {
            return;
        }

        const existing = tbody.querySelector(`[data-order-id="${order.id}"]`);
        if (existing instanceof HTMLElement) {
            return;
        }

        tbody.prepend(buildRow(order));
    };

    const pusher = new Pusher(pusherKey, {
        wsHost: config.pusherHost || 'localhost',
        wsPort: Number(config.pusherPort) || 8090,
        wssPort: Number(config.pusherPort) || 8090,
        forceTLS: (config.pusherScheme || 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
        cluster: '',
        authEndpoint: authUrl,
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });

    const channel = pusher.subscribe('private-' + channelName);
    channel.bind('order.created', handleOrderCreated);
})();
