(function () {
    const config = window.__DESKTOP_ORDER_LIST || {};

    const pusherKey = String(config.pusherKey || '').trim();
    const channelName = String(config.channelName || 'orders').trim();
    const authUrl = String(config.broadcastAuthUrl || '').trim();
    const apiToken = String(config.apiToken || '').trim();
    const hasFilters = Boolean(config.hasFilters);
    const showUrlBase = String(config.ordersShowUrlBase || '/os/').trim();

    if (pusherKey === '' || authUrl === '' || apiToken === '' || typeof Pusher === 'undefined') {
        return;
    }

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');

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

    const buildRow = (order) => {
        const orderId = Number(order.id || 0);
        const numeroOs = escapeHtml(order.numero_os || ('#' + orderId));
        const clienteNome = escapeHtml(order.cliente_nome || 'Não informado');
        const clienteTel = escapeHtml(order.cliente_telefone || '');
        const equipResumo = escapeHtml(order.equipamento_resumo || 'Sem resumo técnico');
        const equipSerie = escapeHtml(order.equipamento_serie || '');
        const statusNome = escapeHtml(order.status_nome || 'Triagem');
        const statusCor = escapeHtml(order.status_cor || '#64748b');
        const dataEntrada = escapeHtml(order.data_entrada || '');
        const showUrl = escapeHtml(showUrlBase + orderId);

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
                        <div class="text-secondary small">Recém criada</div>
                    </div>
                </div>
            </td>
            <td data-label="Cliente">
                <div>${clienteNome}</div>
                ${whatsappHtml}
            </td>
            <td data-label="Equipamento">
                <div>${equipResumo}</div>
                <small class="text-secondary">${equipSerie !== '' ? 'S/N ' + equipSerie : 'Série não informada'}</small>
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
                <a href="${showUrl}" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-eye me-1"></i>
                    Ver OS
                </a>
            </td>
        `;

        return tr;
    };

    const handleOrderCreated = (order) => {
        const numeroOs = String(order.numero_os || '').trim() || ('#' + order.id);
        const clienteNome = String(order.cliente_nome || '').trim() || 'cliente';
        showToast(`Nova OS criada: ${numeroOs} — ${clienteNome}`);
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
                Authorization: 'Bearer ' + apiToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });

    const channel = pusher.subscribe('private-' + channelName);
    channel.bind('order.created', handleOrderCreated);
})();
