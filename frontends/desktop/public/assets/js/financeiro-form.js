(function () {
    const config = window.__DESKTOP_FINANCEIRO_FORM || {};

    const clientSearchUrl = String(config.clientSearchUrl || '').trim();
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const els = {
        clientSelect: document.getElementById(config.clientSelectId || 'financeiroClienteId'),
        categoriaSelect: document.getElementById('financeiroCategoria'),
        valorDisplay: document.getElementById('financeiroValorDisplay'),
        valorHidden: document.getElementById('financeiroValorHidden'),
        quickClientModal: document.getElementById('quickClientModal'),
        quickClientForm: document.getElementById('quickClientForm'),
        quickClientSubmit: document.getElementById('quickClientSubmit'),
        quickClientButton: document.getElementById('btnNovoClienteFinanceiro'),
        statusSelect: document.getElementById('financeiroStatus'),
        paymentMethodSelect: document.getElementById('financeiroFormaPagamento'),
        accountWrapper: document.getElementById('financeiroContaWrapper'),
        accountSelect: document.getElementById('financeiroConta'),
    };

    const escapeHtml = (unsafe) => String(unsafe ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const normalizeText = (value) => String(value ?? '').trim();

    const initFinancialAccount = () => {
        if (!(els.accountSelect instanceof HTMLSelectElement) || !(els.statusSelect instanceof HTMLSelectElement)) { return; }

        const defaults = config.contasFinanceiras?.contas_padrao || {};
        const syncVisibility = () => {
            const required = els.statusSelect.value === 'pago';
            els.accountWrapper?.classList.toggle('d-none', !required);
            els.accountSelect.required = required;
        };
        const syncDefault = () => {
            if (!(els.paymentMethodSelect instanceof HTMLSelectElement) || els.statusSelect.value !== 'pago') { return; }
            const defaultId = defaults[els.paymentMethodSelect.value];
            if (!defaultId || !Array.from(els.accountSelect.options).some((option) => Number(option.value) === Number(defaultId))) { return; }
            els.accountSelect.value = String(defaultId);
            if (window.jQuery) { window.jQuery(els.accountSelect).trigger('change'); }
        };

        els.statusSelect.addEventListener('change', () => { syncVisibility(); syncDefault(); });
        els.paymentMethodSelect?.addEventListener('change', syncDefault);
        if (window.jQuery) {
            window.jQuery(els.statusSelect).on('change', () => { syncVisibility(); syncDefault(); });
            window.jQuery(els.paymentMethodSelect).on('change', syncDefault);
        }
        syncVisibility();
        syncDefault();
    };

    const select2Language = {
        errorLoading: () => 'Os resultados nao puderam ser carregados.',
        inputTooShort: (args) => `Digite mais ${args.minimum - args.input.length} caractere(s) para buscar`,
        noResults: () => 'Nenhum cliente encontrado.',
        searching: () => 'Buscando...',
        loadingMore: () => 'Carregando mais resultados...',
    };

    // --- Currency mask (R$ format) ---

    const rawToDisplay = (raw) => {
        const num = parseFloat(String(raw).replace(',', '.'));
        if (Number.isNaN(num) || num < 0) { return ''; }
        return new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(num);
    };

    const displayToRaw = (display) => {
        // Remove tudo exceto dígitos e vírgula
        const stripped = String(display).replace(/[^\d,]/g, '');
        if (stripped === '') { return ''; }
        const normalized = stripped.replace(',', '.');
        const num = parseFloat(normalized);
        return Number.isNaN(num) ? '' : num.toFixed(2);
    };

    const applyMaskFromDigits = (display, hidden) => {
        const digits = String(display.value).replace(/\D/g, '');
        if (digits === '' || digits === '0') {
            display.value = '';
            if (hidden) { hidden.value = ''; }
            return;
        }
        const amount = parseInt(digits, 10) / 100;
        display.value = new Intl.NumberFormat('pt-BR', {
            style: 'currency', currency: 'BRL',
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        }).format(amount);
        if (hidden) { hidden.value = amount.toFixed(2); }
    };

    const initValorMask = () => {
        const display = els.valorDisplay;
        const hidden = els.valorHidden;
        if (!(display instanceof HTMLInputElement)) { return; }

        // Pre-populate display from hidden value (edit mode / old())
        if (hidden instanceof HTMLInputElement && hidden.value !== '') {
            display.value = rawToDisplay(hidden.value);
        }

        display.addEventListener('input', () => applyMaskFromDigits(display, hidden));

        display.addEventListener('blur', () => {
            const raw = displayToRaw(display.value);
            if (raw !== '') {
                display.value = rawToDisplay(raw);
                if (hidden instanceof HTMLInputElement) { hidden.value = raw; }
            } else {
                display.value = '';
                if (hidden instanceof HTMLInputElement) { hidden.value = ''; }
            }
        });

        display.addEventListener('paste', (e) => {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData)?.getData('text') || '';
            const raw = displayToRaw(text);
            if (raw !== '') {
                display.value = rawToDisplay(raw);
                if (hidden instanceof HTMLInputElement) { hidden.value = raw; }
            }
        });
    };

    // --- Categoria Select2 (tags) ---

    const initCategoriaSelect = () => {
        const select = els.categoriaSelect;
        if (!(select instanceof HTMLSelectElement)) { return; }
        if (typeof window.jQuery === 'undefined' || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') { return; }

        const $ = window.jQuery;
        if ($(select).data('select2')) { return; }

        $(select).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: select.dataset.select2Placeholder || 'Ex.: Serviço, Aluguel, Energia...',
            allowClear: true,
            tags: true,
            createTag: (params) => {
                const term = normalizeText(params.term);
                if (term === '' || term.length > 50) { return null; }
                return { id: term, text: term, newTag: true };
            },
            language: {
                noResults: () => 'Nenhuma categoria. Pressione Enter para criar.',
                searching: () => 'Buscando...',
            },
        });
    };

    const getModal = (element) => {
        if (!(element instanceof HTMLElement) || typeof window.bootstrap === 'undefined') {
            return null;
        }

        return window.bootstrap.Modal.getOrCreateInstance(element);
    };

    const showToast = (icon, title) => {
        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({
            toast: true,
            position: 'top-end',
            timer: 3200,
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

    const requestJson = async (url, { method = 'GET', body = null } = {}) => {
        const options = {
            method,
            headers: {
                Accept: 'application/json',
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

    const renderClientTemplate = (client) => {
        if (!client || client.loading) {
            return escapeHtml(client?.text || client?.name || '');
        }

        const title = escapeHtml(normalizeText(client.name || client.text || `Cliente #${client.id}`));
        const meta = [
            normalizeText(client.phone || ''),
            normalizeText(client.email || ''),
            client.city || client.uf ? [client.city, client.uf].filter(Boolean).join(' / ') : '',
        ].filter(Boolean);

        return `
            <div class="d-flex flex-column py-1">
                <strong>${title}</strong>
                ${meta.length > 0 ? `<small class="text-secondary">${escapeHtml(meta.join(' / '))}</small>` : ''}
            </div>
        `;
    };

    const setClientSelectValue = (clientId, clientName) => {
        if (!(els.clientSelect instanceof HTMLSelectElement)) {
            return;
        }

        const value = String(clientId || '');
        if (value === '') {
            return;
        }

        let option = Array.from(els.clientSelect.options).find((o) => o.value === value) || null;

        if (!(option instanceof HTMLOptionElement)) {
            option = document.createElement('option');
            option.value = value;
            option.textContent = normalizeText(clientName || `Cliente #${value}`);
            els.clientSelect.appendChild(option);
        }

        if (
            typeof window.jQuery !== 'undefined'
            && window.jQuery.fn
            && typeof window.jQuery.fn.select2 === 'function'
            && Boolean(window.jQuery(els.clientSelect).data('select2'))
        ) {
            window.jQuery(els.clientSelect).val(value).trigger('change.select2');
        } else {
            els.clientSelect.value = value;
        }
    };

    const initClientSelect = () => {
        if (!(els.clientSelect instanceof HTMLSelectElement) || clientSearchUrl === '') {
            return;
        }

        if (
            typeof window.jQuery === 'undefined'
            || !window.jQuery.fn
            || typeof window.jQuery.fn.select2 !== 'function'
        ) {
            return;
        }

        const $ = window.jQuery;

        if ($(els.clientSelect).data('select2')) {
            return;
        }

        const placeholder = els.clientSelect.dataset.select2Placeholder || 'Buscar cliente pelo nome...';

        $(els.clientSelect).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder,
            allowClear: true,
            minimumInputLength: 1,
            language: select2Language,
            escapeMarkup: (markup) => markup,
            templateResult: renderClientTemplate,
            templateSelection: (client) => {
                if (!client || client.loading) {
                    return escapeHtml(client?.text || placeholder);
                }

                return escapeHtml(normalizeText(client.name || client.text || placeholder));
            },
            ajax: {
                url: clientSearchUrl,
                dataType: 'json',
                delay: 250,
                cache: true,
                data: (params) => ({
                    q: params.term || '',
                    page: params.page || 1,
                    per_page: 10,
                }),
                processResults: (data, params) => {
                    const page = params.page || 1;
                    const clients = Array.isArray(data?.clients) ? data.clients : [];

                    return {
                        results: clients.map((item) => ({
                            id: String(item?.id || ''),
                            text: normalizeText(item?.text || item?.name || ''),
                            name: normalizeText(item?.name || item?.text || ''),
                            phone: normalizeText(item?.phone || ''),
                            email: normalizeText(item?.email || ''),
                            city: normalizeText(item?.city || ''),
                            uf: normalizeText(item?.uf || ''),
                        })),
                        pagination: {
                            more:
                                Number(data?.pagination?.current_page || page)
                                < Number(data?.pagination?.last_page || page),
                        },
                    };
                },
            },
        });
    };

    // --- Quick Client Modal ---

    const renderQuickClientErrors = (messages, fallback = '') => {
        const box = document.getElementById('quickClientErrors');
        if (!(box instanceof HTMLElement)) {
            return;
        }

        const items = Array.isArray(messages) ? messages.filter(Boolean) : [];
        box.innerHTML = items.length > 0
            ? `<ul class="mb-0 ps-3">${items.map((m) => `<li>${escapeHtml(m)}</li>`).join('')}</ul>`
            : escapeHtml(fallback || 'Nao foi possivel cadastrar o cliente.');
        box.classList.remove('d-none');
    };

    const clearQuickClientErrors = () => {
        const box = document.getElementById('quickClientErrors');
        if (box instanceof HTMLElement) {
            box.classList.add('d-none');
            box.innerHTML = '';
        }
    };

    const setQuickClientSubmitState = (loading) => {
        if (!(els.quickClientSubmit instanceof HTMLButtonElement)) {
            return;
        }

        els.quickClientSubmit.disabled = loading;
        els.quickClientSubmit.innerHTML = loading
            ? '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Salvando...'
            : '<i class="bi bi-person-plus me-2"></i>Cadastrar cliente';
    };

    const applyClientSelection = (client) => {
        const clientId = Number(client?.id || 0) || 0;
        const clientName = normalizeText(client?.nome_razao || client?.name || '');

        if (clientId <= 0) {
            return;
        }

        setClientSelectValue(clientId, clientName);
        showToast('success', 'Cliente cadastrado e selecionado.');
    };

    const initQuickClient = () => {
        if (!config.quickClientStoreUrl) {
            return;
        }

        els.quickClientButton?.addEventListener('click', () => {
            getModal(els.quickClientModal)?.show();
        });

        const form = els.quickClientForm;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const submitHandler = async (event) => {
            event.preventDefault();
            clearQuickClientErrors();

            if (!form.reportValidity()) {
                renderQuickClientErrors([], 'Informe nome/razão social e telefone principal antes de salvar.');
                return;
            }

            setQuickClientSubmitState(true);

            try {
                const payload = Object.fromEntries(new FormData(form).entries());
                const response = await requestJson(config.quickClientStoreUrl, {
                    method: 'POST',
                    body: payload,
                });

                applyClientSelection(response.client || {});
                getModal(els.quickClientModal)?.hide();
            } catch (error) {
                const details = Array.isArray(error?.details)
                    ? error.details
                    : error?.details && typeof error.details === 'object'
                        ? Object.values(error.details).flat().filter(Boolean)
                        : [];

                renderQuickClientErrors(details, error.message);
                showAlert('error', 'Falha ao cadastrar cliente', error.message);
            } finally {
                setQuickClientSubmitState(false);
            }
        };

        els.quickClientSubmit?.addEventListener('click', () => {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });

        form.addEventListener('submit', submitHandler);

        els.quickClientModal?.addEventListener('hidden.bs.modal', () => {
            form.reset();
            clearQuickClientErrors();
            setQuickClientSubmitState(false);
        });
    };

    initValorMask();
    initCategoriaSelect();
    initFinancialAccount();
    initClientSelect();
    initQuickClient();
})();
