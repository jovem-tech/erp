(function () {
    const form = document.querySelector('[data-integration-form]');
    const panel = document.querySelector('[data-integration-panel]');

    if (!(form instanceof HTMLFormElement) || !(panel instanceof HTMLElement)) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const routes = {
        testConnection: panel.dataset.routeTestConnection || '',
        sendTest: panel.dataset.routeSendTest || '',
        selfCheck: panel.dataset.routeSelfCheck || '',
        status: panel.dataset.routeStatus || '',
        qr: panel.dataset.routeQr || '',
        restart: panel.dataset.routeRestart || '',
        logout: panel.dataset.routeLogout || '',
        start: panel.dataset.routeStart || '',
    };

    const els = {
        directProvider: form.querySelector('[data-integration-provider]'),
        bulkProvider: form.querySelector('#whatsappBulkProvider'),
        currentChip: panel.querySelector('[data-integration-current-chip]'),
        bulkChip: panel.querySelector('[data-integration-bulk-chip]'),
        statusBadge: panel.querySelector('[data-integration-status-badge]'),
        providerLabel: panel.querySelector('[data-integration-provider-label]'),
        previewImage: panel.querySelector('[data-integration-preview-image]'),
        previewPlaceholder: panel.querySelector('[data-integration-preview-placeholder]'),
        previewProvider: panel.querySelector('[data-integration-preview-provider]'),
        previewBulk: panel.querySelector('[data-integration-preview-bulk]'),
        previewStatus: panel.querySelector('[data-integration-preview-status]'),
        previewLastCheck: panel.querySelector('[data-integration-preview-last-check]'),
        previewAccountName: panel.querySelector('[data-integration-preview-account-name]'),
        previewAccountNumber: panel.querySelector('[data-integration-preview-account-number]'),
        previewAccountPlatform: panel.querySelector('[data-integration-preview-account-platform]'),
        previewWebhook: panel.querySelector('[data-integration-preview-webhook]'),
        previewMessage: panel.querySelector('[data-integration-preview-message]'),
        actionButtons: Array.from(panel.querySelectorAll('[data-config-action]')),
    };

    const connectedImage = panel.dataset.connectedImage || '';
    const providerValue = () => (els.directProvider instanceof HTMLSelectElement ? String(els.directProvider.value || '') : '');
    const bulkValue = () => (els.bulkProvider instanceof HTMLSelectElement ? String(els.bulkProvider.value || '') : '');
    const providerLabel = () => getSelectedText(els.directProvider, 'Integração');
    const bulkLabel = () => getSelectedText(els.bulkProvider, 'Meta Oficial (futuro)');

    const actionLabels = {
        testConnection: {
            busy: 'Testando conexão...',
            success: 'Conexão validada',
            error: 'Não foi possível testar a conexão',
        },
        sendTest: {
            busy: 'Enviando mensagem...',
            success: 'Mensagem de teste enviada',
            error: 'Não foi possível enviar a mensagem',
        },
        selfCheck: {
            busy: 'Executando self-check...',
            success: 'Self-check concluído',
            error: 'Self-check falhou',
        },
        status: {
            busy: 'Consultando status...',
            success: 'Status consultado',
            error: 'Não foi possível consultar o status',
        },
        qr: {
            busy: 'Buscando QR Code...',
            success: 'QR Code atualizado',
            error: 'Não foi possível carregar o QR Code',
        },
        restart: {
            busy: 'Reiniciando gateway...',
            success: 'Gateway reiniciado',
            error: 'Não foi possível reiniciar o gateway',
        },
        logout: {
            busy: 'Desconectando gateway...',
            success: 'Gateway desconectado',
            error: 'Não foi possível desconectar o gateway',
        },
        start: {
            busy: 'Iniciando servidor...',
            success: 'Inicialização solicitada',
            error: 'Não foi possível iniciar o servidor',
        },
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const getSelectedText = (select, fallback = '') => {
        if (!(select instanceof HTMLSelectElement)) {
            return fallback;
        }

        const option = select.selectedOptions?.[0];
        const text = String(option?.textContent || select.value || fallback).trim();

        return text === '' ? fallback : text;
    };

    const getReadableDateTime = () => new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date());

    const collectPayload = () => {
        const payload = new URLSearchParams();

        new FormData(form).forEach((value, key) => {
            if (['_token', '_method'].includes(key)) {
                return;
            }

            if (typeof value === 'string') {
                payload.append(key, value);
            }
        });

        return payload;
    };

    const buildFormBody = (extra = {}) => {
        const body = new FormData();

        new FormData(form).forEach((value, key) => {
            if (['_token', '_method'].includes(key)) {
                return;
            }

            body.append(key, value);
        });

        Object.entries(extra).forEach(([key, value]) => {
            if (value === null || value === undefined || value === '') {
                return;
            }

            body.set(key, String(value));
        });

        return body;
    };

    const buildUrl = (baseUrl, extra = {}) => {
        const url = new URL(baseUrl, window.location.origin);
        const payload = collectPayload();

        Object.entries(extra).forEach(([key, value]) => {
            if (value === null || value === undefined || value === '') {
                return;
            }

            payload.set(key, String(value));
        });

        payload.forEach((value, key) => {
            url.searchParams.set(key, value);
        });

        return url.toString();
    };

    const updateChips = () => {
        const directText = providerLabel();
        const bulkText = bulkLabel();

        if (els.currentChip instanceof HTMLElement) {
            els.currentChip.textContent = directText;
        }

        if (els.bulkChip instanceof HTMLElement) {
            els.bulkChip.textContent = `Massa: ${bulkText}`;
        }

        if (els.providerLabel instanceof HTMLElement) {
            els.providerLabel.textContent = directText;
        }

        if (els.previewProvider instanceof HTMLElement) {
            els.previewProvider.textContent = directText;
        }

        if (els.previewBulk instanceof HTMLElement) {
            els.previewBulk.textContent = bulkText;
        }
    };

    const setBadgeState = (tone, text) => {
        if (!(els.statusBadge instanceof HTMLElement)) {
            return;
        }

        els.statusBadge.className = `badge rounded-pill text-bg-${tone}`;
        els.statusBadge.textContent = text;
    };

    const setPreviewTone = (tone) => {
        if (!(els.previewStatus instanceof HTMLElement) || !(els.previewMessage instanceof HTMLElement)) {
            return;
        }

        els.previewStatus.dataset.tone = tone;
        els.previewMessage.dataset.tone = tone;
    };

    const setPreviewText = (text) => {
        if (els.previewStatus instanceof HTMLElement) {
            els.previewStatus.textContent = text;
        }

        if (els.previewMessage instanceof HTMLElement) {
            els.previewMessage.textContent = text;
        }
    };

    const setPreviewImage = (src, alt = 'Pré-visualização do gateway') => {
        if (!(els.previewImage instanceof HTMLImageElement) || !(els.previewPlaceholder instanceof HTMLElement)) {
            return;
        }

        if (src) {
            els.previewImage.src = src;
            els.previewImage.alt = alt;
            els.previewImage.classList.remove('d-none');
            els.previewPlaceholder.classList.add('d-none');
            return;
        }

        els.previewImage.classList.add('d-none');
        els.previewPlaceholder.classList.remove('d-none');

        if (connectedImage) {
            els.previewImage.src = connectedImage;
        }
    };

    const setPreviewDetails = (response) => {
        const data = getNested(response, ['response', 'data']) ?? getNested(response, ['response']) ?? {};
        const account = getNested(data, ['account']) ?? {};

        maybeSetValue(els.previewAccountName, firstString([
            account.pushname,
            account.name,
            data.account_name,
            data.name,
            response.account_name,
        ]));

        maybeSetValue(els.previewAccountNumber, firstString([
            account.number,
            data.number,
            response.number,
        ]));

        maybeSetValue(els.previewAccountPlatform, firstString([
            account.platform,
            data.platform,
            response.platform,
            response.provider,
        ]));

        maybeSetValue(els.previewWebhook, firstString([
            data.webhook ?? data.source ?? response.provider,
            response.provider,
        ], ''));
    };

    const firstString = (values, fallback = '-') => {
        for (const value of values) {
            if (value === null || value === undefined) {
                continue;
            }

            const text = String(value).trim();
            if (text !== '') {
                return text;
            }
        }

        return fallback;
    };

    const getNested = (source, path) => {
        let current = source;

        for (const segment of path) {
            if (current === null || current === undefined || typeof current !== 'object') {
                return undefined;
            }

            current = current[segment];
        }

        return current;
    };

    const setValue = (element, value) => {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        element.textContent = String(value ?? '-');
    };

    const maybeSetValue = (element, value) => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        setValue(element, value);
    };

    const extractQrSource = (response) => {
        const candidates = [
            getNested(response, ['response', 'data', 'qr']),
            getNested(response, ['response', 'data', 'qrcode']),
            getNested(response, ['response', 'data', 'image']),
            getNested(response, ['response', 'data', 'base64']),
            getNested(response, ['response', 'qr']),
            getNested(response, ['response', 'qrcode']),
            getNested(response, ['response', 'image']),
            getNested(response, ['response', 'base64']),
            response.qr,
            response.qrcode,
            response.image,
            response.base64,
        ];

        for (const candidate of candidates) {
            if (typeof candidate !== 'string') {
                continue;
            }

            const value = candidate.trim();
            if (value === '') {
                continue;
            }

            if (value.startsWith('data:image/') || value.startsWith('http://') || value.startsWith('https://')) {
                return value;
            }

            if (/^[A-Za-z0-9+/=]+$/.test(value) && value.length > 120) {
                return `data:image/png;base64,${value}`;
            }
        }

        return '';
    };

    const notify = (icon, title, text = '') => {
        if (typeof window.Swal === 'undefined') {
            return;
        }

        window.Swal.fire({
            icon,
            title,
            text,
            timer: icon === 'error' ? undefined : 2500,
            showConfirmButton: icon === 'error',
            toast: icon !== 'error',
            position: icon !== 'error' ? 'top-end' : undefined,
        });
    };

    const renderErrorDetails = (details) => {
        if (details === null || details === undefined || details === '') {
            return '';
        }

        if (Array.isArray(details)) {
            const items = details
                .map((item) => `<li>${escapeHtml(item)}</li>`)
                .join('');

            return items ? `<ul class="text-start mb-0 ps-3">${items}</ul>` : '';
        }

        if (typeof details === 'object') {
            const items = Object.entries(details)
                .map(([field, messages]) => {
                    if (Array.isArray(messages)) {
                        const list = messages.map((item) => `<li>${escapeHtml(item)}</li>`).join('');
                        return `<li><strong>${escapeHtml(field)}</strong><ul class="ps-3 mb-0">${list}</ul></li>`;
                    }

                    return `<li><strong>${escapeHtml(field)}</strong>: ${escapeHtml(String(messages))}</li>`;
                })
                .join('');

            return items ? `<ul class="text-start mb-0 ps-3">${items}</ul>` : '';
        }

        return `<p class="mb-0">${escapeHtml(String(details))}</p>`;
    };

    const showRequestError = (title, message, details = null) => {
        if (typeof window.Swal === 'undefined') {
            return;
        }

        const detailHtml = renderErrorDetails(details);
        const body = detailHtml !== ''
            ? `<div class="text-start"><p class="mb-2">${escapeHtml(message)}</p>${detailHtml}</div>`
            : escapeHtml(message);

        window.Swal.fire({
            icon: 'error',
            title,
            html: body,
            confirmButtonText: 'Entendi',
        });
    };

    const setButtonLoading = (button, loading, busyLabel) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        if (loading) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }

            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${escapeHtml(busyLabel)}`;
            return;
        }

        button.disabled = false;
        if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
        }
    };

    const setPanelLoading = (loading, busyLabel = '') => {
        els.actionButtons.forEach((button) => setButtonLoading(button, loading, busyLabel));
    };

    const requestAction = async (actionKey, options = {}) => {
        const route = routes[actionKey];
        if (!route) {
            throw new Error(`Rota inexistente para a ação ${actionKey}.`);
        }

        const method = String(options.method || 'POST').toUpperCase();
        const body = options.body ?? null;
        const extraQuery = options.extraQuery ?? {};
        const url = method === 'GET' ? buildUrl(route, extraQuery) : route;

        const requestOptions = {
            method,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        };

        if (method !== 'GET') {
            requestOptions.headers['X-CSRF-TOKEN'] = csrfToken;

            if (body instanceof FormData) {
                requestOptions.body = body;
            } else if (body instanceof URLSearchParams) {
                requestOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
                requestOptions.body = body.toString();
            } else if (body !== null) {
                requestOptions.headers['Content-Type'] = 'application/json';
                requestOptions.body = JSON.stringify(body);
            }
        }

        const response = await fetch(url, requestOptions);

        if (response.redirected) {
            window.location.href = response.url;
            return null;
        }

        const payload = await response.json().catch(() => ({}));

        if (response.status === 401) {
            window.location.href = payload?.redirect || '/login';
            return null;
        }

        if (response.status === 403) {
            window.location.href = payload?.redirect || '/dashboard';
            return null;
        }

        if (!response.ok || payload?.success === false) {
            const error = new Error(payload?.message || 'Falha ao processar a solicitação.');
            error.status = response.status;
            error.details = payload?.details ?? payload?.errors ?? null;
            throw error;
        }

        return payload;
    };

    const applyActionResult = (actionKey, result) => {
        const ok = Boolean(result?.ok);
        const message = String(result?.message || (ok ? 'Operação concluída com sucesso.' : 'Falha ao processar a solicitação.'));
        const label = actionLabels[actionKey] || actionLabels.status;

        setBadgeState(ok ? 'success' : 'danger', ok ? label.success : label.error);
        setPreviewTone(ok ? 'success' : 'danger');
        setPreviewText(message);
        setValue(els.previewLastCheck, getReadableDateTime());
        setPreviewDetails(result ?? {});

        if (ok) {
            const qrSource = actionKey === 'qr' ? extractQrSource(result ?? {}) : '';
            if (qrSource !== '') {
                setPreviewImage(qrSource, 'QR Code do gateway');
            } else if (connectedImage !== '') {
                setPreviewImage(connectedImage, 'Gateway conectado');
            }

            notify('success', label.success, message);
            return;
        }

        setPreviewImage('');
        notify('error', label.error, message);
    };

    const runAction = async (button, actionKey, options = {}) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        const label = actionLabels[actionKey] || actionLabels.status;
        setPanelLoading(true, label.busy);

        try {
            const payload = await requestAction(actionKey, options);
            if (payload?.result) {
                applyActionResult(actionKey, payload.result);
            }
        } catch (error) {
            if (window.DesktopUi && typeof window.DesktopUi.logError === 'function') {
                window.DesktopUi.logError('configurations-integrations', error, {
                    action: actionKey,
                });
            } else {
                console.error('[Desktop][configurations-integrations]', error);
            }

            showRequestError(
                label.error,
                error?.message || 'Não foi possível concluir a ação agora.',
                error?.details ?? null
            );
        } finally {
            setPanelLoading(false);
            updateChips();
        }
    };

    const runSelfCheck = async (button) => {
        const currentProvider = providerValue();

        if (!['api_whats_local', 'api_whats_linux'].includes(currentProvider)) {
            if (typeof window.Swal !== 'undefined') {
                window.Swal.fire({
                    icon: 'info',
                    title: 'Self-check indisponível',
                    text: 'Esse diagnóstico usa apenas os gateways local ou Linux.',
                });
            }

            return;
        }

        await runAction(button, 'selfCheck', {
            method: 'POST',
            body: buildFormBody(),
        });
    };

    const init = () => {
        updateChips();

        if (els.statusBadge instanceof HTMLElement) {
            setPreviewTone('secondary');
        }

        if (els.directProvider instanceof HTMLSelectElement) {
            els.directProvider.addEventListener('change', updateChips);
        }

        if (els.bulkProvider instanceof HTMLSelectElement) {
            els.bulkProvider.addEventListener('change', updateChips);
        }

        els.actionButtons.forEach((button) => {
            const actionKey = button.getAttribute('data-config-action') || '';

            button.addEventListener('click', () => {
                if (actionKey === '') {
                    return;
                }

                if (actionKey === 'self-check' || actionKey === 'selfCheck') {
                    runSelfCheck(button);
                    return;
                }

                if (actionKey === 'test-connection') {
                    runAction(button, 'testConnection', {
                        method: 'POST',
                        body: buildFormBody(),
                    });
                    return;
                }

                if (actionKey === 'send-test') {
                    runAction(button, 'sendTest', {
                        method: 'POST',
                        body: buildFormBody(),
                    });
                    return;
                }

                if (actionKey === 'status') {
                    runAction(button, 'status', {
                        method: 'GET',
                    });
                    return;
                }

                if (actionKey === 'qr') {
                    runAction(button, 'qr', {
                        method: 'GET',
                    });
                    return;
                }

                if (actionKey === 'restart') {
                    runAction(button, 'restart', {
                        method: 'POST',
                        body: buildFormBody(),
                    });
                    return;
                }

                if (actionKey === 'logout') {
                    runAction(button, 'logout', {
                        method: 'POST',
                        body: buildFormBody(),
                    });
                    return;
                }

                if (actionKey === 'start') {
                    runAction(button, 'start', {
                        method: 'POST',
                        body: buildFormBody(),
                    });
                }
            });
        });
    };

    init();
})();

(function () {
    const subtabs = Array.from(document.querySelectorAll('[data-config-subtab]'));
    const subpanels = Array.from(document.querySelectorAll('[data-config-subpanel]'));

    if (subtabs.length === 0 || subpanels.length === 0) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const setActiveSubtab = (name) => {
        subtabs.forEach((button) => {
            const active = button.getAttribute('data-config-subtab') === name;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        subpanels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.getAttribute('data-config-subpanel') === name);
        });
    };

    subtabs.forEach((button) => {
        button.addEventListener('click', () => {
            setActiveSubtab(button.getAttribute('data-config-subtab') || '');
        });
    });

    const notifySub = (icon, title, text = '') => {
        if (typeof window.Swal === 'undefined') {
            return;
        }

        window.Swal.fire({
            icon,
            title,
            text,
            timer: icon === 'error' ? undefined : 2500,
            showConfirmButton: icon === 'error',
            toast: icon !== 'error',
            position: icon !== 'error' ? 'top-end' : undefined,
        });
    };

    const setButtonBusy = (button, busy, busyLabel) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        if (busy) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }

            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${busyLabel}`;
            return;
        }

        button.disabled = false;
        if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
        }
    };

    const postForm = async (url, fields) => {
        const body = new FormData();
        Object.entries(fields).forEach(([key, value]) => body.append(key, value ?? ''));

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body,
        });

        const payload = await response.json().catch(() => ({}));

        if (response.status === 401) {
            window.location.href = payload?.redirect || '/login';
            return null;
        }

        if (!response.ok || payload?.success === false) {
            throw new Error(payload?.message || 'Falha ao processar a solicitação.');
        }

        return payload;
    };

    const paymentsPanel = document.querySelector('[data-payments-panel]');
    if (paymentsPanel instanceof HTMLElement) {
        const testRoute = paymentsPanel.dataset.routeTestPayment || '';

        paymentsPanel.querySelectorAll('[data-payment-test]').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            button.addEventListener('click', async () => {
                const provider = button.getAttribute('data-payment-test') || '';
                const fields = {
                    provider,
                    pagamentos_mercadopago_access_token: document.getElementById('mercadoPagoAccessToken')?.value || '',
                    pagamentos_mercadopago_public_key: document.getElementById('mercadoPagoPublicKey')?.value || '',
                    pagamentos_asaas_base_url: document.getElementById('asaasBaseUrl')?.value || '',
                    pagamentos_asaas_api_key: document.getElementById('asaasApiKey')?.value || '',
                };

                setButtonBusy(button, true, 'Testando...');

                try {
                    const payload = await postForm(testRoute, fields);
                    const result = payload?.result || {};

                    if (result.ok) {
                        notifySub('success', 'Conexão validada', result.message || 'Conexão validada com sucesso.');
                    } else {
                        notifySub('error', 'Falha na validação', result.message || 'Não foi possível validar a conexão.');
                    }
                } catch (error) {
                    notifySub('error', 'Falha na validação', error?.message || 'Não foi possível concluir a ação agora.');
                } finally {
                    setButtonBusy(button, false);
                }
            });
        });
    }

    const emailPanel = document.querySelector('[data-email-panel]');
    if (emailPanel instanceof HTMLElement) {
        const sendRoute = emailPanel.dataset.routeSendTest || '';
        const sendButton = emailPanel.querySelector('[data-email-test-send]');

        if (sendButton instanceof HTMLButtonElement) {
            sendButton.addEventListener('click', async () => {
                const destination = document.getElementById('smtpTestEmail')?.value || '';

                if (destination.trim() === '') {
                    notifySub('info', 'Informe o e-mail de teste', 'Preencha o campo "E-mail para teste" antes de enviar.');
                    return;
                }

                const fields = {
                    email: destination,
                    smtp_host: document.getElementById('smtpHost')?.value || '',
                    smtp_port: document.getElementById('smtpPort')?.value || '',
                    smtp_crypto: document.getElementById('smtpCrypto')?.value || '',
                    smtp_timeout: document.getElementById('smtpTimeout')?.value || '',
                    smtp_user: document.getElementById('smtpUser')?.value || '',
                    smtp_pass: document.getElementById('smtpPass')?.value || '',
                    smtp_from_email: document.getElementById('smtpFromEmail')?.value || '',
                    smtp_from_name: document.getElementById('smtpFromName')?.value || '',
                };

                setButtonBusy(sendButton, true, 'Enviando...');

                try {
                    const payload = await postForm(sendRoute, fields);
                    const result = payload?.result || {};

                    if (result.ok) {
                        notifySub('success', 'E-mail enviado', result.message || 'E-mail de teste enviado com sucesso.');
                    } else {
                        notifySub('error', 'Falha no envio', result.message || 'Não foi possível enviar o e-mail de teste.');
                    }
                } catch (error) {
                    notifySub('error', 'Falha no envio', error?.message || 'Não foi possível concluir a ação agora.');
                } finally {
                    setButtonBusy(sendButton, false);
                }
            });
        }
    }
})();
