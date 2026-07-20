(function () {
    const config = window.__ORDER_DOCUMENTS_CENTER;

    if (!config) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const state = {
        selected: new Set(),
        catalogSelected: new Set(),
        pendingSends: Number(config.pendingSends || 0),
        pollTimer: null,
        pollAttempts: 0,
        refreshing: false,
        busy: { generate: false, send: false, share: false, archive: false },
    };

    // --- helpers reaproveitados do padrão de equipments-create.js -----------

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const getModal = (element) => (element ? bootstrap.Modal.getOrCreateInstance(element) : null);

    const showToast = (icon, title) => {
        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({
            toast: true,
            position: 'top-end',
            timer: 3600,
            timerProgressBar: true,
            showConfirmButton: false,
            icon,
            title,
        });
    };

    const showAlert = (icon, title, text = '') => {
        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({ icon, title, text });
    };

    const extractErrorMessages = (details) => {
        if (Array.isArray(details)) {
            return details.map((message) => String(message || '').trim()).filter(Boolean);
        }

        if (details && typeof details === 'object') {
            return Object.values(details)
                .flat()
                .map((message) => String(message || '').trim())
                .filter(Boolean);
        }

        return [];
    };

    const requestJson = async (url, { method = 'GET', body = null } = {}) => {
        const options = {
            method,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        };

        if (method !== 'GET' && body !== null) {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-TOKEN'] = csrfToken;
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload.success === false) {
            const error = new Error(payload.message || 'Falha ao processar a solicitação.');
            error.status = response.status;
            error.details = payload.errors || null;
            throw error;
        }

        return payload;
    };

    const setButtonLoading = (button, loading, loadingLabel = 'Aguarde...') => {
        if (!(button instanceof HTMLElement)) {
            return;
        }

        if (loading) {
            if (button.dataset.originalHtml === undefined) {
                button.dataset.originalHtml = button.innerHTML;
            }

            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${loadingLabel}`;
        } else {
            button.disabled = false;

            if (button.dataset.originalHtml !== undefined) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
        }
    };

    const renderModalErrors = (boxId, messages, fallback) => {
        const box = document.getElementById(boxId);
        if (!box) {
            return;
        }

        const items = Array.isArray(messages) ? messages.filter(Boolean) : [];
        box.innerHTML = items.length > 0
            ? `<ul class="mb-0 ps-3">${items.map((message) => `<li>${escapeHtml(message)}</li>`).join('')}</ul>`
            : escapeHtml(fallback || 'Não foi possível concluir a ação.');
        box.classList.remove('d-none');
    };

    const clearModalErrors = (boxId) => {
        const box = document.getElementById(boxId);
        if (!box) {
            return;
        }

        box.classList.add('d-none');
        box.innerHTML = '';
    };

    // --- seleção de documento (ações por linha do catálogo) --------------------
    // A tabela de acervo com checkboxes foi removida (a versão agora se
    // escolhe direto na linha do tipo, via data-doc-version-select) — cada
    // ação de linha (ZIP/imprimir/link/enviar) passa sempre um id explícito,
    // sem depender de nenhum checkbox existir no DOM.

    // --- seleção do catálogo ---------------------------------------------------

    const updateCatalogBar = () => {
        const count = state.catalogSelected.size;
        const button = document.querySelector('[data-doc-generate-batch]');
        if (button instanceof HTMLButtonElement) {
            button.innerHTML = `<i class="bi bi-file-earmark-plus me-2"></i>Gerar selecionados (${count})`;
            button.disabled = count === 0;
        }
    };

    const applyCatalogSelectionToDom = () => {
        const checkboxes = Array.from(document.querySelectorAll('[data-catalog-checkbox]'));
        const availableTypes = new Set(checkboxes.map((checkbox) => checkbox.value));

        Array.from(state.catalogSelected).forEach((type) => {
            if (!availableTypes.has(type)) {
                state.catalogSelected.delete(type);
            }
        });

        checkboxes.forEach((checkbox) => {
            checkbox.checked = state.catalogSelected.has(checkbox.value);
        });

        updateCatalogBar();
    };

    // --- seleção de versão por linha (coluna "Versão" do catálogo) -------------

    // Troca qual documento (versão) é "o atual" da linha: atualiza o chip de
    // status, a miniatura, os links Visualizar A4/80mm e o alvo das ações (ZIP/imprimir/
    // link/enviar/arquivar) para a versão escolhida no <select>, sem reload.
    const applyVersionSelection = (selectEl) => {
        const row = selectEl.closest('[data-doc-type-card]');
        const option = selectEl.selectedOptions[0];
        if (!row || !option) return;

        const documentId = selectEl.value;
        const archived = option.dataset.archived === '1';
        const a4Available = option.dataset.a4Available === '1';
        const thermalAvailable = option.dataset.thermalAvailable === '1';
        const a4Url = option.dataset.a4Url || '#';
        const thumbnailUrl = option.dataset.thumbnailUrl || '';

        row.dataset.documentSuggestedMessage = option.dataset.suggestedMessage || '';

        const statusBadge = row.querySelector('[data-doc-status-badge]');
        if (statusBadge) {
            statusBadge.classList.toggle('d-none', !archived);
        }

        const viewA4 = row.querySelector('[data-doc-view-a4]');
        if (viewA4 instanceof HTMLAnchorElement) {
            viewA4.classList.toggle('d-none', !a4Available);
            viewA4.setAttribute('href', a4Url);
        }

        const thumbnailLink = row.querySelector('[data-doc-thumbnail-link]');
        if (thumbnailLink instanceof HTMLAnchorElement) {
            thumbnailLink.classList.toggle('is-unavailable', !a4Available);
            thumbnailLink.setAttribute('href', a4Available ? a4Url : '#');
            thumbnailLink.setAttribute('aria-disabled', a4Available ? 'false' : 'true');
            thumbnailLink.setAttribute('title', a4Available ? 'Abrir a versão selecionada' : 'PDF indisponível nesta versão');
        }

        const thumbnailImage = row.querySelector('[data-doc-thumbnail-image]');
        if (thumbnailImage instanceof HTMLImageElement) {
            thumbnailImage.classList.add('d-none');
            if (a4Available && thumbnailUrl !== '') {
                thumbnailImage.alt = 'Miniatura de ' + option.textContent.trim();
                thumbnailImage.setAttribute('src', thumbnailUrl);
                if (thumbnailImage.complete && thumbnailImage.naturalWidth > 0) {
                    thumbnailImage.classList.remove('d-none');
                }
            } else {
                thumbnailImage.removeAttribute('src');
            }
        }

        const view80mm = row.querySelector('[data-doc-view-80mm]');
        if (view80mm instanceof HTMLAnchorElement) {
            view80mm.classList.toggle('d-none', !thermalAvailable);
            view80mm.setAttribute('href', option.dataset.thermalUrl || '#');
        }

        ['data-doc-row-zip', 'data-doc-row-print', 'data-doc-row-share', 'data-doc-row-send'].forEach((attr) => {
            const button = row.querySelector('[' + attr + ']');
            if (button) button.setAttribute(attr, documentId);
        });

        const archiveButton = row.querySelector('[data-doc-archive-toggle]');
        if (archiveButton) {
            archiveButton.setAttribute('data-doc-archive-toggle', documentId);
            archiveButton.setAttribute('data-archive', archived ? '0' : '1');

            const icon = archiveButton.querySelector('i');
            if (icon) icon.className = 'bi ' + (archived ? 'bi-box-arrow-up' : 'bi-archive') + ' me-2';

            const label = archiveButton.querySelector('[data-doc-archive-label]');
            if (label) label.textContent = archived ? 'Reativar' : 'Arquivar';
        }
    };

    // --- refresh sem reload -----------------------------------------------------

    const toggleLiveIndicator = (visible) => {
        const el = document.querySelector('[data-doc-sends-live]');
        if (el) {
            el.classList.toggle('d-none', !visible);
        }
    };

    // `skipCatalog`: o polling periódico (a cada 5s, enquanto há envio na
    // fila) só precisa refletir o status dos envios/links — recriar o
    // innerHTML da tabela de catálogo nesse meio tempo reseta silenciosamente
    // qualquer interação em andamento do usuário ali (versão escolhida no
    // <select>, dropdown de Ações aberto). As ações que realmente alteram o
    // catálogo (gerar/enviar/compartilhar/arquivar) continuam atualizando-o
    // normalmente, pois chamam refreshState() sem essa flag.
    const refreshState = async ({ skipCatalog = false } = {}) => {
        if (state.refreshing) {
            return;
        }

        state.refreshing = true;

        try {
            const payload = await requestJson(config.routes.state);

            Object.entries(payload.fragments || {}).forEach(([key, html]) => {
                if (skipCatalog && key === 'catalog') {
                    return;
                }

                const target = document.querySelector(`[data-fragment="${key}"]`);
                if (target) {
                    target.innerHTML = html;
                }
            });

            state.pendingSends = Number(payload.meta?.pending_sends || 0);
            if (!skipCatalog) {
                applyCatalogSelectionToDom();
            }
            evaluatePolling();
        } catch (error) {
            console.error('[Documentos] Falha ao atualizar a central documental', error);
        } finally {
            state.refreshing = false;
        }
    };

    // --- polling do histórico de envios (fila) ---------------------------------

    const stopPolling = () => {
        if (state.pollTimer) {
            window.clearInterval(state.pollTimer);
            state.pollTimer = null;
        }

        toggleLiveIndicator(false);
    };

    const startPolling = () => {
        if (state.pollTimer) {
            return;
        }

        state.pollAttempts = 0;
        toggleLiveIndicator(true);

        state.pollTimer = window.setInterval(() => {
            state.pollAttempts += 1;

            if (state.pollAttempts > 60) {
                stopPolling();
                showToast('info', 'Acompanhamento automático pausado. Recarregue a página se necessário.');
                return;
            }

            refreshState({ skipCatalog: true });
        }, 5000);
    };

    const evaluatePolling = () => {
        if (state.pendingSends > 0) {
            startPolling();
        } else {
            stopPolling();
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (state.pollTimer) {
                window.clearInterval(state.pollTimer);
                state.pollTimer = null;
            }
        } else if (state.pendingSends > 0 && !state.pollTimer) {
            startPolling();
        }
    });

    // --- geração de documentos ---------------------------------------------------

    const signatureModalElement = document.getElementById('documentSignatureModal');
    const signatureModal = getModal(signatureModalElement);
    let pendingGenerationTypes = [];

    const generateTypes = (types) => {
        const uniqueTypes = Array.from(new Set(types.filter(Boolean)));
        if (uniqueTypes.length === 0 || state.busy.generate) return;
        pendingGenerationTypes = uniqueTypes;
        signatureModal?.show();
    };

    const submitGeneration = async (types, signaturePayload = {}) => {
        const uniqueTypes = Array.from(new Set(types.filter(Boolean)));
        if (uniqueTypes.length === 0 || state.busy.generate) {
            return;
        }

        state.busy.generate = true;

        uniqueTypes.forEach((type) => {
            document.querySelector(`[data-doc-type-card="${type}"]`)?.classList.add('is-generating');
        });

        const singleButtons = uniqueTypes
            .map((type) => document.querySelector(`[data-doc-generate-type="${type}"]`))
            .filter(Boolean);
        singleButtons.forEach((button) => setButtonLoading(button, true, 'Gerando...'));

        const batchButton = document.querySelector('[data-doc-generate-batch]');
        const batchWasUsed = uniqueTypes.length > 1;
        if (batchWasUsed && batchButton) {
            setButtonLoading(batchButton, true, 'Gerando...');
        }

        try {
            const payload = await requestJson(config.routes.generate, {
                method: 'POST',
                body: { tipos: uniqueTypes, ...signaturePayload },
            });

            showToast('success', payload.message || 'Documentos gerados com sucesso.');

            const failed = (payload.results || []).filter((item) => !item.ok);
            if (failed.length > 0) {
                showAlert(
                    'warning',
                    'Alguns documentos não foram gerados',
                    failed.map((item) => item.message).filter(Boolean).join('\n')
                );
            }

            const clientLinks = (payload.results || []).filter((item) => item.signature_url);
            if (clientLinks.length > 0) {
                const absoluteLinks = clientLinks.map((item) => new URL(item.signature_url, window.location.origin).toString());
                if (navigator.clipboard && absoluteLinks.length === 1) {
                    await navigator.clipboard.writeText(absoluteLinks[0]).catch(() => {});
                }
                showAlert(
                    'success',
                    'Link de assinatura criado',
                    absoluteLinks.length === 1
                        ? `Envie este link ao cliente (ele também foi copiado): ${absoluteLinks[0]}`
                        : absoluteLinks.join('\n')
                );
            }

            state.catalogSelected.clear();
            await refreshState();
        } catch (error) {
            showAlert('error', 'Falha ao gerar documentos', error.message);
        } finally {
            state.busy.generate = false;
            singleButtons.forEach((button) => setButtonLoading(button, false));
            if (batchWasUsed && batchButton) {
                setButtonLoading(batchButton, false);
            }
        }
    };

    if (signatureModalElement) {
        const modeInputs = Array.from(signatureModalElement.querySelectorAll('input[name="document_signature_mode"]'));
        const userFields = signatureModalElement.querySelector('[data-signature-user-fields]');
        const credentials = signatureModalElement.querySelector('[data-signature-credentials]');
        const userSelect = signatureModalElement.querySelector('[data-signature-user]');
        const emailInput = signatureModalElement.querySelector('[data-signature-email]');
        const passwordInput = signatureModalElement.querySelector('[data-signature-password]');

        const syncSignatureMode = () => {
            const mode = modeInputs.find((input) => input.checked)?.value || 'self';
            userFields?.classList.toggle('d-none', mode === 'self' || mode === 'client');
            credentials?.classList.toggle('d-none', mode !== 'reauth');
            if (mode !== 'reauth' && passwordInput) passwordInput.value = '';
        };

        modeInputs.forEach((input) => input.addEventListener('change', syncSignatureMode));
        userSelect?.addEventListener('change', () => {
            const selected = userSelect.selectedOptions[0];
            if (emailInput) emailInput.value = selected?.dataset.email || '';
        });

        signatureModalElement.querySelector('[data-signature-confirm]')?.addEventListener('click', async () => {
            const mode = modeInputs.find((input) => input.checked)?.value || 'self';
            const userId = Number(userSelect?.value || 0);
            if (mode !== 'self' && mode !== 'client' && userId <= 0) {
                showAlert('warning', 'Selecione o responsável', 'Escolha um usuário com assinatura cadastrada.');
                return;
            }
            if (mode === 'reauth' && (!emailInput?.value || !passwordInput?.value)) {
                showAlert('warning', 'Confirme as credenciais', 'Informe o e-mail e a senha do usuário que assinará.');
                return;
            }

            const payload = {
                signature_mode: mode,
                signature_user_id: mode === 'self' ? null : userId,
                signature_email: mode === 'reauth' ? emailInput.value : null,
                signature_password: mode === 'reauth' ? passwordInput.value : null,
            };
            signatureModal.hide();
            if (passwordInput) passwordInput.value = '';
            await submitGeneration(pendingGenerationTypes, payload);
            pendingGenerationTypes = [];
        });

        signatureModalElement.addEventListener('hidden.bs.modal', () => {
            if (passwordInput) passwordInput.value = '';
        });
        syncSignatureMode();
    }

    // --- modal de envio ------------------------------------------------------------

    // Metadados (template sugerido/mensagem/rótulo) vivem no <tr> da linha do
    // catálogo — atualizados por applyVersionSelection() a cada troca de
    // versão — e são localizados a partir do id selecionado via o botão de
    // ação daquela linha (qualquer um dos data-doc-row-* serve de âncora).
    const getSelectedDocumentMeta = () => Array.from(state.selected).map((id) => {
        const trigger = document.querySelector(`[data-doc-row-send="${id}"], [data-doc-row-zip="${id}"]`);
        const row = trigger?.closest('[data-doc-type-card]');

        return {
            id,
            templateCode: row?.dataset.documentTemplateCode || '',
            suggestedMessage: row?.dataset.documentSuggestedMessage || '',
            label: row?.dataset.documentLabel || 'Documento',
        };
    });

    const buildGenericMessage = (labels) => {
        if (labels.length === 0) {
            return config.dispatchDefaults.message || `Olá! Seguem os documentos da sua OS ${config.orderNumber}.`;
        }

        return `Olá! Seguem os documentos da sua OS ${config.orderNumber}: ${labels.join(', ')}.`;
    };

    const resolveSuggestedTemplate = (selectedMeta) => {
        const codes = Array.from(new Set(selectedMeta.map((item) => item.templateCode).filter(Boolean)));

        if (codes.length === 1) {
            return codes[0];
        }

        if (codes.length > 1) {
            return '';
        }

        return config.dispatchDefaults.templateCode || '';
    };

    const resolveSuggestedMessage = (selectedMeta, templateCode) => {
        if (selectedMeta.length === 1 && selectedMeta[0].suggestedMessage) {
            const row = selectedMeta[0];
            if (!templateCode || !row.templateCode || templateCode === row.templateCode) {
                return row.suggestedMessage;
            }
        }

        if (templateCode) {
            const option = document.querySelector(`#docSendTemplate option[value="${CSS.escape(templateCode)}"]`);
            const rendered = option?.dataset.renderedMessage || '';
            if (rendered !== '') {
                return rendered;
            }
        }

        return buildGenericMessage(selectedMeta.map((item) => item.label));
    };

    const syncSendDestinationHint = () => {
        const channelSelect = document.getElementById('docSendChannel');
        const hint = document.getElementById('docSendModalDestinationHint');
        if (!channelSelect || !hint) {
            return;
        }

        hint.textContent = channelSelect.value === 'email'
            ? 'Padrão: e-mail cadastrado do cliente.'
            : 'Padrão: telefone/WhatsApp cadastrado do cliente.';
    };

    const openSendModal = (explicitIds = null) => {
        applyExplicitSelection(explicitIds);

        if (state.selected.size === 0) {
            return;
        }

        const modalEl = document.getElementById('docSendModal');
        if (!modalEl) {
            return;
        }

        const countEl = document.getElementById('docSendModalCount');
        if (countEl) {
            countEl.textContent = state.selected.size === 1
                ? '1 documento selecionado para envio.'
                : `${state.selected.size} documentos selecionados para envio.`;
        }

        clearModalErrors('docSendModalErrors');

        const form = document.getElementById('docSendModalForm');
        form?.reset();

        const channelSelect = document.getElementById('docSendChannel');
        const destinoInput = document.getElementById('docSendDestino');
        const templateSelect = document.getElementById('docSendTemplate');
        const messageTextarea = document.getElementById('docSendMessage');

        if (channelSelect) {
            channelSelect.value = config.dispatchDefaults.channel || 'whatsapp';
        }

        const selectedMeta = getSelectedDocumentMeta();
        const suggestedTemplate = resolveSuggestedTemplate(selectedMeta);

        if (templateSelect) {
            templateSelect.value = suggestedTemplate;
            if (window.jQuery) {
                window.jQuery(templateSelect).trigger('change.select2');
            }
        }

        if (messageTextarea) {
            messageTextarea.value = resolveSuggestedMessage(selectedMeta, suggestedTemplate);
        }

        syncSendDestinationHint();

        if (destinoInput && channelSelect) {
            destinoInput.value = channelSelect.value === 'email'
                ? (config.dispatchDefaults.email || '')
                : (config.dispatchDefaults.whatsapp || '');
        }

        getModal(modalEl)?.show();
    };

    const submitSend = async (event) => {
        event.preventDefault();

        if (state.busy.send || state.selected.size === 0) {
            return;
        }

        state.busy.send = true;
        const submitButton = document.getElementById('docSendModalSubmit');
        setButtonLoading(submitButton, true, 'Enviando...');
        clearModalErrors('docSendModalErrors');

        const form = document.getElementById('docSendModalForm');
        const formData = new FormData(form);
        const payload = {
            document_ids: Array.from(state.selected).map(Number),
            channel: formData.get('channel'),
            format: formData.get('format'),
            template_code: formData.get('template_code') || '',
            destino: formData.get('destino') || '',
            message: formData.get('message') || '',
            confirmar_destino_alternativo: document.getElementById('docSendConfirmAlt')?.checked || false,
        };

        try {
            const result = await requestJson(config.routes.send, { method: 'POST', body: payload });
            getModal(document.getElementById('docSendModal'))?.hide();
            showToast('success', result.message || 'Envio documental enfileirado.');
            await refreshState();
            startPolling();
        } catch (error) {
            renderModalErrors('docSendModalErrors', extractErrorMessages(error.details), error.message);
        } finally {
            state.busy.send = false;
            setButtonLoading(submitButton, false);
        }
    };

    // --- modal de link público ------------------------------------------------------

    const resetShareModal = () => {
        document.getElementById('docShareModalFormWrap')?.classList.remove('d-none');
        document.getElementById('docShareModalResult')?.classList.add('d-none');
        document.getElementById('docShareModalSubmit')?.classList.remove('d-none');

        const cancelButton = document.getElementById('docShareModalCancel');
        if (cancelButton) {
            cancelButton.textContent = 'Cancelar';
        }
    };

    const openShareModal = (explicitIds = null) => {
        applyExplicitSelection(explicitIds);

        if (state.selected.size === 0) {
            return;
        }

        const modalEl = document.getElementById('docShareModal');
        if (!modalEl) {
            return;
        }

        resetShareModal();
        clearModalErrors('docShareModalErrors');
        document.getElementById('docShareModalForm')?.reset();

        const countEl = document.getElementById('docShareModalCount');
        if (countEl) {
            countEl.textContent = state.selected.size === 1
                ? '1 documento selecionado.'
                : `${state.selected.size} documentos selecionados.`;
        }

        getModal(modalEl)?.show();
    };

    const submitShare = async (event) => {
        event.preventDefault();

        if (state.busy.share || state.selected.size === 0) {
            return;
        }

        state.busy.share = true;
        const submitButton = document.getElementById('docShareModalSubmit');
        setButtonLoading(submitButton, true, 'Gerando...');
        clearModalErrors('docShareModalErrors');

        const form = document.getElementById('docShareModalForm');
        const formData = new FormData(form);
        const payload = {
            document_ids: Array.from(state.selected).map(Number),
            format: formData.get('format'),
            expiracao: formData.get('expiracao'),
        };

        try {
            const result = await requestJson(config.routes.share, { method: 'POST', body: payload });
            const link = result.link || {};

            showToast('success', result.message || 'Link seguro criado com sucesso.');

            document.getElementById('docShareModalFormWrap')?.classList.add('d-none');
            document.getElementById('docShareModalResult')?.classList.remove('d-none');
            submitButton?.classList.add('d-none');

            const cancelButton = document.getElementById('docShareModalCancel');
            if (cancelButton) {
                cancelButton.textContent = 'Fechar';
            }

            const urlInput = document.getElementById('docShareModalUrl');
            if (urlInput) {
                urlInput.value = link.url || '';
            }

            const expiresEl = document.getElementById('docShareModalExpires');
            if (expiresEl) {
                expiresEl.textContent = link.expires_at
                    ? `Expira em ${new Date(link.expires_at).toLocaleString('pt-BR')}.`
                    : '';
            }

            await refreshState();
        } catch (error) {
            renderModalErrors('docShareModalErrors', extractErrorMessages(error.details), error.message);
        } finally {
            state.busy.share = false;
            setButtonLoading(submitButton, false);
        }
    };

    const copyShareUrl = async () => {
        const input = document.getElementById('docShareModalUrl');
        if (!input || !input.value) {
            return;
        }

        try {
            await navigator.clipboard.writeText(input.value);
            showToast('success', 'Link copiado para a área de transferência.');
        } catch (error) {
            input.select();

            try {
                document.execCommand('copy');
                showToast('success', 'Link copiado para a área de transferência.');
            } catch (fallbackError) {
                showAlert('error', 'Não foi possível copiar', 'Selecione e copie o link manualmente.');
            }
        }
    };

    // --- arquivar / reativar ---------------------------------------------------------

    const toggleArchive = async (documentId, archive, button) => {
        if (state.busy.archive) {
            return;
        }

        state.busy.archive = true;
        setButtonLoading(button, true);

        const url = (archive ? config.routes.archiveTemplate : config.routes.unarchiveTemplate)
            .replace('__DOC__', documentId);

        try {
            const result = await requestJson(url, { method: 'POST', body: {} });
            showToast('success', result.message || 'Documento atualizado.');
            await refreshState();
        } catch (error) {
            showAlert('error', 'Falha ao atualizar documento', error.message);
        } finally {
            state.busy.archive = false;
            setButtonLoading(button, false);
        }
    };

    // --- revogar link ------------------------------------------------------------------

    const revokeLink = (linkId, button) => {
        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: 'Revogar este link?',
            text: 'Quem estiver com o link perderá o acesso imediatamente.',
            showCancelButton: true,
            confirmButtonText: 'Revogar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#dc3545',
        }).then(async (result) => {
            if (!result.isConfirmed) {
                return;
            }

            setButtonLoading(button, true);

            const url = config.routes.revokeTemplate.replace('__LINK__', linkId);

            try {
                const response = await requestJson(url, { method: 'POST', body: {} });
                showToast('success', response.message || 'Link revogado com sucesso.');
                await refreshState();
            } catch (error) {
                showAlert('error', 'Falha ao revogar link', error.message);
            } finally {
                setButtonLoading(button, false);
            }
        });
    };

    // --- ZIP / impressão -----------------------------------------------------------------

    // ZIP/impressão de uma linha do catálogo sempre pega o A4 como formato
    // (não há mais um seletor de formato na tela — quem quiser o 80mm usa
    // "Visualizar 80mm" direto).
    const buildSelectionUrl = (baseUrl) => {
        const params = new URLSearchParams();
        state.selected.forEach((id) => params.append('document_ids[]', id));
        params.set('format', 'a4');

        return `${baseUrl}?${params.toString()}`;
    };

    // Cada ação de linha do catálogo (ZIP/imprimir/link/enviar) sempre passa
    // um id explícito — não existe mais seleção via checkbox para reler.
    const applyExplicitSelection = (explicitIds) => {
        state.selected = new Set((explicitIds || []).map(String));
    };

    const downloadZip = (explicitIds = null) => {
        applyExplicitSelection(explicitIds);

        if (state.selected.size === 0) {
            return;
        }

        showToast('info', 'Preparando o ZIP para download…');
        window.location.assign(buildSelectionUrl(config.routes.download));
    };

    const openPrint = (explicitIds = null) => {
        applyExplicitSelection(explicitIds);

        if (state.selected.size === 0) {
            return;
        }

        window.open(buildSelectionUrl(config.routes.print), '_blank', 'noopener');
    };

    // --- bindings (event delegation — nunca re-bindar pós-swap dos fragments) ------------

    document.addEventListener('click', (event) => {
        const generateTypeButton = event.target.closest('[data-doc-generate-type]');
        if (generateTypeButton) {
            generateTypes([generateTypeButton.dataset.docGenerateType]);
            return;
        }

        if (event.target.closest('[data-doc-generate-batch]')) {
            generateTypes(Array.from(state.catalogSelected));
            return;
        }

        // Ações de uma linha só do catálogo — cada tipo documental já gerado
        // ganhou seu próprio botão de ZIP/imprimir/link/enviar, sem precisar
        // marcar checkbox nenhum (a confusão entre a seleção do catálogo e a
        // do acervo era exatamente por que "Baixar ZIP" parecia não funcionar).
        const zipRowButton = event.target.closest('[data-doc-row-zip]');
        if (zipRowButton) {
            downloadZip([zipRowButton.getAttribute('data-doc-row-zip')]);
            return;
        }

        const printRowButton = event.target.closest('[data-doc-row-print]');
        if (printRowButton) {
            openPrint([printRowButton.getAttribute('data-doc-row-print')]);
            return;
        }

        const shareRowButton = event.target.closest('[data-doc-row-share]');
        if (shareRowButton) {
            openShareModal([shareRowButton.getAttribute('data-doc-row-share')]);
            return;
        }

        const sendRowButton = event.target.closest('[data-doc-row-send]');
        if (sendRowButton) {
            openSendModal([sendRowButton.getAttribute('data-doc-row-send')]);
            return;
        }

        const archiveButton = event.target.closest('[data-doc-archive-toggle]');
        if (archiveButton) {
            const documentId = archiveButton.getAttribute('data-doc-archive-toggle');
            const archive = archiveButton.getAttribute('data-archive') === '1';
            toggleArchive(documentId, archive, archiveButton);
            return;
        }

        const revokeButton = event.target.closest('[data-doc-revoke-link]');
        if (revokeButton) {
            revokeLink(revokeButton.getAttribute('data-doc-revoke-link'), revokeButton);
            return;
        }

        if (event.target.closest('[data-doc-share-copy]')) {
            copyShareUrl();
        }
    });

    document.addEventListener('change', (event) => {
        if (event.target.matches?.('[data-doc-version-select]')) {
            applyVersionSelection(event.target);
            return;
        }

        if (event.target.matches?.('[data-catalog-checkbox]')) {
            const checkbox = event.target;
            if (checkbox.checked) {
                state.catalogSelected.add(checkbox.value);
            } else {
                state.catalogSelected.delete(checkbox.value);
            }
            updateCatalogBar();
            return;
        }

        if (event.target.id === 'docSendChannel') {
            syncSendDestinationHint();
            const destinoInput = document.getElementById('docSendDestino');
            if (destinoInput) {
                destinoInput.value = event.target.value === 'email'
                    ? (config.dispatchDefaults.email || '')
                    : (config.dispatchDefaults.whatsapp || '');
            }
            return;
        }

        if (event.target.id === 'docSendTemplate') {
            const messageTextarea = document.getElementById('docSendMessage');
            if (messageTextarea) {
                messageTextarea.value = resolveSuggestedMessage(getSelectedDocumentMeta(), event.target.value);
            }
        }
    });

    document.addEventListener('load', (event) => {
        if (event.target.matches?.('[data-doc-thumbnail-image]')) {
            event.target.classList.remove('d-none');
        }
    }, true);

    document.addEventListener('error', (event) => {
        if (event.target.matches?.('[data-doc-thumbnail-image]')) {
            event.target.classList.add('d-none');
        }
    }, true);

    document.addEventListener('submit', (event) => {
        if (event.target.id === 'docSendModalForm') {
            submitSend(event);
            return;
        }

        if (event.target.id === 'docShareModalForm') {
            submitShare(event);
        }
    });

    // --- init -----------------------------------------------------------------------------

    evaluatePolling();
})();
