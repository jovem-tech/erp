(function () {
    const config = window.__DESKTOP_FINANCEIRO_FORM || {};

    const clientSearchUrl = String(config.clientSearchUrl || '').trim();
    const orderSearchUrl = String(config.orderSearchUrl || '').trim();
    const supplierSearchUrl = String(config.supplierSearchUrl || '').trim();
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
        osSelect: document.getElementById('financeiroOsId'),
        osHelp: document.getElementById('financeiroOsHelp'),
        avulsoInput: document.getElementById('financeiroAvulso'),
        tipoSelect: document.getElementById('financeiroTipo'),
        fornecedorWrapper: document.getElementById('financeiroFornecedorWrapper'),
        fornecedorSelect: document.getElementById('financeiroFornecedorId'),
        classificacaoWrapper: document.getElementById('financeiroClassificacaoWrapper'),
        classificacaoSelect: document.getElementById('financeiroClassificacaoFixa'),
        repetirWrapper: document.getElementById('financeiroRepetirWrapper'),
        osWrapper: document.getElementById('financeiroOsWrapper'),
        clienteWrapper: document.getElementById('financeiroClienteWrapper'),
        vinculosSection: document.getElementById('financeiroVinculosSection'),
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

    // --- OS (ordem de serviço) Select2 ---

    const renderOrderTemplate = (order) => {
        if (!order || order.loading) {
            return escapeHtml(order?.text || '');
        }

        const title = escapeHtml(normalizeText(
            order.text || (order.numero_os ? `OS ${order.numero_os}` : `OS #${order.id}`)
        ));
        const meta = [
            normalizeText(order.cliente_nome || ''),
            normalizeText(order.equipamento || ''),
            normalizeText(order.status_nome || ''),
        ].filter(Boolean);

        return `
            <div class="d-flex flex-column py-1">
                <strong>${title}</strong>
                ${meta.length > 0 ? `<small class="text-secondary">${escapeHtml(meta.join(' / '))}</small>` : ''}
            </div>
        `;
    };

    const initOrderSelect = () => {
        if (!(els.osSelect instanceof HTMLSelectElement) || orderSearchUrl === '') {
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

        if ($(els.osSelect).data('select2')) {
            return;
        }

        const placeholder = els.osSelect.dataset.select2Placeholder || 'Buscar OS pelo número...';

        $(els.osSelect).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder,
            allowClear: true,
            minimumInputLength: 0,
            language: {
                ...select2Language,
                noResults: () => 'Nenhuma OS em aberto encontrada.',
            },
            escapeMarkup: (markup) => markup,
            templateResult: renderOrderTemplate,
            templateSelection: (order) => {
                if (!order || order.loading) {
                    return escapeHtml(order?.text || placeholder);
                }

                return escapeHtml(normalizeText(order.text || placeholder));
            },
            ajax: {
                url: orderSearchUrl,
                dataType: 'json',
                delay: 250,
                cache: true,
                data: (params) => ({
                    q: params.term || '',
                    client_id: els.clientSelect instanceof HTMLSelectElement ? (els.clientSelect.value || '') : '',
                    page: params.page || 1,
                    per_page: 10,
                }),
                processResults: (data, params) => {
                    const page = params.page || 1;
                    const orders = Array.isArray(data?.orders) ? data.orders : [];

                    return {
                        results: orders.map((item) => ({
                            id: String(item?.id || ''),
                            text: normalizeText(item?.text || ''),
                            numero_os: normalizeText(item?.numero_os || ''),
                            cliente_id: item?.cliente_id ? String(item.cliente_id) : '',
                            cliente_nome: normalizeText(item?.cliente_nome || ''),
                            equipamento: normalizeText(item?.equipamento || ''),
                            status_nome: normalizeText(item?.status_nome || ''),
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

    // --- Fornecedor Select2 ---

    const renderSupplierTemplate = (supplier) => {
        if (!supplier || supplier.loading) {
            return escapeHtml(supplier?.text || '');
        }

        const title = escapeHtml(normalizeText(supplier.name || supplier.text || `Fornecedor #${supplier.id}`));
        const meta = [normalizeText(supplier.phone || '')].filter(Boolean);

        return `
            <div class="d-flex flex-column py-1">
                <strong>${title}</strong>
                ${meta.length > 0 ? `<small class="text-secondary">${escapeHtml(meta.join(' / '))}</small>` : ''}
            </div>
        `;
    };

    const initSupplierSelect = () => {
        if (!(els.fornecedorSelect instanceof HTMLSelectElement) || supplierSearchUrl === '') {
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

        if ($(els.fornecedorSelect).data('select2')) {
            return;
        }

        const placeholder = els.fornecedorSelect.dataset.select2Placeholder || 'Buscar fornecedor pelo nome...';

        $(els.fornecedorSelect).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder,
            allowClear: true,
            minimumInputLength: 1,
            language: {
                ...select2Language,
                noResults: () => 'Nenhum fornecedor encontrado.',
            },
            escapeMarkup: (markup) => markup,
            templateResult: renderSupplierTemplate,
            templateSelection: (supplier) => {
                if (!supplier || supplier.loading) {
                    return escapeHtml(supplier?.text || placeholder);
                }

                return escapeHtml(normalizeText(supplier.name || supplier.text || placeholder));
            },
            ajax: {
                url: supplierSearchUrl,
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
                    const suppliers = Array.isArray(data?.suppliers) ? data.suppliers : [];

                    return {
                        results: suppliers.map((item) => ({
                            id: String(item?.id || ''),
                            text: normalizeText(item?.text || ''),
                            name: normalizeText(item?.name || item?.text || ''),
                            phone: normalizeText(item?.phone || ''),
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

    // --- Avulso / OS / Fornecedor coordination ---
    //
    // Compra de peças quase sempre serve para fechar a OS de um cliente, raramente
    // é para estoque — por isso selecionar uma OS preenche o cliente automaticamente
    // e desmarca "avulso". Trocar o cliente limpa a OS selecionada (ela pertencia ao
    // cliente anterior). Como o cliente é setado via `change.select2` (evento
    // namespaced), esse listener plain 'change' só dispara em edições feitas pelo
    // usuário na UI, não quando o próprio código preenche o cliente a partir da OS.

    const initVinculos = () => {
        const $ = window.jQuery;
        const hasSelect2 = typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function';

        const clearSelect2Value = (select) => {
            if (!(select instanceof HTMLSelectElement)) { return; }
            if (hasSelect2 && $(select).data('select2')) {
                $(select).val(null).trigger('change');
            } else {
                select.value = '';
            }
        };

        // Fornecedor e "Despesa fixa?" só existem para "a pagar".
        const syncClassificacaoVisibility = () => {
            if (!els.tipoSelect) { return; }

            const isPagar = els.tipoSelect.value === 'pagar';
            els.fornecedorWrapper?.classList.toggle('d-none', !isPagar);
            els.classificacaoWrapper?.classList.toggle('d-none', !isPagar);

            if (els.fornecedorSelect instanceof HTMLSelectElement) {
                els.fornecedorSelect.required = isPagar;
                if (!isPagar) { clearSelect2Value(els.fornecedorSelect); }
            }

            if (!isPagar && els.classificacaoSelect instanceof HTMLSelectElement) {
                els.classificacaoSelect.value = '';
            }
        };

        // OS vinculada e Cliente só fazem sentido, em "a pagar", quando a
        // despesa é compra de peça ligada a uma OS (categoria do grupo DRE
        // "Custo Direto (OS)") — para despesas operacionais genéricas
        // (Energia, Água, Internet, Aluguel...) não há relação com OS/cliente.
        const syncOsClienteState = () => {
            if (!els.tipoSelect || (!els.osWrapper && !els.clienteWrapper)) { return; }

            const isPagar = els.tipoSelect.value === 'pagar';
            const categoriaNome = els.categoriaSelect instanceof HTMLSelectElement ? els.categoriaSelect.value : '';
            const categorias = Array.isArray(config.categorias) ? config.categorias : [];
            const categoria = categorias.find((c) => normalizeText(c?.nome) === normalizeText(categoriaNome));
            const isPecaCategoria = categoria?.dre_grupo?.nome === 'Custo Direto (OS)';
            const hide = isPagar && !isPecaCategoria;

            els.osWrapper?.classList.toggle('d-none', hide);
            els.clienteWrapper?.classList.toggle('d-none', hide);

            if (hide) {
                clearSelect2Value(els.osSelect);
                clearSelect2Value(els.clientSelect);
            }
        };

        // "Despesa fixa?" filtra as opções de Categoria (via atributo
        // data-fixo, já presente em cada <option>), em vez de a categoria
        // definir um padrão que o usuário só ajustaria depois — o fluxo é
        // "escolho fixa/variável" → "só então vejo as categorias certas".
        const filterCategoriaOptions = () => {
            if (!(els.categoriaSelect instanceof HTMLSelectElement)) { return; }

            const filterValue = els.classificacaoSelect instanceof HTMLSelectElement ? els.classificacaoSelect.value : '';
            let selectedStillValid = filterValue === '' || els.categoriaSelect.value === '';

            Array.from(els.categoriaSelect.options).forEach((option) => {
                if (option.value === '') { return; }
                const matches = filterValue === '' || option.dataset.fixo === filterValue;
                option.hidden = !matches;
                option.disabled = !matches;
                if (matches && option.value === els.categoriaSelect.value) { selectedStillValid = true; }
            });

            if (!selectedStillValid) { clearSelect2Value(els.categoriaSelect); }
        };

        const syncRepetirVisibility = () => {
            const isFixa = els.classificacaoSelect instanceof HTMLSelectElement && els.classificacaoSelect.value === '1';
            els.repetirWrapper?.classList.toggle('d-none', !isFixa);
        };

        // Despesa fixa mensal (água, luz, aluguel...) nunca tem OS, cliente
        // ou fornecedor — a seção inteira de Vínculos some para não
        // confundir o operador com campos que não fazem sentido pra esse
        // tipo de lançamento. "Lançamento avulso" fica marcado (é sempre o
        // caso) e qualquer OS/cliente/fornecedor já selecionado é limpo.
        const syncVinculosVisibility = () => {
            if (!els.vinculosSection) { return; }

            const isFixa = els.classificacaoSelect instanceof HTMLSelectElement && els.classificacaoSelect.value === '1';
            els.vinculosSection.classList.toggle('d-none', isFixa);

            if (isFixa) {
                if (els.avulsoInput instanceof HTMLInputElement) { els.avulsoInput.checked = true; }
                clearSelect2Value(els.osSelect);
                clearSelect2Value(els.clientSelect);
                clearSelect2Value(els.fornecedorSelect);
            }
        };

        syncClassificacaoVisibility();
        syncOsClienteState();
        filterCategoriaOptions();
        syncRepetirVisibility();
        syncVinculosVisibility();

        // Tipo e "Despesa fixa?" são <select class="form-select"> comuns
        // (sem data-native-select="true"), então o auto-init global de
        // desktop.js os transforma em Select2 — e escolher uma opção pela
        // UI do Select2 dispara 'change' só via jQuery, nunca o evento
        // nativo do DOM (ver comentário em desktop.js, initSelect2()). Um
        // addEventListener('change', ...) puro aqui NUNCA dispararia quando
        // o usuário realmente usasse o dropdown — só funcionaria se o valor
        // fosse setado programaticamente. Por isso o bind duplo: nativo
        // (cobre o caso raro de não virar Select2, ex. JS falhar) + jQuery
        // (cobre o Select2, que é o caso real em produção).
        const bindChange = (select, handler) => {
            if (!(select instanceof HTMLSelectElement)) { return; }
            select.addEventListener('change', handler);
            select.addEventListener('input', handler);
            if (hasSelect2) { $(select).on('change', handler); }
        };

        bindChange(els.tipoSelect, () => { syncClassificacaoVisibility(); syncOsClienteState(); filterCategoriaOptions(); syncVinculosVisibility(); });
        bindChange(els.classificacaoSelect, () => { filterCategoriaOptions(); syncRepetirVisibility(); syncVinculosVisibility(); });

        if (els.categoriaSelect instanceof HTMLSelectElement) {
            els.categoriaSelect.addEventListener('change', syncOsClienteState);
            if (hasSelect2) {
                $(els.categoriaSelect).on('change select2:select select2:unselect', syncOsClienteState);
            }
        }

        // Alguns navegadores restauram o valor de <select> ao recarregar a
        // página (form state restoration) SEM disparar 'change' — 'pageshow'
        // dispara depois de qualquer restauração desse tipo (recarregar,
        // voltar/avançar), então reconferir o estado ali cobre esse caso.
        window.addEventListener('pageshow', () => {
            syncClassificacaoVisibility();
            syncOsClienteState();
            filterCategoriaOptions();
            syncRepetirVisibility();
            syncVinculosVisibility();
        });

        if (!(els.avulsoInput instanceof HTMLInputElement) || !(els.osSelect instanceof HTMLSelectElement)) {
            return;
        }

        const syncAvulsoState = () => {
            const isAvulso = els.avulsoInput.checked;

            if (isAvulso) { clearSelect2Value(els.osSelect); }
            els.osSelect.disabled = isAvulso;

            if (els.osHelp instanceof HTMLElement) {
                els.osHelp.textContent = isAvulso
                    ? 'OS desabilitada: lançamentos avulsos são sempre independentes de ordem de serviço.'
                    : 'Busque pelo número da OS (só aparecem OS em aberto). Selecionar uma OS preenche o cliente automaticamente e desmarca o lançamento avulso.';
            }
        };

        els.avulsoInput.addEventListener('change', syncAvulsoState);

        if (hasSelect2) {
            $(els.osSelect).on('select2:select', (event) => {
                const order = event.params?.data;
                if (!order) { return; }

                if (els.avulsoInput.checked) {
                    els.avulsoInput.checked = false;
                    syncAvulsoState();
                }

                if (order.cliente_id) {
                    setClientSelectValue(order.cliente_id, order.cliente_nome);
                }
            });

            if (els.clientSelect instanceof HTMLSelectElement) {
                $(els.clientSelect).on('change', () => clearSelect2Value(els.osSelect));
            }
        }

        syncAvulsoState();
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

    // Cada init roda isolado: um erro em qualquer um deles não pode impedir
    // os seguintes de rodar (ex.: se initClientSelect() falhar, initVinculos()
    // — que mostra/esconde Fornecedor e Despesa fixa mensal — ainda precisa
    // rodar). Sem isso, uma chamada síncrona simples deixaria tudo depois do
    // ponto de falha completamente sem inicializar, silenciosamente.
    const runInit = (name, fn) => {
        try {
            fn();
        } catch (error) {
            console.error(`[financeiro-form] Falha ao inicializar ${name}:`, error);
        }
    };

    runInit('initValorMask', initValorMask);
    runInit('initCategoriaSelect', initCategoriaSelect);
    runInit('initFinancialAccount', initFinancialAccount);
    runInit('initClientSelect', initClientSelect);
    runInit('initOrderSelect', initOrderSelect);
    runInit('initSupplierSelect', initSupplierSelect);
    runInit('initVinculos', initVinculos);
    runInit('initQuickClient', initQuickClient);
})();
