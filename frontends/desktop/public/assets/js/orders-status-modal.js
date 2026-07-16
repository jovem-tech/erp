(function () {
    const config = window.__DESKTOP_STATUS_MODAL || {};
    const statusContextUrlTemplate = String(config.statusContextUrlTemplate || '');
    const statusUpdateUrlTemplate = String(config.statusUpdateUrlTemplate || '');
    const proceduresUrlTemplate = String(config.proceduresUrlTemplate || '');
    const csrfToken = String(config.csrfToken || '');

    const buildContextUrl = (orderId) => statusContextUrlTemplate.replaceAll('__ORDER__', String(orderId));
    const buildUpdateUrl = (orderId) => statusUpdateUrlTemplate.replaceAll('__ORDER__', String(orderId));
    const buildProceduresUrl = (orderId) => proceduresUrlTemplate.replaceAll('__ORDER__', String(orderId));

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
    const proceduresEl = document.getElementById('orderStatusModalProcedures');
    const diagnosisEl = document.getElementById('orderStatusModalDiagnosis');
    const solutionEl = document.getElementById('orderStatusModalSolution');
    const proceduresSaveBtn = document.getElementById('orderStatusModalProceduresSave');
    const novoPrazoWrapper = document.getElementById('orderStatusModalNovoPrazoWrapper');
    const novoPrazoInput = document.getElementById('orderStatusModalNovoPrazo');

    let currentOrderId = null;
    let statusLabelsByCode = {};
    let statusCongelaPrazoAtual = false;

    // Sugestão padrão: hoje + 7 dias, no formato aceito pelo <input type="date">.
    const suggestedNovoPrazo = () => {
        const data = new Date();
        data.setDate(data.getDate() + 7);
        const ano = data.getFullYear();
        const mes = String(data.getMonth() + 1).padStart(2, '0');
        const dia = String(data.getDate()).padStart(2, '0');
        return `${ano}-${mes}-${dia}`;
    };

    // Esconde/desabilita a seção de redefinição de prazo (input disabled não
    // entra no FormData do submit, então não precisa de guarda extra nesse caso).
    const hideNovoPrazoSection = () => {
        novoPrazoWrapper?.classList.add('d-none');
        if (novoPrazoInput) {
            novoPrazoInput.disabled = true;
            novoPrazoInput.required = false;
            novoPrazoInput.value = '';
        }
    };

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

    const statusLabel = (code) => {
        const trimmed = String(code || '').trim();
        if (trimmed === '') return '';
        return statusLabelsByCode[trimmed] || trimmed;
    };

    const formatHistoryDate = (value) => {
        const raw = String(value || '').trim();
        if (raw === '') return '';

        const parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) return raw;

        return parsed.toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const buildProcedureHistoryItem = (item) => {
        const data = formatHistoryDate(item.created_at);
        const descricao = String(item.descricao || '');
        const autor = item.usuario?.nome ? String(item.usuario.nome) : '';

        return `
            <div class="os-status-history-item">
                ${data !== '' ? `<div class="os-status-history-item-date mb-1">${data}</div>` : ''}
                <div class="os-status-history-item-obs">${descricao}</div>
                ${autor !== '' ? `<div class="os-status-history-item-author">por ${autor}</div>` : ''}
            </div>
        `;
    };

    const renderProceduresHistory = (procedimentos) => {
        const historyEl = document.getElementById('orderStatusModalProceduresHistory');
        if (!historyEl) return;

        const items = Array.isArray(procedimentos) ? procedimentos : [];
        historyEl.innerHTML = items.length > 0
            ? items.map(buildProcedureHistoryItem).join('')
            : '<p class="text-muted small mb-0">Nenhum procedimento registrado ainda.</p>';
    };

    const buildHistoryItem = (item) => {
        const anterior = statusLabel(item.status_anterior);
        const novo = statusLabel(item.status_novo);
        const transicao = anterior && novo ? `${anterior} → ${novo}` : (novo || anterior || 'Movimentação');
        const data = formatHistoryDate(item.created_at);
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

        statusCongelaPrazoAtual = Boolean(data.status_congela_prazo);
        hideNovoPrazoSection();

        // Catálogo de status (código → nome), usado pra traduzir o histórico
        // e evitar mostrar códigos crus tipo "aguardando_reparo" na tela.
        statusLabelsByCode = {};
        const statusCatalog = Array.isArray(data.status_disponiveis) ? data.status_disponiveis : [];
        statusCatalog.forEach((status) => {
            const code = String(status?.codigo || '').trim();
            if (code !== '') {
                statusLabelsByCode[code] = String(status?.nome || code);
            }
        });

        // Cliente
        setText('orderStatusModalClientName', data.cliente_nome || '-');
        setText('orderStatusModalClientPhone', data.cliente_telefone ? `Telefone: ${data.cliente_telefone}` : 'Telefone: -');
        setText('orderStatusModalClientEmail', data.cliente_email ? `E-mail: ${data.cliente_email}` : 'E-mail: -');

        // Equipamento
        setText('orderStatusModalEquipName', data.equipamento_nome || '-');
        setText('orderStatusModalEquipType', data.equipamento_tipo_nome ? `Tipo: ${data.equipamento_tipo_nome}` : 'Tipo: -');
        setText('orderStatusModalEquipSerial', data.equipamento_numero_serie ? `Nº de série: ${data.equipamento_numero_serie}` : 'Nº de série: -');

        // Diagnóstico e solução (salvos junto com o status); o campo de
        // procedimentos sempre começa vazio, pois cada envio cria uma nova
        // entrada no histórico em vez de sobrescrever um valor único.
        if (proceduresEl) proceduresEl.value = '';
        if (diagnosisEl) diagnosisEl.value = String(data.diagnostico_tecnico || '');
        if (solutionEl) solutionEl.value = String(data.solucao_aplicada || '');
        renderProceduresHistory(data.procedimentos_historico);

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
                opt.dataset.congelaPrazo = etapa.congela_prazo ? '1' : '0';
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
        // "Salvar status" fica sempre liberado: também é usado para salvar
        // diagnóstico/solução sem necessariamente trocar o status da OS.
        if (submitBtn) submitBtn.disabled = false;

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
        hideNovoPrazoSection();

        const statusTabBtn = document.getElementById('orderStatusModalTabStatusBtn');
        if (statusTabBtn && typeof bootstrap !== 'undefined') {
            bootstrap.Tab.getOrCreateInstance(statusTabBtn).show();
        }
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

    // Ao mudar o select (o botão "Salvar status" já fica sempre liberado).
    // O select vira Select2 (ver desktop.js): escolher uma opção pela UI do
    // Select2 dispara `change` só via jQuery, não propaga pro addEventListener
    // nativo — por isso o binding duplo abaixo (nativo cobre o dispatchEvent
    // programático dos botões de ação rápida; jQuery cobre a UI de verdade).
    const handleStatusSelectChange = () => {
        const selectedName = selectEl.selectedOptions[0]?.text || '';
        setText(
            'orderStatusModalTargetHint',
            selectEl.value ? `Fluxo selecionado: ${selectedName}.` : 'Selecione um fluxo para continuar.'
        );

        // A OS estava com o prazo congelado e o destino escolhido não congela
        // mais — é uma reabertura manual, então pede confirmação do novo prazo
        // (ver OrderStatus::DEADLINE_FREEZE_CODES no backend).
        const destinoCongelaPrazo = selectEl.selectedOptions[0]?.dataset.congelaPrazo === '1';
        const precisaRedefinirPrazo = statusCongelaPrazoAtual && selectEl.value !== '' && !destinoCongelaPrazo;

        if (precisaRedefinirPrazo) {
            novoPrazoWrapper?.classList.remove('d-none');
            if (novoPrazoInput) {
                novoPrazoInput.disabled = false;
                novoPrazoInput.required = true;
                if (!novoPrazoInput.value) novoPrazoInput.value = suggestedNovoPrazo();
            }
        } else {
            hideNovoPrazoSection();
        }
    };

    selectEl?.addEventListener('change', handleStatusSelectChange);
    if (selectEl && typeof window.jQuery !== 'undefined') {
        window.jQuery(selectEl).on('change', handleStatusSelectChange);
    }

    // Salvar um novo procedimento executado (aba "Procedimentos"): cada clique
    // cria uma entrada nova no histórico, com data e técnico responsável.
    proceduresSaveBtn?.addEventListener('click', async () => {
        if (!currentOrderId) return;

        const descricao = proceduresEl?.value.trim() || '';
        if (descricao === '') {
            showToast('Descreva o procedimento executado antes de salvar.', 'error');
            return;
        }

        const originalHtml = proceduresSaveBtn.innerHTML;
        proceduresSaveBtn.disabled = true;
        proceduresSaveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';

        try {
            const res = await fetch(buildProceduresUrl(currentOrderId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ descricao }),
            });

            const result = await res.json();

            if (!res.ok || result.error) {
                throw new Error(result.error || result.message || 'Erro ao salvar o procedimento.');
            }

            if (proceduresEl) proceduresEl.value = '';
            renderProceduresHistory(result.procedimentos_historico);
            showToast(result.message || 'Procedimento registrado com sucesso.', 'success');
        } catch (err) {
            showToast(err.message || 'Não foi possível salvar o procedimento. Tente novamente.', 'error');
        } finally {
            proceduresSaveBtn.disabled = false;
            proceduresSaveBtn.innerHTML = originalHtml;
        }
    });

    // Submissão via AJAX
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!currentOrderId) return;

        const novoPrazoVisivel = novoPrazoWrapper && !novoPrazoWrapper.classList.contains('d-none');
        if (novoPrazoVisivel && !novoPrazoInput?.value) {
            showToast('Informe o novo prazo de entrega para reabrir esta OS.', 'error');
            return;
        }

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
