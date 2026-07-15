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

    // --- seleção do acervo ----------------------------------------------------

    const updateActionBar = () => {
        const count = state.selected.size;
        const countEl = document.querySelector('[data-doc-selection-count]');
        if (countEl) {
            countEl.textContent = count === 0
                ? 'Nenhum documento selecionado'
                : count === 1
                    ? '1 documento selecionado'
                    : `${count} documentos selecionados`;
        }

        ['zip', 'print', 'share', 'send'].forEach((action) => {
            const button = document.querySelector(`[data-doc-action-${action}]`);
            if (button instanceof HTMLButtonElement) {
                button.disabled = count === 0;
            }
        });
    };

    const applySelectionToDom = () => {
        const checkboxes = Array.from(document.querySelectorAll('[data-document-row-checkbox]'));
        const availableIds = new Set(checkboxes.map((checkbox) => checkbox.value));

        Array.from(state.selected).forEach((id) => {
            if (!availableIds.has(id)) {
                state.selected.delete(id);
            }
        });

        checkboxes.forEach((checkbox) => {
            checkbox.checked = state.selected.has(checkbox.value);
        });

        updateActionBar();
    };

    // Recalcula state.selected direto dos checkboxes marcados agora, em vez
    // de confiar no Set acumulado — chamado antes de qualquer ação crítica
    // (ZIP/imprimir/enviar/link) pra nunca disparar com ids fantasmas caso
    // algum evento de change tenha sido perdido em algum swap de fragment.
    const resyncSelectionFromLiveDom = () => {
        const checkboxes = Array.from(document.querySelectorAll('[data-document-row-checkbox]'));
        state.selected = new Set(
            checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value)
        );
        updateActionBar();
    };

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

    // --- refresh sem reload -----------------------------------------------------

    const toggleLiveIndicator = (visible) => {
        const el = document.querySelector('[data-doc-sends-live]');
        if (el) {
            el.classList.toggle('d-none', !visible);
        }
    };

    const refreshState = async () => {
        if (state.refreshing) {
            return;
        }

        state.refreshing = true;

        try {
            const payload = await requestJson(config.routes.state);

            Object.entries(payload.fragments || {}).forEach(([key, html]) => {
                const target = document.querySelector(`[data-fragment="${key}"]`);
                if (target) {
                    target.innerHTML = html;
                }
            });

            state.pendingSends = Number(payload.meta?.pending_sends || 0);
            applySelectionToDom();
            applyCatalogSelectionToDom();
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

            refreshState();
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

    const generateTypes = async (types) => {
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
                body: { tipos: uniqueTypes },
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

    // --- modal de envio ------------------------------------------------------------

    const getSelectedDocumentMeta = () => Array.from(document.querySelectorAll('[data-document-row-checkbox]'))
        .filter((checkbox) => state.selected.has(checkbox.value))
        .map((checkbox) => ({
            id: checkbox.value,
            templateCode: checkbox.dataset.documentTemplateCode || '',
            suggestedMessage: checkbox.dataset.documentSuggestedMessage || '',
            label: checkbox.dataset.documentLabel || 'Documento',
        }));

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

    const buildSelectionUrl = (baseUrl) => {
        const format = document.getElementById('docActionFormat')?.value || 'a4';
        const params = new URLSearchParams();
        state.selected.forEach((id) => params.append('document_ids[]', id));
        params.set('format', format);

        return `${baseUrl}?${params.toString()}`;
    };

    // Ação de uma linha específica do catálogo (um só documento, o mais
    // recente daquele tipo) não deve depender da seleção via checkbox do
    // acervo — troca state.selected pelo id explícito, sem reler os
    // checkboxes (resyncSelectionFromLiveDom reverteria essa troca).
    const applyExplicitSelection = (explicitIds) => {
        if (!explicitIds) {
            resyncSelectionFromLiveDom();
            return;
        }

        state.selected = new Set(explicitIds.map(String));
        applySelectionToDom();
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

        if (event.target.closest('[data-doc-select-all]')) {
            document.querySelectorAll('[data-document-row-checkbox]').forEach((checkbox) => {
                checkbox.checked = true;
                state.selected.add(checkbox.value);
            });
            updateActionBar();
            return;
        }

        if (event.target.closest('[data-doc-clear-all]')) {
            document.querySelectorAll('[data-document-row-checkbox]').forEach((checkbox) => {
                checkbox.checked = false;
            });
            state.selected.clear();
            updateActionBar();
            return;
        }

        if (event.target.closest('[data-doc-action-zip]')) {
            downloadZip();
            return;
        }

        if (event.target.closest('[data-doc-action-print]')) {
            openPrint();
            return;
        }

        if (event.target.closest('[data-doc-action-share]')) {
            openShareModal();
            return;
        }

        if (event.target.closest('[data-doc-action-send]')) {
            openSendModal();
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
        if (event.target.matches?.('[data-document-row-checkbox]')) {
            const checkbox = event.target;
            if (checkbox.checked) {
                state.selected.add(checkbox.value);
            } else {
                state.selected.delete(checkbox.value);
            }
            updateActionBar();
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
