(function () {
    const config = window.__DESKTOP_BATCH_STATUS_MODAL || {};
    const batchStatusUrl = String(config.batchStatusUrl || '');
    const csrfToken = String(config.csrfToken || '');

    const trigger = document.getElementById('osBulkStatusTrigger');
    const modalEl = document.getElementById('batchStatusModal');
    if (!trigger || !modalEl) return;

    const countChip = document.getElementById('osBulkSelectionCount');
    const selectAll = document.getElementById('osSelectAll');
    const form = document.getElementById('batchStatusForm');
    const errorBox = document.getElementById('batchStatusError');
    const submitBtn = document.getElementById('batchStatusSubmit');
    const idsContainer = document.getElementById('batchStatusOrderIds');
    const countLabel = document.getElementById('batchStatusCount');
    const orderListEl = document.getElementById('batchStatusOrderList');
    const modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');

    // Reconsulta ao vivo (nao guarda um array estatico): orders-list.js pode
    // inserir linhas novas via Pusher depois do carregamento da pagina.
    // Mesmo checkbox .order-select compartilhado com a baixa em lote — cada
    // ação valida por conta própria quais das OS selecionadas são elegíveis.
    const checkboxes = () => [...document.querySelectorAll('.order-select')];
    const selected = () => checkboxes().filter((item) => item.checked);

    // Estado do lote dentro do modal: cópia mutável da seleção da listagem no
    // instante em que o modal abriu (id -> detalhes). Permite remover uma OS
    // do lote sem fechar o modal — mesmo padrão de orders-batch-closure.js.
    let batchItems = new Map();

    const buildItemFromCheckbox = (checkbox) => ({
        id: checkbox.value,
        numero: checkbox.dataset.orderNumero || ('#' + checkbox.value),
        cliente: checkbox.dataset.orderCliente || 'Cliente não informado',
        equipamento: checkbox.dataset.orderEquipamento || 'Sem resumo técnico',
        status: checkbox.dataset.orderStatus || 'Sem status',
        valor: checkbox.dataset.orderValor || 'Valor não informado',
    });

    const showError = (message) => {
        if (errorBox instanceof HTMLElement) {
            errorBox.textContent = message;
            errorBox.classList.remove('d-none');
        }
    };

    const clearError = () => {
        if (errorBox instanceof HTMLElement) {
            errorBox.classList.add('d-none');
            errorBox.textContent = '';
        }
    };

    const updateSelection = () => {
        const items = selected();
        const total = items.length;
        const all = checkboxes();

        if (countChip instanceof HTMLElement) {
            countChip.textContent = `${total} selecionada${total === 1 ? '' : 's'}`;
        }
        if (trigger instanceof HTMLButtonElement) {
            trigger.disabled = total === 0;
        }
        if (selectAll instanceof HTMLInputElement) {
            selectAll.checked = all.length > 0 && total === all.length;
            selectAll.indeterminate = total > 0 && total < all.length;
        }
    };

    document.addEventListener('change', (event) => {
        if (event.target instanceof HTMLElement && event.target.matches('.order-select')) {
            updateSelection();
        }
    });

    selectAll?.addEventListener('change', () => {
        checkboxes().forEach((item) => { item.checked = selectAll.checked; });
        updateSelection();
    });

    const renderBatchList = () => {
        idsContainer?.replaceChildren(...[...batchItems.keys()].map((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'order_ids[]';
            input.value = id;
            return input;
        }));

        if (countLabel instanceof HTMLElement) {
            countLabel.textContent = String(batchItems.size);
        }

        if (submitBtn instanceof HTMLButtonElement) {
            submitBtn.disabled = batchItems.size === 0;
        }

        if (batchItems.size === 0) {
            orderListEl?.replaceChildren(Object.assign(document.createElement('div'), {
                className: 'list-group-item list-group-item-empty',
                textContent: 'Nenhuma OS selecionada. Feche o modal e marque ao menos uma OS na listagem.',
            }));
            return;
        }

        orderListEl?.replaceChildren(...[...batchItems.values()].map((item) => {
            const row = document.createElement('div');
            row.className = 'list-group-item';
            row.innerHTML = `
                <div class="flex-grow-1">
                    <div class="fw-semibold">${escapeHtml(item.numero)} <span class="badge text-bg-secondary fw-normal">${escapeHtml(item.status)}</span></div>
                    <div class="small text-secondary">${escapeHtml(item.cliente)} · ${escapeHtml(item.equipamento)}</div>
                    <div class="small text-secondary">${escapeHtml(item.valor)}</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger batch-status-remove" data-order-id="${escapeHtml(item.id)}" aria-label="Remover OS ${escapeHtml(item.numero)} da alteração em lote">
                    <i class="bi bi-x-lg"></i>
                </button>
            `;
            return row;
        }));
    };

    orderListEl?.addEventListener('click', (event) => {
        const button = event.target instanceof HTMLElement ? event.target.closest('.batch-status-remove') : null;
        if (!(button instanceof HTMLElement)) return;

        const orderId = button.dataset.orderId || '';
        batchItems.delete(orderId);

        // Mantém a listagem por trás coerente: se o modal for fechado sem
        // confirmar, a OS removida aqui não deve continuar marcada lá.
        const checkbox = document.querySelector(`.order-select[value="${CSS.escape(orderId)}"]`);
        if (checkbox instanceof HTMLInputElement) {
            checkbox.checked = false;
        }
        updateSelection();

        renderBatchList();
    });

    trigger.addEventListener('click', () => {
        const items = selected();
        if (!items.length || !modal) return;

        batchItems = new Map(items.map((item) => [item.value, buildItemFromCheckbox(item)]));
        renderBatchList();

        clearError();
        modal.show();
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        form?.reset();
        clearError();
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;

        const orderIds = idsContainer instanceof HTMLElement
            ? [...idsContainer.querySelectorAll('input')].map((input) => Number(input.value))
            : [];
        if (!orderIds.length) return;

        if (submitBtn instanceof HTMLButtonElement) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processando...';
        }
        clearError();

        try {
            const response = await fetch(batchStatusUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    order_ids: orderIds,
                    status: document.getElementById('batchStatusTarget')?.value || '',
                    observacao: document.getElementById('batchStatusObservacao')?.value || null,
                    comunicar_cliente: document.getElementById('batchStatusNotifyClient')?.checked || false,
                }),
            });

            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload.error) {
                throw new Error(payload.error || 'Não foi possível concluir a alteração de status em lote.');
            }

            modal?.hide();

            const result = payload.result || {};
            const succeeded = Array.isArray(result.succeeded) ? result.succeeded : [];
            const failed = Array.isArray(result.failed) ? result.failed : [];
            const requested = Number(result.notificacoes_solicitadas || 0);
            const sent = Number(result.notificacoes_enviadas || 0);

            const rows = [
                ...succeeded.map((item) => {
                    const suffix = item.notificado
                        ? ' — status alterado e cliente notificado'
                        : requested > 0
                            ? ' — status alterado (não notificado)'
                            : ' — status alterado';
                    return `<li class="text-success">✔ ${escapeHtml(item.numero_os || ('#' + item.order_id))}${suffix}</li>`;
                }),
                ...failed.map((item) => `<li class="text-danger">✘ ${escapeHtml(item.numero_os || ('#' + item.order_id))} — ${escapeHtml(item.message || item.reason)}</li>`),
            ].join('');

            const capNotice = requested > sent
                ? `<p class="small text-warning mb-2">Limite de 5 notificações por lote atingido: ${sent} de ${requested} solicitadas foram enviadas.</p>`
                : '';

            if (typeof Swal !== 'undefined') {
                await Swal.fire({
                    icon: failed.length ? 'warning' : 'success',
                    title: payload.message || 'Alteração de status em lote concluída',
                    html: `${capNotice}<ul class="text-start small mb-0">${rows}</ul>`,
                });
            }

            window.location.reload();
        } catch (exception) {
            showError(exception.message || 'Não foi possível concluir a alteração de status em lote.');
        } finally {
            if (submitBtn instanceof HTMLButtonElement) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-arrow-left-right me-1"></i>Confirmar alteração em lote';
            }
        }
    });

    updateSelection();
})();
