(function () {
    const config = window.__DESKTOP_STATUS_MODAL || {};
    const statusContextUrlTemplate = String(config.statusContextUrlTemplate || '');
    const statusUpdateUrlTemplate = String(config.statusUpdateUrlTemplate || '');
    const csrfToken = String(config.csrfToken || '');

    const buildContextUrl = (orderId) => statusContextUrlTemplate.replaceAll('__ORDER__', String(orderId));
    const buildUpdateUrl = (orderId) => statusUpdateUrlTemplate.replaceAll('__ORDER__', String(orderId));

    const modalEl = document.getElementById('orderStatusModal');
    if (!modalEl) return;

    const form = document.getElementById('orderStatusModalForm');
    const loadingEl = document.getElementById('orderStatusModalLoading');
    const contentEl = document.getElementById('orderStatusModalContent');
    const errorEl = document.getElementById('orderStatusModalError');
    const errorTextEl = document.getElementById('orderStatusModalErrorText');
    const submitBtn = document.getElementById('orderStatusModalSubmit');
    const selectEl = document.getElementById('orderStatusModalSelect');
    const nextBtn = document.getElementById('orderStatusModalQuickNext');
    const cancelBtn = document.getElementById('orderStatusModalQuickCancel');

    let currentOrderId = null;

    const setText = (id, text) => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    };

    const showState = (state) => {
        loadingEl?.classList.toggle('d-none', state !== 'loading');
        contentEl?.classList.toggle('d-none', state !== 'content');
        errorEl?.classList.toggle('d-none', state !== 'error');
    };

    const showToast = (message, type = 'success') => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                timer: 4000,
                timerProgressBar: true,
                showConfirmButton: false,
                icon: type,
                title: message,
                customClass: { popup: 'swal-desktop-toast' },
            });
        }
    };

    const buildHistoryItem = (item) => {
        const anterior = String(item.status_anterior || '');
        const novo = String(item.status_novo || '');
        const transicao = anterior && novo ? `${anterior} → ${novo}` : (novo || anterior || 'Movimentação');
        const data = String(item.created_at || '');
        const obs = String(item.observacao || '');
        const autor = item.usuario?.nome ? String(item.usuario.nome) : '';

        return `
            <div class="os-status-history-item">
                <div class="os-status-history-item-header">
                    <span class="os-status-history-item-badge">${transicao}</span>
                    ${data !== '' ? `<span class="os-status-history-item-date">${data}</span>` : ''}
                </div>
                ${obs !== '' ? `<div class="os-status-history-item-obs">${obs}</div>` : ''}
                ${autor !== '' ? `<div class="os-status-history-item-author">por ${autor}</div>` : ''}
            </div>
        `;
    };

    const populateModal = (data) => {
        const numeroOs = String(data.numero_os || '');
        setText('orderStatusModalNumero', numeroOs);

        // Cliente
        setText('orderStatusModalClientName', data.cliente_nome || '-');
        setText('orderStatusModalClientPhone', data.cliente_telefone ? `Telefone: ${data.cliente_telefone}` : 'Telefone: -');
        setText('orderStatusModalClientEmail', data.cliente_email ? `E-mail: ${data.cliente_email}` : 'E-mail: -');

        // Equipamento
        setText('orderStatusModalEquipName', data.equipamento_nome || '-');
        setText('orderStatusModalEquipType', data.equipamento_tipo_nome ? `Tipo: ${data.equipamento_tipo_nome}` : 'Tipo: -');
        setText('orderStatusModalEquipSerial', data.equipamento_numero_serie ? `Nº de série: ${data.equipamento_numero_serie}` : 'Nº de série: -');

        // Status atual
        const statusAtual = String(data.status_nome || '');
        setText('orderStatusModalCurrentHint', `Status atual da OS: ${statusAtual}.`);

        // Próximas etapas
        const etapas = Array.isArray(data.proximas_etapas) ? data.proximas_etapas : [];

        // Preenche o select
        if (selectEl) {
            selectEl.innerHTML = '<option value="">Selecione um status</option>';
            etapas.forEach((etapa) => {
                const code = String(etapa.codigo || '').trim();
                if (!code) return;
                const opt = document.createElement('option');
                opt.value = code;
                opt.textContent = String(etapa.nome || code);
                selectEl.appendChild(opt);
            });
            selectEl.value = '';
        }

        // Botão de ação rápida "Próxima etapa": primeira opção que não seja de cancelamento
        const cancelCodes = ['cancelado', 'devolvido', 'devolvido_sem_reparo', 'descartado'];
        const primaryStep = etapas.find((e) => !cancelCodes.includes(String(e.codigo || '')));
        const cancelStep = etapas.find((e) => cancelCodes.includes(String(e.codigo || '')));

        if (nextBtn) {
            if (primaryStep) {
                nextBtn.disabled = false;
                nextBtn.dataset.statusCode = primaryStep.codigo;
                nextBtn.dataset.statusName = primaryStep.nome || primaryStep.codigo;
                setText('orderStatusModalFlowHint', `Fluxo normal sugerido: ${primaryStep.nome || primaryStep.codigo}.`);
            } else {
                nextBtn.disabled = true;
                nextBtn.dataset.statusCode = '';
                setText('orderStatusModalFlowHint', 'Sem próxima etapa definida no fluxo.');
            }
        }

        if (cancelBtn) {
            if (cancelStep) {
                cancelBtn.disabled = false;
                cancelBtn.dataset.statusCode = cancelStep.codigo;
                cancelBtn.dataset.statusName = cancelStep.nome || cancelStep.codigo;
            } else {
                cancelBtn.disabled = true;
                cancelBtn.dataset.statusCode = '';
            }
        }

        setText('orderStatusModalTargetHint', 'Selecione um fluxo para continuar.');
        if (submitBtn) submitBtn.disabled = true;

        // Histórico
        const historyEl = document.getElementById('orderStatusModalHistory');
        if (historyEl) {
            const historico = Array.isArray(data.historico) ? data.historico : [];
            if (historico.length > 0) {
                historyEl.innerHTML = historico.map(buildHistoryItem).join('');
            } else {
                historyEl.innerHTML = '<p class="text-muted small mb-0">Sem histórico recente.</p>';
            }
        }

        showState('content');
    };

    // Abre o modal e carrega os dados da OS via AJAX
    modalEl.addEventListener('show.bs.modal', async (event) => {
        const trigger = event.relatedTarget;
        currentOrderId = trigger?.dataset.orderId || '';

        if (!currentOrderId) {
            showState('error');
            if (errorTextEl) errorTextEl.textContent = 'ID da OS não informado.';
            return;
        }

        showState('loading');
        if (form) form.reset();
        if (submitBtn) submitBtn.disabled = true;

        try {
            const res = await fetch(buildContextUrl(currentOrderId), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!res.ok) {
                throw new Error('Erro ao carregar dados da OS.');
            }

            const data = await res.json();

            if (data.error) {
                throw new Error(data.error);
            }

            populateModal(data);
        } catch (err) {
            showState('error');
            if (errorTextEl) errorTextEl.textContent = err.message || 'Não foi possível carregar os dados da OS.';
        }
    });

    // Limpa o modal ao fechar
    modalEl.addEventListener('hidden.bs.modal', () => {
        currentOrderId = null;
        if (form) form.reset();
        showState('loading');
        if (submitBtn) submitBtn.disabled = true;
        setText('orderStatusModalNumero', '-');
    });

    // Botão "Próxima etapa"
    nextBtn?.addEventListener('click', () => {
        const code = nextBtn.dataset.statusCode;
        if (code && selectEl) {
            selectEl.value = code;
            selectEl.dispatchEvent(new Event('change'));
        }
    });

    // Botão "Cancelar" (quick action)
    cancelBtn?.addEventListener('click', () => {
        const code = cancelBtn.dataset.statusCode;
        if (code && selectEl) {
            selectEl.value = code;
            selectEl.dispatchEvent(new Event('change'));
        }
    });

    // Ao mudar o select
    selectEl?.addEventListener('change', () => {
        const selectedName = selectEl.selectedOptions[0]?.text || '';
        if (submitBtn) submitBtn.disabled = !selectEl.value;
        setText(
            'orderStatusModalTargetHint',
            selectEl.value ? `Fluxo selecionado: ${selectedName}.` : 'Selecione um fluxo para continuar.'
        );
    });

    // Submissão via AJAX
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!currentOrderId || !selectEl?.value) return;

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
        }

        const formData = new FormData(form);

        try {
            const res = await fetch(buildUpdateUrl(currentOrderId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: formData,
            });

            const result = await res.json();

            if (!res.ok || result.error) {
                throw new Error(result.error || result.message || 'Erro ao atualizar o status.');
            }

            // Fecha o modal
            const bsModal = bootstrap.Modal.getInstance(modalEl);
            bsModal?.hide();

            showToast(result.message || `Status alterado para: ${result.status_nome || 'novo status'}.`, 'success');

            // Recarrega a página para refletir o novo status sem redirecionar
            setTimeout(() => location.reload(), 1000);
        } catch (err) {
            showToast(err.message || 'Não foi possível alterar o status. Tente novamente.', 'error');

            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Salvar status';
            }
        }
    });
})();
