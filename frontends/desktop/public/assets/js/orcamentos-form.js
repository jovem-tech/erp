(function () {
    const config = window.__DESKTOP_ORCAMENTO_FORM || {};
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const catalogs = {
        services: Array.isArray(config.catalogs?.services) ? config.catalogs.services : [],
        parts: Array.isArray(config.catalogs?.parts) ? config.catalogs.parts : [],
    };

    const quickCatalogs = {
        service: {
            enabled: Boolean(config.quickCatalogs?.service?.enabled),
            storeUrl: String(config.quickCatalogs?.service?.store_url || ''),
            label: String(config.quickCatalogs?.service?.label || 'Serviço'),
            title: String(config.quickCatalogs?.service?.title || 'Cadastro rápido de serviço'),
            submitLabel: String(config.quickCatalogs?.service?.submit_label || 'Cadastrar serviço'),
        },
        part: {
            enabled: Boolean(config.quickCatalogs?.part?.enabled),
            storeUrl: String(config.quickCatalogs?.part?.store_url || ''),
            label: String(config.quickCatalogs?.part?.label || 'Peça'),
            title: String(config.quickCatalogs?.part?.title || 'Cadastro rápido de peça'),
            submitLabel: String(config.quickCatalogs?.part?.submit_label || 'Cadastrar peça'),
        },
    };

    const draftKey = String(config.draftKey || 'orcamentos:create');
    const isEditMode = Boolean(config.isEditMode);
    const budgetId = Number(config.budgetId || 0) || 0;
    const clientSearchUrl = String(config.clientSearchUrl || '').trim();
    // Endpoint que devolve as OS abertas e os equipamentos do cliente escolhido,
    // para filtrar "OS vinculada" e "Equipamento cadastrado" conforme o cliente.
    const clientContextUrl = String(config.clientContextUrl || '').trim();

    const moneyFormatter = new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    const percentFormatter = new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const toNumber = (value) => {
        if (typeof value === 'number') {
            return Number.isFinite(value) ? value : 0;
        }

        const raw = String(value ?? '').trim();

        if (raw === '') {
            return 0;
        }

        let normalized = raw.replace(/[^\d,.-]/g, '');

        if (normalized === '' || normalized === '-' || normalized === '.' || normalized === ',') {
            return 0;
        }

        const lastComma = normalized.lastIndexOf(',');
        const lastDot = normalized.lastIndexOf('.');

        if (lastComma !== -1 && lastDot !== -1) {
            if (lastComma > lastDot) {
                normalized = normalized.replace(/\./g, '').replace(',', '.');
            } else {
                normalized = normalized.replace(/,/g, '');
            }
        } else if (lastComma !== -1) {
            normalized = normalized.replace(/\./g, '').replace(',', '.');
        } else if (lastDot !== -1) {
            const parts = normalized.split('.');
            const lastPart = parts[parts.length - 1] || '';

            if (parts.length > 2 || lastPart.length === 3) {
                normalized = normalized.replace(/\./g, '');
            }
        }

        const parsed = Number.parseFloat(normalized);

        return Number.isFinite(parsed) ? parsed : 0;
    };

    const formatMoney = (value) => `R$ ${moneyFormatter.format(toNumber(value))}`;
    const formatPercent = (value) => percentFormatter.format(toNumber(value));
    const formatCanonicalNumber = (value, scale = 2) => {
        const numeric = Number(toNumber(value));

        if (!Number.isFinite(numeric)) {
            return Number(0).toFixed(scale);
        }

        return numeric.toFixed(scale);
    };
    const roundCurrency = (value) => Number(formatCanonicalNumber(value, 2));
    const roundPercent = (value) => Number(formatCanonicalNumber(value, 4));

    const normalizeText = (value) => String(value ?? '').trim();

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const bindMoneyInput = (input) => {
        if (!(input instanceof HTMLInputElement) || input.dataset.moneyBound === '1') {
            return;
        }

        input.dataset.moneyBound = '1';
        input.type = 'text';
        input.inputMode = 'decimal';
        input.autocomplete = 'off';
        input.spellcheck = false;

        const sync = () => {
            input.value = formatMoney(input.value);
        };

        input.addEventListener('focus', () => {
            window.requestAnimationFrame(() => {
                input.select();
            });
        });
        input.addEventListener('blur', sync);
        sync();
    };

    const bindMoneyInputs = (root = document) => {
        if (!(root instanceof Document || root instanceof HTMLElement)) {
            return;
        }

        root.querySelectorAll('[data-budget-money]').forEach((input) => bindMoneyInput(input));
    };

    const getModal = (element) => {
        if (!(element instanceof HTMLElement) || typeof window.bootstrap === 'undefined' || !window.bootstrap?.Modal) {
            return null;
        }

        return window.bootstrap.Modal.getOrCreateInstance(element);
    };

    const showToast = (icon, title) => {
        if (typeof window.Swal === 'undefined') {
            return;
        }

        window.Swal.fire({
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
        if (typeof window.Swal === 'undefined') {
            return;
        }

        window.Swal.fire({ icon, title, text });
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

            if (window.DesktopUi && typeof window.DesktopUi.logError === 'function') {
                window.DesktopUi.logError('orcamentos-form.requestJson', error, {
                    path: String(url).split('?')[0],
                    method,
                });
            }

            throw error;
        }

        return payload;
    };

    const extractErrorMessages = (details) => {
        if (Array.isArray(details)) {
            return details
                .map((message) => normalizeText(message))
                .filter(Boolean);
        }

        if (details && typeof details === 'object') {
            return Object.values(details)
                .flat()
                .map((message) => normalizeText(message))
                .filter(Boolean);
        }

        return [];
    };

    const normalizeServiceCatalogItem = (service) => ({
        id: Number(service?.id || 0),
        label: normalizeText(service?.nome || service?.label || service?.description || 'Serviço'),
        description: normalizeText(service?.descricao || service?.description || service?.nome || ''),
        price: toNumber(service?.valor ?? service?.price ?? 0),
    });

    const normalizePartCatalogItem = (part) => ({
        id: Number(part?.id || 0),
        label: normalizeText(
            (normalizeText(part?.codigo || '') !== '' ? `${normalizeText(part?.codigo)} - ` : '') + (part?.nome || part?.label || part?.description || 'Peça')
        ),
        description: normalizeText(part?.nome || part?.description || part?.label || ''),
        price: toNumber(part?.preco_venda ?? part?.price ?? 0),
    });

    const reinitReferenceSelect2 = (select) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        if (window.DesktopUi && typeof window.DesktopUi.refreshSelect2 === 'function') {
            window.DesktopUi.refreshSelect2(select);
        }
    };

    const initEquipmentTypeSelect = (select) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        if (typeof window.jQuery === 'undefined' || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') {
            return;
        }

        const $ = window.jQuery;
        if ($(select).data('select2')) {
            return;
        }

        // Select2 precisa de dropdownParent apontando para o modal: sem isso, o
        // dropdown (com o campo de busca) e anexado ao <body>, fora da area em
        // que o focus trap do Bootstrap Modal permite foco, e a digitacao no
        // campo de busca simplesmente nao registra nenhuma tecla.
        const modal = select.closest('.modal');
        const dropdownParent = modal ? $(modal) : $(document.body);

        $(select).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: select.dataset.select2Placeholder || 'Selecione ou digite um tipo de equipamento',
            allowClear: true,
            dropdownParent,
            tags: true,
            createTag: (params) => {
                const term = normalizeText(params.term);
                if (term === '' || term.length > 120) {
                    return null;
                }

                return { id: term, text: term, newTag: true };
            },
            language: {
                noResults: () => 'Nenhum tipo de equipamento. Pressione Enter para criar.',
                searching: () => 'Buscando...',
            },
        });
    };

    const setEquipmentTypeValue = (select, value) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        const normalized = normalizeText(value);

        if (normalized !== '' && !Array.from(select.options).some((option) => option.value === normalized)) {
            select.appendChild(new Option(normalized, normalized, true, true));
        }

        select.value = normalized;

        if (typeof window.jQuery !== 'undefined' && window.jQuery.fn && Boolean(window.jQuery(select).data('select2'))) {
            window.jQuery(select).trigger('change');
            return;
        }

        select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const onSelectEvent = (select, eventName, handler) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        if (typeof window.jQuery !== 'undefined' && window.jQuery.fn && typeof window.jQuery.fn.on === 'function') {
            window.jQuery(select).on(eventName, handler);
            return;
        }

        select.addEventListener(eventName, handler);
    };

    const debounce = (callback, wait = 300) => {
        let timeoutId = null;

        return (...args) => {
            if (timeoutId) {
                window.clearTimeout(timeoutId);
            }

            timeoutId = window.setTimeout(() => callback(...args), wait);
        };
    };

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('[data-budget-form]');
        const itemsBody = document.querySelector('[data-budget-items]');
        const template = document.getElementById('orcamentoItemTemplate');
        const addButton = document.querySelector('[data-budget-item-add]');
        const subtotalInput = document.querySelector('[data-budget-subtotal]');
        const totalInput = document.querySelector('[data-budget-total]');
        const banner = document.querySelector('[data-budget-draft-banner]');
        const restoreButton = document.querySelector('[data-budget-draft-restore]');
        const discardButton = document.querySelector('[data-budget-draft-discard]');
        const itemsCount = document.querySelector('[data-budget-items-count]');
        const validityDaysSelect = document.querySelector('[data-budget-validity-days]');
        const validityDateInput = document.querySelector('[data-budget-validity-date]');
        const clientSelect = document.getElementById('orcamentoClienteId');
        const clientFallbackInput = document.getElementById('orcamentoClienteAvulso');
        const phoneInput = document.getElementById('orcamentoTelefoneContato');
        const emailInput = document.getElementById('orcamentoEmailContato');
        const orderSelect = document.getElementById('orcamentoOsId');
        const equipmentSelect = document.getElementById('orcamentoEquipamentoId');
        const titleInput = document.getElementById('orcamentoTitulo');
        // Tipo e origem são derivados da presença de OS (sem OS = avulso/prévio;
        // com OS = assistência). Exibidos read-only, o valor real vai em hidden.
        const typeDisplay = document.querySelector('[data-budget-type-display]');
        const typeValueInput = document.querySelector('[data-budget-type-value]');
        const originDisplay = document.querySelector('[data-budget-origin-display]');
        const originValueInput = document.querySelector('[data-budget-origin-value]');
        const statusSelect = document.getElementById('orcamentoStatus');
        const DERIVED_TYPE_LABELS = {
            previo: 'Orçamento prévio',
            assistencia: 'Orçamento com equipamento na assistência',
        };
        const DERIVED_ORIGIN_LABELS = {
            manual: 'Manual',
            os: 'Ordem de serviço',
            conversa: 'Conversa',
            cliente: 'Cliente',
        };
        // Origens de proveniência (chat/cliente) são preservadas quando não há OS;
        // caso contrário o avulso manual usa 'manual' e o vinculado usa 'os'.
        const preservedOrigins = ['conversa', 'cliente'];
        const syncDerivedClassification = () => {
            // OS desabilitada (cliente eventual ou "sem equipamento") conta como
            // "sem OS" — assim tipo/origem derivam corretamente para avulso.
            const hasOrder = orderSelect instanceof HTMLSelectElement
                && ! orderSelect.disabled
                && String(orderSelect.value || '').trim() !== '';
            const nextType = hasOrder ? 'assistencia' : 'previo';
            if (typeValueInput instanceof HTMLInputElement) {
                typeValueInput.value = nextType;
            }
            if (typeDisplay instanceof HTMLInputElement) {
                typeDisplay.value = DERIVED_TYPE_LABELS[nextType] || nextType;
            }
            const currentOrigin = originValueInput instanceof HTMLInputElement
                ? String(originValueInput.value || '').trim()
                : '';
            let nextOrigin;
            if (hasOrder) {
                nextOrigin = 'os';
            } else if (preservedOrigins.includes(currentOrigin)) {
                nextOrigin = currentOrigin;
            } else {
                nextOrigin = 'manual';
            }
            if (originValueInput instanceof HTMLInputElement) {
                originValueInput.value = nextOrigin;
            }
            if (originDisplay instanceof HTMLInputElement) {
                originDisplay.value = DERIVED_ORIGIN_LABELS[nextOrigin] || nextOrigin;
            }
        };

        // --- Cliente/equipamento eventual: exclusividade e visibilidade ---------
        // Regras: cliente cadastrado × nome eventual são mutuamente exclusivos;
        // equipamento cadastrado × equipamento eventual idem; cliente eventual
        // oculta "OS vinculada" e "Equipamento cadastrado" (dependem de cadastro);
        // "sem equipamento" oculta todo o bloco de equipamento.
        const clientFallbackForExclusivity = clientFallbackInput;
        const envolveCheckbox = document.querySelector('[data-budget-envolve-equipamento]');
        const eventualInputs = Array.from(document.querySelectorAll('[data-budget-eventual-input]'));
        // Campo "Tipo" do equipamento eventual: agora é um Select2 com os tipos do
        // banco (EquipmentType), com tags para permitir digitar um novo.
        const equipTypeSelect = document.querySelector('[data-budget-equip-type-select]');
        const equipmentFieldWraps = Array.from(document.querySelectorAll('[data-budget-equipment-field]'));
        const registeredOnlyWraps = Array.from(document.querySelectorAll('[data-budget-registered-only]'));
        const eventualWrap = document.querySelector('[data-budget-eventual-equipment]');
        // clientLocked (orçamento vindo de uma OS): o Blade zera o name do select.
        const clientLocked = clientSelect instanceof HTMLSelectElement
            && clientSelect.getAttribute('name') !== 'cliente_id';

        const jq = () => (typeof window.jQuery !== 'undefined' ? window.jQuery : null);
        const rememberClientOption = (option, client = {}) => {
            if (!(option instanceof HTMLOptionElement)) {
                return;
            }

            option.dataset.clientName = normalizeText(client?.name || option.dataset.clientName || option.textContent);
            option.dataset.clientPhone = normalizeText(client?.phone || option.dataset.clientPhone || '');
            option.dataset.clientEmail = normalizeText(client?.email || option.dataset.clientEmail || '');
        };
        const initClientSelect = () => {
            if (!(clientSelect instanceof HTMLSelectElement)
                || clientLocked
                || clientSearchUrl === ''
                || typeof window.jQuery === 'undefined'
                || !window.jQuery.fn
                || typeof window.jQuery.fn.select2 !== 'function') {
                return;
            }

            const $ = window.jQuery;

            if ($(clientSelect).data('select2')) {
                try {
                    $(clientSelect).select2('destroy');
                } catch (error) {
                    console.error('[OrcamentosForm] Falha ao reinicializar a busca de clientes.', error);
                }
            }

            $(clientSelect).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: clientSelect.dataset.select2Placeholder || 'Selecione um cliente...',
                allowClear: true,
                dropdownParent: $(document.body),
                minimumInputLength: 0,
                language: {
                    errorLoading: () => 'Os clientes não puderam ser carregados.',
                    loadingMore: () => 'Carregando mais clientes...',
                    noResults: () => 'Nenhum cliente encontrado.',
                    searching: () => 'Buscando clientes...',
                },
                ajax: {
                    url: clientSearchUrl,
                    dataType: 'json',
                    delay: 250,
                    cache: true,
                    data: (params) => ({
                        q: normalizeText(params.term),
                        page: Number(params.page || 1),
                        per_page: 15,
                    }),
                    processResults: (payload) => ({
                        results: Array.isArray(payload?.results)
                            ? payload.results.map((client) => ({
                                id: String(client?.id || ''),
                                text: normalizeText(client?.text || client?.name || ''),
                                name: normalizeText(client?.name || ''),
                                phone: normalizeText(client?.phone || ''),
                                email: normalizeText(client?.email || ''),
                            })).filter((client) => client.id !== '')
                            : [],
                        pagination: {
                            more: Boolean(payload?.pagination?.more),
                        },
                    }),
                },
            });

            $(clientSelect)
                .off('select2:select.orcamentoClients')
                .on('select2:select.orcamentoClients', (event) => {
                    const selectedOption = clientSelect.selectedOptions[0];
                    const selectedClient = event?.params?.data || {};

                    rememberClientOption(selectedOption, selectedClient);

                    if (phoneInput instanceof HTMLInputElement) {
                        phoneInput.value = normalizeText(selectedClient.phone);
                    }
                    if (emailInput instanceof HTMLInputElement) {
                        emailInput.value = normalizeText(selectedClient.email);
                    }
                });

            const selectedOption = clientSelect.selectedOptions[0];
            rememberClientOption(selectedOption);
            clientSelect.dataset.remoteSelect2Ready = '1';
        };
        // Ao desabilitar, também limpamos o valor: o campo inativo (lado oposto da
        // exclusividade / oculto) deve ir vazio. Antes de enviar reabilitamos tudo
        // (enableManagedControlsForSubmit) para que o valor vazio realmente poste e
        // sobrescreva o antigo numa edição — campo disabled não é submetido.
        const setControlDisabled = (el, disabled) => {
            if (!(el instanceof HTMLElement)) {
                return;
            }
            el.disabled = disabled;
            const $ = jq();
            if (el.tagName === 'SELECT') {
                if (disabled) {
                    el.value = '';
                    if ($) {
                        $(el).val(null).trigger('change.select2');
                    }
                }
                if ($) {
                    $(el).prop('disabled', disabled).trigger('change.select2');
                }
            } else if (disabled && (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement)) {
                el.value = '';
            }
        };
        const managedExclusivityControls = () => [
            orderSelect,
            equipmentSelect,
            ...eventualInputs,
            ...(clientLocked ? [] : [clientSelect, clientFallbackInput]),
        ];
        const enableManagedControlsForSubmit = () => {
            managedExclusivityControls().forEach((el) => {
                if (el instanceof HTMLElement) {
                    el.disabled = false;
                    const $ = jq();
                    if ($ && el.tagName === 'SELECT') {
                        $(el).prop('disabled', false);
                    }
                }
            });
        };
        const setWrapHidden = (wrap, hidden) => {
            if (wrap instanceof HTMLElement) {
                wrap.classList.toggle('d-none', hidden);
            }
        };
        const filledSelect = (el) => el instanceof HTMLSelectElement && String(el.value || '').trim() !== '';
        const filledInput = (el) => (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement)
            && String(el.value || '').trim() !== '';
        // Preenchido independentemente do tipo de controle (input/textarea/select).
        const filledControl = (el) => filledInput(el) || filledSelect(el);

        const syncClientExclusivity = () => {
            if (clientLocked) {
                return;
            }
            const avulsoFilled = filledInput(clientFallbackForExclusivity);
            const clientFilled = filledSelect(clientSelect);
            setControlDisabled(clientSelect, avulsoFilled && ! clientFilled);
            setControlDisabled(clientFallbackForExclusivity, clientFilled && ! avulsoFilled);
        };

        // "OS vinculada" (agora na aba do cliente) só deve aparecer quando o
        // cliente selecionado tem OS aberta. Este bloco NUNCA reexibe o campo —
        // apenas o esconde se não houver OS na lista; a exibição é decidida por
        // syncEquipmentModeCore (regras de eventual/sem-equipamento). A composição
        // resulta em: visível só quando (modo registrado) E (há OS aberta).
        const orderFieldWrap = document.querySelector('[data-budget-order-field]');
        const orderOptionCount = () => {
            if (!(orderSelect instanceof HTMLSelectElement)) {
                return 0;
            }

            return Array.from(orderSelect.options)
                .filter((option) => String(option.value || '').trim() !== '')
                .length;
        };
        const applyOrderFieldVisibility = () => {
            if (!(orderFieldWrap instanceof HTMLElement)) {
                return;
            }

            if (orderOptionCount() === 0) {
                orderFieldWrap.classList.add('d-none');
            }
        };

        const syncEquipmentModeCore = () => {
            const envolve = envolveCheckbox instanceof HTMLInputElement ? envolveCheckbox.checked : true;
            const hasEventualClient = ! clientLocked
                && filledInput(clientFallbackForExclusivity)
                && ! filledSelect(clientSelect);

            if (! envolve) {
                equipmentFieldWraps.forEach((w) => setWrapHidden(w, true));
                setControlDisabled(orderSelect, true);
                setControlDisabled(equipmentSelect, true);
                eventualInputs.forEach((i) => setControlDisabled(i, true));
                syncDerivedClassification();
                return;
            }

            equipmentFieldWraps.forEach((w) => setWrapHidden(w, false));

            if (hasEventualClient) {
                // Sem cadastro: só existe equipamento eventual.
                registeredOnlyWraps.forEach((w) => setWrapHidden(w, true));
                setWrapHidden(eventualWrap, false);
                setControlDisabled(orderSelect, true);
                setControlDisabled(equipmentSelect, true);
                eventualInputs.forEach((i) => setControlDisabled(i, false));
                syncDerivedClassification();
                return;
            }

            // Cliente cadastrado (ou travado por OS): registrado + eventual disponíveis.
            registeredOnlyWraps.forEach((w) => setWrapHidden(w, false));

            const hasRegistered = filledSelect(orderSelect) || filledSelect(equipmentSelect);
            const hasEventual = eventualInputs.some(filledControl);
            if (hasRegistered) {
                // OS/equipamento cadastrado escolhido: o card de equipamento
                // eventual não se aplica mais — some (não só desabilita), para
                // não sugerir que ainda dá pra preencher tipo/marca/modelo/cor.
                setWrapHidden(eventualWrap, true);
                setControlDisabled(orderSelect, false);
                setControlDisabled(equipmentSelect, false);
                eventualInputs.forEach((i) => setControlDisabled(i, true));
            } else if (hasEventual) {
                setWrapHidden(eventualWrap, false);
                setControlDisabled(orderSelect, true);
                setControlDisabled(equipmentSelect, true);
                eventualInputs.forEach((i) => setControlDisabled(i, false));
            } else {
                setWrapHidden(eventualWrap, false);
                setControlDisabled(orderSelect, false);
                setControlDisabled(equipmentSelect, false);
                eventualInputs.forEach((i) => setControlDisabled(i, false));
            }
            syncDerivedClassification();
        };

        // Wrapper: além das regras de eventual/sem-equipamento, aplica a
        // visibilidade de "OS vinculada" conforme haver OS aberta do cliente.
        const syncEquipmentMode = () => {
            syncEquipmentModeCore();
            applyOrderFieldVisibility();
        };

        const syncEventualFields = () => {
            syncClientExclusivity();
            syncEquipmentMode();
        };

        // --- Filtragem por cliente: OS abertas e equipamentos (com foto) --------
        const cssAttrEscape = (value) => String(value ?? '').replace(/["\\]/g, '\\$&');

        const rebuildSelectOptions = (select, items, buildOption, desiredValue) => {
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            // desiredValue (opcional) força uma seleção — usado ao reaplicar a OS
            // ou o equipamento restaurados de um rascunho depois que a lista do
            // cliente chega. Sem ele, preserva o que já estava selecionado.
            const previous = desiredValue != null && String(desiredValue) !== ''
                ? String(desiredValue)
                : String(select.value || '');
            select.innerHTML = '';

            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            select.appendChild(emptyOption);

            (Array.isArray(items) ? items : []).forEach((item) => {
                const option = buildOption(item);
                if (option instanceof HTMLOptionElement) {
                    select.appendChild(option);
                }
            });

            // Preserva a seleção desejada apenas se ainda existir na nova lista.
            if (previous !== '' && select.querySelector(`option[value="${cssAttrEscape(previous)}"]`)) {
                select.value = previous;
            } else {
                select.value = '';
            }
        };

        const setOrderOptions = (orders, desiredValue) => {
            rebuildSelectOptions(orderSelect, orders, (order) => {
                const id = String(order?.id ?? '');
                if (id === '') {
                    return null;
                }

                const option = document.createElement('option');
                option.value = id;
                option.textContent = normalizeText(order?.label) || `OS #${id}`;
                if (order?.cliente_id != null) {
                    option.dataset.clienteId = String(order.cliente_id);
                }
                if (order?.equipamento_id != null) {
                    option.dataset.equipamentoId = String(order.equipamento_id);
                }
                if (order?.relato_cliente != null) {
                    option.dataset.relatoCliente = String(order.relato_cliente);
                }

                return option;
            }, desiredValue);

            // OS usa o Select2 genérico do desktop.js — reinicializa para refletir
            // as novas opções e o placeholder.
            if (window.DesktopUi && typeof window.DesktopUi.refreshSelect2 === 'function') {
                window.DesktopUi.refreshSelect2(orderSelect);
            } else {
                const $ = jq();
                if ($ && orderSelect instanceof HTMLSelectElement) {
                    $(orderSelect).trigger('change.select2');
                }
            }
        };

        const setEquipmentOptions = (equipments, desiredValue) => {
            rebuildSelectOptions(equipmentSelect, equipments, (equipment) => {
                const id = String(equipment?.id ?? '');
                if (id === '') {
                    return null;
                }

                const option = document.createElement('option');
                option.value = id;
                option.textContent = normalizeText(equipment?.label) || `Equipamento #${id}`;
                const fotoUrl = normalizeText(equipment?.foto_url);
                if (fotoUrl !== '') {
                    option.dataset.fotoUrl = fotoUrl;
                }
                if (equipment?.cliente_id != null) {
                    option.dataset.clienteId = String(equipment.cliente_id);
                }

                return option;
            }, desiredValue);

            // Equipamento é totalmente gerido aqui (data-select2="false" no HTML):
            // reinicializa o Select2 com o template de miniatura.
            initEquipmentSelect();
        };

        // Template do resultado do Select2 do equipamento: miniatura da foto
        // principal + rótulo. Reaproveita as classes do seletor de equipamento da
        // criação de OS (order-create-equipment-result / equipment-list-photo).
        const buildEquipmentResultMarkup = (state) => {
            const $ = jq();
            const text = normalizeText(state?.text);

            if (!$ || !state || !state.id) {
                return text;
            }

            const option = state.element instanceof HTMLOptionElement ? state.element : null;
            const fotoUrl = option ? normalizeText(option.dataset.fotoUrl) : '';
            // Divide "Tipo - Marca Modelo · S/N ... · Cliente" em título + metadados.
            const parts = text.split(' · ');
            const title = normalizeText(parts.shift());
            const meta = parts.map(normalizeText).filter(Boolean).join(' / ');

            const $wrap = $('<div class="order-create-equipment-result"></div>');
            const $thumb = $('<span class="equipment-list-photo-link order-create-equipment-result-thumb" aria-hidden="true"></span>');
            const emptyThumbMarkup = '<span class="equipment-list-photo-placeholder"><i class="bi bi-laptop"></i></span>';

            if (fotoUrl !== '') {
                const $img = $('<img class="equipment-list-photo" alt="" loading="lazy">');
                $img.attr('src', fotoUrl);
                // Foto inacessível (permissão/arquivo ausente): cai no ícone padrão
                // em vez de mostrar uma imagem quebrada.
                $img.on('error', function handleThumbError() {
                    $thumb.html(emptyThumbMarkup);
                });
                $thumb.append($img);
            } else {
                $thumb.append(emptyThumbMarkup);
            }

            const $content = $('<div class="order-create-equipment-result-content"></div>');
            $content.append($('<strong class="order-create-equipment-result-title"></strong>').text(title !== '' ? title : text));
            if (meta !== '') {
                $content.append($('<small class="order-create-equipment-result-meta"></small>').text(meta));
            }

            return $wrap.append($thumb).append($content);
        };

        const initEquipmentSelect = () => {
            const $ = jq();
            if (!(equipmentSelect instanceof HTMLSelectElement) || !$ || !$.fn || typeof $.fn.select2 !== 'function') {
                return;
            }

            if ($(equipmentSelect).data('select2')) {
                try {
                    $(equipmentSelect).select2('destroy');
                } catch (error) {
                    console.error('[OrcamentosForm] Falha ao reinicializar o seletor de equipamentos.', error);
                }
            }

            const placeholder = equipmentSelect.dataset.select2Placeholder || 'Selecione um equipamento...';

            $(equipmentSelect).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder,
                allowClear: true,
                dropdownParent: $(document.body),
                minimumResultsForSearch: 0,
                templateResult: buildEquipmentResultMarkup,
                // Na seleção fechada, só o texto (miniatura fica no dropdown).
                templateSelection: (state) => normalizeText(state?.text) || placeholder,
                language: {
                    noResults: () => 'Nenhum equipamento cadastrado para este cliente.',
                    searching: () => 'Buscando...',
                },
            });

            equipmentSelect.dataset.select2Ready = '1';
        };

        let lastContextClientId = null;
        // Valores crus de OS/equipamento vindos de um rascunho restaurado: usados
        // uma única vez para reaplicar a seleção após a lista do cliente chegar,
        // mesmo que a opção ainda não exista no <select> no momento da restauração.
        let pendingRestoreOrderId = '';
        let pendingRestoreEquipmentId = '';
        const loadClientContext = async (clientId, desiredOrderId = '', desiredEquipmentId = '') => {
            if (clientContextUrl === '') {
                return;
            }

            const separator = clientContextUrl.includes('?') ? '&' : '?';
            // orcamento_id (orçamento em edição) preserva a própria OS
            // vinculada na lista — ela não deve sumir por já "ter orçamento".
            const excludeBudgetParam = budgetId > 0 ? `&orcamento_id=${encodeURIComponent(budgetId)}` : '';
            const url = `${clientContextUrl}${separator}cliente_id=${encodeURIComponent(clientId)}${excludeBudgetParam}`;

            try {
                const payload = await requestJson(url);

                // Resposta obsoleta (o cliente já mudou de novo): descarta.
                if (String(clientSelect?.value || '').trim() !== String(clientId)) {
                    return;
                }

                // desiredOrderId/desiredEquipmentId reaplicam uma seleção
                // pré-existente (ex.: restauração de rascunho) se ainda pertencer
                // ao cliente.
                setOrderOptions(payload.orders || [], desiredOrderId);
                setEquipmentOptions(payload.equipments || [], desiredEquipmentId);
            } catch (error) {
                setOrderOptions([]);
                setEquipmentOptions([]);
            } finally {
                syncEventualFields();
            }
        };

        const handleClientChange = () => {
            if (clientLocked) {
                return;
            }

            const clientId = clientSelect instanceof HTMLSelectElement ? String(clientSelect.value || '').trim() : '';

            if (clientId === '') {
                lastContextClientId = '';
                setOrderOptions([]);
                setEquipmentOptions([]);
                syncEventualFields();
                return;
            }

            if (clientId === lastContextClientId) {
                return;
            }

            lastContextClientId = clientId;
            // Guarda a seleção atual de OS/equipamento (pode vir de um rascunho
            // restaurado) para reaplicá-la depois que a lista do cliente chegar.
            // pendingRestore* têm prioridade (valor cru do rascunho) e são
            // consumidos uma única vez.
            const desiredOrderId = pendingRestoreOrderId
                || (orderSelect instanceof HTMLSelectElement ? String(orderSelect.value || '') : '');
            const desiredEquipmentId = pendingRestoreEquipmentId
                || (equipmentSelect instanceof HTMLSelectElement ? String(equipmentSelect.value || '') : '');
            pendingRestoreOrderId = '';
            pendingRestoreEquipmentId = '';
            // Limpeza otimista: some imediatamente com as OS/equipamentos do
            // cliente anterior enquanto a resposta não chega.
            setOrderOptions([]);
            setEquipmentOptions([]);
            applyOrderFieldVisibility();
            loadClientContext(clientId, desiredOrderId, desiredEquipmentId);
        };

        // Na carga: mantemos as opções pré-renderadas quando já há cliente
        // (edição, vindo de OS, ou aberto com ?cliente_id — o backend já filtrou
        // por cliente). Sem cliente (novo em branco), as listas vêm sem filtro e
        // devem ser esvaziadas até que um cliente seja escolhido.
        const initClientDependentFields = () => {
            if (clientLocked || filledSelect(clientSelect)) {
                lastContextClientId = clientLocked || !(clientSelect instanceof HTMLSelectElement)
                    ? lastContextClientId
                    : String(clientSelect.value || '').trim();
                return;
            }

            setOrderOptions([]);
            setEquipmentOptions([]);
        };
        const executionDeadlineInput = document.getElementById('orcamentoPrazoExecucao');
        const observationsInput = document.getElementById('orcamentoObservacoes');
        const conditionsInput = document.getElementById('orcamentoCondicoes');
        const quickItemModal = document.getElementById('orcamentoQuickItemModal');
        const quickItemForm = document.getElementById('orcamentoQuickItemForm');
        const quickItemSubmit = document.getElementById('orcamentoQuickItemSubmit');
        const quickItemType = document.getElementById('orcamentoQuickItemType');
        const quickItemEquipmentType = document.getElementById('orcamentoQuickItemEquipmentType');

        initEquipmentTypeSelect(quickItemEquipmentType);
        const quickItemTitle = document.querySelector('[data-budget-quick-title]');
        const quickItemNote = document.querySelector('[data-budget-quick-note]');
        const quickItemErrors = document.getElementById('orcamentoQuickItemErrors');
        const quickItemNameLabel = document.querySelector('[data-budget-quick-name-label]');
        const quickItemServiceGroup = document.querySelector('[data-budget-quick-group="servico"]');
        const quickItemPartGroup = document.querySelector('[data-budget-quick-group="peca"]');
        const reviewModalElement = document.getElementById('orcamentoReviewModal');
        const reviewPendenciesWrapper = document.querySelector('[data-budget-review-pendencies-wrapper]');
        const reviewPendenciesList = document.querySelector('[data-budget-review-pendencies]');
        const reviewClientContainer = document.querySelector('[data-budget-review-client]');
        const reviewContextContainer = document.querySelector('[data-budget-review-context]');
        const reviewItemsContainer = document.querySelector('[data-budget-review-items]');
        const reviewItemsCount = document.querySelector('[data-budget-review-items-count]');
        const reviewTotalsContainer = document.querySelector('[data-budget-review-totals]');
        const reviewNotesContainer = document.querySelector('[data-budget-review-notes]');
        const reviewTermsContainer = document.querySelector('[data-budget-review-terms]');
        const reviewSubmitButtons = Array.from(document.querySelectorAll('[data-budget-review-submit]'));

        if (!(form instanceof HTMLFormElement) || !(itemsBody instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
            return;
        }

        const submissionModeInput = form.querySelector('[data-budget-submission-mode]');

        bindMoneyInputs(document);

        const tabButtons = Array.from(document.querySelectorAll('[data-budget-tab]'));
        const tabPanels = Array.from(document.querySelectorAll('[data-budget-panel]'));
        const state = {
            draftLoaded: false,
            quickItemRow: null,
            quickItemType: 'servico',
            quickItemSubmitting: false,
            reviewConfirmed: false,
            adminConfirmed: false,
        };

        // OS encerrada (skill sistema-erp-os-fluxo-fechamento): salvar exige
        // confirmação de administrador — ver orcamentos/_admin_confirm_modal.blade.php.
        const budgetIsEncerrada = form.dataset.budgetIsEncerrada === '1';
        const adminConfirmModalElement = document.getElementById('orcamentoAdminConfirmModal');
        const adminEmailInput = document.getElementById('orcamentoAdminEmail');
        const adminPasswordInput = document.getElementById('orcamentoAdminPassword');
        const adminConfirmError = document.getElementById('orcamentoAdminConfirmError');
        const adminConfirmSubmitButton = document.getElementById('orcamentoAdminConfirmSubmit');
        const adminEmailHidden = form.querySelector('[data-budget-admin-email]');
        const adminPasswordHidden = form.querySelector('[data-budget-admin-password]');

        const getRowCatalog = (type) => {
            return type === 'peca' ? catalogs.parts : catalogs.services;
        };

        const updateItemsCount = () => {
            if (!(itemsCount instanceof HTMLElement)) {
                return;
            }

            const count = itemsBody.querySelectorAll('[data-budget-item-row]').length;
            itemsCount.innerHTML = `<i class="bi bi-list-check"></i>${count} item${count === 1 ? '' : 's'}`;
        };

        const populateReferenceSelect = (row, keepSelected = true) => {
            const typeSelect = row.querySelector('[data-budget-item-type]');
            const referenceSelect = row.querySelector('[data-budget-item-reference]');

            if (!(typeSelect instanceof HTMLSelectElement) || !(referenceSelect instanceof HTMLSelectElement)) {
                return;
            }

            const selectedType = typeSelect.value === 'peca' ? 'peca' : 'servico';
            const selectedReference = keepSelected ? String(referenceSelect.dataset.selectedReference || referenceSelect.value || '') : '';
            const catalog = getRowCatalog(selectedType);

            referenceSelect.innerHTML = '';

            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = 'Selecione';
            referenceSelect.appendChild(emptyOption);

            catalog.forEach((item) => {
                const option = document.createElement('option');
                option.value = String(item.id ?? '');
                option.textContent = String(item.label ?? item.description ?? 'Item');
                option.dataset.description = String(item.description ?? '');
                option.dataset.price = String(item.price ?? 0);
                referenceSelect.appendChild(option);
            });

            if (keepSelected && selectedReference !== '') {
                referenceSelect.value = selectedReference;
            }

            referenceSelect.dataset.selectedReference = referenceSelect.value;

            reinitReferenceSelect2(referenceSelect);

            updateRowFromReference(row);
        };

        const getAllowedQuickTypes = () => {
            const allowed = [];

            if (quickCatalogs.service.enabled) {
                allowed.push('servico');
            }

            if (quickCatalogs.part.enabled) {
                allowed.push('peca');
            }

            return allowed;
        };

        const getResolvedQuickType = (type) => {
            const normalized = type === 'peca' ? 'peca' : 'servico';

            if (normalized === 'peca' && quickCatalogs.part.enabled) {
                return normalized;
            }

            if (normalized === 'servico' && quickCatalogs.service.enabled) {
                return normalized;
            }

            return getAllowedQuickTypes()[0] || normalized;
        };

        const getQuickCatalogKey = (type) => (type === 'peca' ? 'part' : 'service');

        const getQuickCatalogConfig = (type) => quickCatalogs[getQuickCatalogKey(type)] || {};

        const getQuickCreateLabelInfo = (type) => {
            const selectedType = type === 'peca' ? 'peca' : 'servico';
            if (selectedType === 'peca') {
                return {
                    label: 'Nova peça',
                    ariaLabel: 'Cadastrar nova peça',
                };
            }

            return {
                label: 'Novo serviço',
                ariaLabel: 'Cadastrar novo serviço',
            };
        };

        const updateQuickCreateButtonLabel = (row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }

            const typeSelect = row.querySelector('[data-budget-item-type]');
            const quickCreateButton = row.querySelector('[data-budget-item-quick-create]');

            if (!(typeSelect instanceof HTMLSelectElement) || !(quickCreateButton instanceof HTMLButtonElement)) {
                return;
            }

            const labelElement = quickCreateButton.querySelector('[data-budget-item-quick-create-label]');
            const { label, ariaLabel } = getQuickCreateLabelInfo(typeSelect.value);

            if (labelElement instanceof HTMLElement) {
                labelElement.textContent = label;
            }

            quickCreateButton.setAttribute('aria-label', ariaLabel);
            quickCreateButton.title = ariaLabel;
        };

        const updateQuickItemSubmitState = (loading) => {
            state.quickItemSubmitting = loading;

            if (!(quickItemSubmit instanceof HTMLButtonElement)) {
                return;
            }

            quickItemSubmit.disabled = loading;
            quickItemSubmit.innerHTML = loading
                ? '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Salvando...'
                : '<i class="bi bi-plus-circle me-2"></i>Salvar e aplicar';
        };

        const clearQuickItemErrors = () => {
            if (!(quickItemErrors instanceof HTMLElement)) {
                return;
            }

            quickItemErrors.classList.add('d-none');
            quickItemErrors.innerHTML = '';
        };

        const renderQuickItemErrors = (messages, fallbackMessage = '') => {
            if (!(quickItemErrors instanceof HTMLElement)) {
                return;
            }

            const items = Array.isArray(messages) ? messages.filter(Boolean) : [];
            quickItemErrors.innerHTML = items.length > 0
                ? `<ul class="mb-0 ps-3">${items.map((message) => `<li>${escapeHtml(message)}</li>`).join('')}</ul>`
                : escapeHtml(fallbackMessage || 'Não foi possível cadastrar o item.');
            quickItemErrors.classList.remove('d-none');
        };

        const updateQuickItemMode = (type) => {
            const resolvedType = getResolvedQuickType(type);
            state.quickItemType = resolvedType;

            if (quickItemType instanceof HTMLSelectElement) {
                quickItemType.value = resolvedType;
            }

            if (quickItemForm instanceof HTMLFormElement) {
                quickItemForm.action = getQuickCatalogConfig(resolvedType).storeUrl || quickItemForm.action;
            }

            if (quickItemTitle instanceof HTMLElement) {
                quickItemTitle.textContent = getQuickCatalogConfig(resolvedType).title || 'Cadastro rápido de item';
            }

            if (quickItemNote instanceof HTMLElement) {
                quickItemNote.textContent = resolvedType === 'peca'
                    ? 'Cadastre a peça e aplique o cadastro novo nesta linha do orçamento sem sair do fluxo.'
                    : 'Cadastre o serviço e aplique o cadastro novo nesta linha do orçamento sem sair do fluxo.';
            }

            if (quickItemNameLabel instanceof HTMLElement) {
                quickItemNameLabel.textContent = resolvedType === 'peca' ? 'Nome da peça *' : 'Nome do serviço *';
            }

            const nameField = quickItemForm?.querySelector('[name="nome"]');
            if (nameField instanceof HTMLInputElement) {
                nameField.placeholder = resolvedType === 'peca'
                    ? 'Ex.: SSD 480GB, bateria, tela LCD...'
                    : 'Ex.: Troca de conector, limpeza interna...';
            }

            if (quickItemServiceGroup instanceof HTMLElement) {
                quickItemServiceGroup.hidden = resolvedType !== 'servico';
            }

            if (quickItemPartGroup instanceof HTMLElement) {
                quickItemPartGroup.hidden = resolvedType !== 'peca';
            }

            if (quickItemSubmit instanceof HTMLButtonElement) {
                quickItemSubmit.innerHTML = `<i class="bi bi-plus-circle me-2"></i>${getQuickCatalogConfig(resolvedType).submitLabel || 'Salvar e aplicar'}`;
            }
        };

        const resetQuickItemForm = () => {
            if (!(quickItemForm instanceof HTMLFormElement)) {
                return;
            }

            quickItemForm.reset();
            setEquipmentTypeValue(quickItemForm.querySelector('[name="tipo_equipamento"]'), '');
            clearQuickItemErrors();
            updateQuickItemSubmitState(false);
        };

        const fillQuickItemFormFromRow = (row, type) => {
            if (!(quickItemForm instanceof HTMLFormElement) || !(row instanceof HTMLElement)) {
                return;
            }

            const descriptionInput = row.querySelector('[data-budget-item-description]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
            const referenceSelect = row.querySelector('[data-budget-item-reference]');
            const selectedReferenceOption = referenceSelect instanceof HTMLSelectElement ? referenceSelect.selectedOptions?.[0] : null;
            const preferredName = normalizeText(
                selectedReferenceOption instanceof HTMLOptionElement && selectedReferenceOption.value !== ''
                    ? selectedReferenceOption.dataset.description || selectedReferenceOption.textContent || ''
                    : descriptionInput instanceof HTMLInputElement
                        ? descriptionInput.value
                        : ''
            );
            const preferredPrice = unitPriceInput instanceof HTMLInputElement ? unitPriceInput.value : '0.00';

            if (quickItemType instanceof HTMLSelectElement) {
                quickItemType.value = getResolvedQuickType(type);
            }

            const nameField = quickItemForm.querySelector('[name="nome"]');
            if (nameField instanceof HTMLInputElement) {
                nameField.value = preferredName;
            }

            setEquipmentTypeValue(quickItemForm.querySelector('[name="tipo_equipamento"]'), '');

            if ((type === 'peca' || quickItemType?.value === 'peca') && quickItemForm instanceof HTMLFormElement) {
                const salePriceField = quickItemForm.querySelector('[name="preco_venda"]');
                if (salePriceField instanceof HTMLInputElement) {
                    salePriceField.value = formatMoney(preferredPrice);
                }
            }

            if ((type === 'servico' || quickItemType?.value === 'servico') && quickItemForm instanceof HTMLFormElement) {
                const serviceValueField = quickItemForm.querySelector('[name="valor"]');
                if (serviceValueField instanceof HTMLInputElement) {
                    serviceValueField.value = formatMoney(preferredPrice);
                }
            }
        };

        const openQuickItemModal = (row) => {
            if (!(row instanceof HTMLElement) || !(quickItemModal instanceof HTMLElement)) {
                return;
            }

            const typeSelect = row.querySelector('[data-budget-item-type]');
            const currentType = typeSelect instanceof HTMLSelectElement ? typeSelect.value : 'servico';
            const resolvedType = getResolvedQuickType(currentType);

            if (!getAllowedQuickTypes().includes(resolvedType)) {
                showAlert('warning', 'Cadastro indisponível', 'Você não tem permissão para cadastrar esse tipo de item agora.');
                return;
            }

            state.quickItemRow = row;
            resetQuickItemForm();
            updateQuickItemMode(resolvedType);
            fillQuickItemFormFromRow(row, resolvedType);
            clearQuickItemErrors();
            getModal(quickItemModal)?.show();
        };

        const upsertCatalogItem = (type, item) => {
            const catalog = getRowCatalog(type);
            const index = catalog.findIndex((entry) => String(entry.id ?? '') === String(item.id ?? ''));

            if (index >= 0) {
                catalog[index] = item;
                return;
            }

            catalog.push(item);
        };

        const applyQuickItemToRow = (row, type, item) => {
            if (!(row instanceof HTMLElement)) {
                return false;
            }

            const typeSelect = row.querySelector('[data-budget-item-type]');
            const referenceSelect = row.querySelector('[data-budget-item-reference]');

            if (!(typeSelect instanceof HTMLSelectElement) || !(referenceSelect instanceof HTMLSelectElement)) {
                return false;
            }

            typeSelect.value = type;
            updateQuickCreateButtonLabel(row);
            referenceSelect.dataset.selectedReference = String(item.id || '');
            populateReferenceSelect(row, true);
            updateSummary();

            return true;
        };

        const handleQuickItemSubmit = async (event) => {
            event.preventDefault();

            if (!(quickItemForm instanceof HTMLFormElement) || !(state.quickItemRow instanceof HTMLElement)) {
                return;
            }

            clearQuickItemErrors();

            if (!quickItemForm.reportValidity()) {
                renderQuickItemErrors([], 'Preencha os campos obrigatórios antes de salvar.');
                return;
            }

            const type = quickItemType instanceof HTMLSelectElement ? getResolvedQuickType(quickItemType.value) : state.quickItemType;
            const storeUrl = getQuickCatalogConfig(type).storeUrl || '';

            if (storeUrl === '') {
                renderQuickItemErrors([], 'Nenhuma rota de cadastro rápido está disponível para este tipo.');
                return;
            }

            updateQuickItemSubmitState(true);

            try {
                const payload = Object.fromEntries(new FormData(quickItemForm).entries());
                const moneyFields = type === 'peca'
                    ? ['preco_venda', 'preco_custo']
                    : ['valor', 'custo_direto_padrao'];

                moneyFields.forEach((field) => {
                    if (Object.prototype.hasOwnProperty.call(payload, field)) {
                        payload[field] = toNumber(payload[field]);
                    }
                });

                const response = await requestJson(storeUrl, {
                    method: 'POST',
                    body: payload,
                });

                const sourceItem = type === 'peca'
                    ? response.part || {}
                    : response.service || {};
                const normalizedItem = type === 'peca'
                    ? normalizePartCatalogItem(sourceItem)
                    : normalizeServiceCatalogItem(sourceItem);

                if (normalizedItem.id <= 0) {
                    throw new Error('O cadastro foi concluído, mas a resposta não trouxe um item válido.');
                }

                upsertCatalogItem(type, normalizedItem);
                applyQuickItemToRow(state.quickItemRow, type, normalizedItem);

                const rowLabel = type === 'peca'
                    ? 'Peça cadastrada e aplicada.'
                    : 'Serviço cadastrado e aplicado.';

                getModal(quickItemModal)?.hide();
                showToast('success', rowLabel);
            } catch (error) {
                const details = extractErrorMessages(error?.details);
                renderQuickItemErrors(details, error.message);
                showAlert('error', type === 'peca' ? 'Falha ao cadastrar peça' : 'Falha ao cadastrar serviço', error.message);
            } finally {
                updateQuickItemSubmitState(false);
            }
        };

        const resolveAdjustmentMode = (value) => (String(value ?? '').trim() === 'percentual' ? 'percentual' : 'valor');

        const clampNonNegative = (value) => Math.max(0, toNumber(value));

        const calculatePercentAmount = (base, percent) => roundCurrency((Math.max(0, base) * Math.max(0, percent)) / 100);

        const calculateAmountPercent = (base, amount) => {
            if (base <= 0) {
                return 0;
            }

            return roundPercent((Math.max(0, amount) / base) * 100);
        };

        const getAdjustmentControl = (root, selectors) => {
            if (!(root instanceof Document || root instanceof HTMLElement)) {
                return null;
            }

            const displayInput = root.querySelector(selectors.display);
            const typeField = root.querySelector(selectors.type);
            const amountInput = root.querySelector(selectors.amount);
            const percentInput = root.querySelector(selectors.percent);
            const previewInput = selectors.preview ? root.querySelector(selectors.preview) : null;
            const previewWrapper = selectors.previewWrapper ? root.querySelector(selectors.previewWrapper) : null;
            const group = displayInput instanceof HTMLInputElement
                ? displayInput.closest('[data-budget-adjustment-group]')
                : null;
            const modeButtons = group instanceof HTMLElement
                ? Array.from(group.querySelectorAll('[data-budget-adjustment-option]')).filter((button) => button instanceof HTMLButtonElement)
                : [];

            if (!(displayInput instanceof HTMLInputElement) || !((typeField instanceof HTMLInputElement) || (typeField instanceof HTMLSelectElement)) || !(amountInput instanceof HTMLInputElement) || !(percentInput instanceof HTMLInputElement)) {
                return null;
            }

            return {
                displayInput,
                typeField,
                amountInput,
                percentInput,
                previewInput: previewInput instanceof HTMLInputElement ? previewInput : null,
                previewWrapper: previewWrapper instanceof HTMLElement ? previewWrapper : null,
                modeButtons,
            };
        };

        const syncAdjustmentModeButtons = (control) => {
            if (control === null) {
                return;
            }

            const mode = resolveAdjustmentMode(control.typeField.value);
            control.modeButtons.forEach((button) => {
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }

                const active = button.dataset.budgetAdjustmentOption === mode;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        };

        const syncAdjustmentPreview = (control, amount) => {
            if (control === null) {
                return;
            }

            if (control.previewInput instanceof HTMLInputElement) {
                control.previewInput.value = formatMoney(amount);
            }

            if (control.previewWrapper instanceof HTMLElement) {
                control.previewWrapper.hidden = resolveAdjustmentMode(control.typeField.value) !== 'percentual';
            }
        };

        const renderAdjustmentDisplay = (control, amount, percent) => {
            if (control === null) {
                return;
            }

            control.displayInput.value = resolveAdjustmentMode(control.typeField.value) === 'percentual'
                ? formatPercent(percent)
                : formatMoney(amount);
        };

        const syncAdjustmentControl = (control, base, { readDisplay = true, formatDisplay = false } = {}) => {
            if (control === null) {
                return { mode: 'valor', amount: 0, percent: 0 };
            }

            const mode = resolveAdjustmentMode(control.typeField.value);
            let amount = clampNonNegative(control.amountInput.value);
            let percent = clampNonNegative(control.percentInput.value);

            if (mode === 'percentual') {
                if (readDisplay) {
                    percent = roundPercent(clampNonNegative(control.displayInput.value));
                }

                amount = calculatePercentAmount(base, percent);
            } else {
                if (readDisplay) {
                    amount = roundCurrency(clampNonNegative(control.displayInput.value));
                } else {
                    amount = roundCurrency(amount);
                }

                percent = calculateAmountPercent(base, amount);
            }

            control.amountInput.value = formatCanonicalNumber(amount, 2);
            control.percentInput.value = formatCanonicalNumber(mode === 'percentual' ? percent : 0, 4);
            syncAdjustmentModeButtons(control);
            syncAdjustmentPreview(control, amount);

            if (formatDisplay) {
                renderAdjustmentDisplay(control, amount, mode === 'percentual' ? percent : calculateAmountPercent(base, amount));
            }

            return { mode, amount, percent };
        };

        const switchAdjustmentMode = (control, base) => {
            if (control === null) {
                return;
            }

            const mode = resolveAdjustmentMode(control.typeField.value);

            if (mode === 'percentual') {
                const amount = roundCurrency(clampNonNegative(control.amountInput.value));
                const percent = calculateAmountPercent(base, amount);
                control.percentInput.value = formatCanonicalNumber(percent, 4);
            } else {
                const percent = roundPercent(clampNonNegative(control.percentInput.value));
                const amount = calculatePercentAmount(base, percent);
                control.amountInput.value = formatCanonicalNumber(amount, 2);
            }

            syncAdjustmentControl(control, base, { readDisplay: false, formatDisplay: true });
        };

        const bindAdjustmentControl = (control, getBaseValue, onUpdate) => {
            if (control === null || control.displayInput.dataset.adjustmentBound === '1') {
                return;
            }

            control.displayInput.dataset.adjustmentBound = '1';
            control.displayInput.type = 'text';
            control.displayInput.inputMode = 'decimal';
            control.displayInput.autocomplete = 'off';
            control.displayInput.spellcheck = false;

            control.displayInput.addEventListener('focus', () => {
                window.requestAnimationFrame(() => {
                    control.displayInput.select();
                });
            });

            control.displayInput.addEventListener('blur', () => {
                syncAdjustmentControl(control, getBaseValue(), { readDisplay: true, formatDisplay: true });
                onUpdate();
            });

            if (control.typeField instanceof HTMLSelectElement) {
                control.typeField.addEventListener('change', () => {
                    switchAdjustmentMode(control, getBaseValue());
                    onUpdate();
                });
            }

            control.modeButtons.forEach((button) => {
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }

                button.addEventListener('click', () => {
                    const nextMode = resolveAdjustmentMode(button.dataset.budgetAdjustmentOption);
                    if (resolveAdjustmentMode(control.typeField.value) === nextMode) {
                        syncAdjustmentModeButtons(control);
                        return;
                    }

                    control.typeField.value = nextMode;
                    switchAdjustmentMode(control, getBaseValue());
                    onUpdate();
                });
            });

            syncAdjustmentModeButtons(control);
        };

        const getRowBaseAmount = (row) => {
            const quantityInput = row.querySelector('[data-budget-item-quantity]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');

            if (!(quantityInput instanceof HTMLInputElement) || !(unitPriceInput instanceof HTMLInputElement)) {
                return 0;
            }

            return roundCurrency(toNumber(quantityInput.value) * toNumber(unitPriceInput.value));
        };

        const getGlobalDiscountControl = () => getAdjustmentControl(document, {
            display: '[data-budget-global-discount-display]',
            type: '[data-budget-global-discount-type]',
            amount: '[data-budget-global-discount]',
            percent: '[data-budget-global-discount-percent]',
            preview: '[data-budget-global-discount-preview]',
            previewWrapper: '[data-budget-global-discount-preview-wrapper]',
        });

        const getGlobalAdditionControl = () => getAdjustmentControl(document, {
            display: '[data-budget-global-addition-display]',
            type: '[data-budget-global-addition-type]',
            amount: '[data-budget-global-addition]',
            percent: '[data-budget-global-addition-percent]',
            preview: '[data-budget-global-addition-preview]',
            previewWrapper: '[data-budget-global-addition-preview-wrapper]',
        });

        const getRowDiscountControl = (row) => getAdjustmentControl(row, {
            display: '[data-budget-item-discount-display]',
            type: '[data-budget-item-discount-type]',
            amount: '[data-budget-item-discount]',
            percent: '[data-budget-item-discount-percent]',
            preview: '[data-budget-item-discount-preview]',
            previewWrapper: '[data-budget-item-discount-preview-wrapper]',
        });

        const getRowAdditionControl = (row) => getAdjustmentControl(row, {
            display: '[data-budget-item-addition-display]',
            type: '[data-budget-item-addition-type]',
            amount: '[data-budget-item-addition]',
            percent: '[data-budget-item-addition-percent]',
            preview: '[data-budget-item-addition-preview]',
            previewWrapper: '[data-budget-item-addition-preview-wrapper]',
        });

        const updateRowTotal = (row) => {
            const quantityInput = row.querySelector('[data-budget-item-quantity]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
            const totalInput = row.querySelector('[data-budget-item-total]');

            if (!(quantityInput instanceof HTMLInputElement) || !(unitPriceInput instanceof HTMLInputElement) || !(totalInput instanceof HTMLInputElement)) {
                return 0;
            }

            const quantity = toNumber(quantityInput.value);
            const unitPrice = toNumber(unitPriceInput.value);
            const base = roundCurrency(quantity * unitPrice);
            const discount = syncAdjustmentControl(getRowDiscountControl(row), base, { readDisplay: true }).amount;
            const addition = syncAdjustmentControl(getRowAdditionControl(row), base, { readDisplay: true }).amount;

            const total = roundCurrency(base - discount + addition);
            totalInput.value = formatMoney(total);

            return total;
        };

        const updateSummary = () => {
            let subtotal = 0;

            itemsBody.querySelectorAll('[data-budget-item-row]').forEach((row) => {
                subtotal += updateRowTotal(row);
            });

            if (subtotalInput instanceof HTMLInputElement) {
                subtotalInput.value = formatMoney(subtotal);
            }

            const discount = syncAdjustmentControl(getGlobalDiscountControl(), subtotal, { readDisplay: true }).amount;
            const addition = syncAdjustmentControl(getGlobalAdditionControl(), subtotal, { readDisplay: true }).amount;
            const total = roundCurrency(subtotal - discount + addition);

            if (totalInput instanceof HTMLInputElement) {
                totalInput.value = formatMoney(total);
            }

            updateItemsCount();
            saveDraftDebounced();
            syncPrimaryAction();
        };

        const getSelectedOptionLabel = (select) => {
            if (!(select instanceof HTMLSelectElement)) {
                return '';
            }

            const option = select.selectedOptions[0];

            return option instanceof HTMLOptionElement ? normalizeText(option.textContent) : '';
        };

        const formatAdjustmentSummary = ({ mode, amount, percent }) => {
            if (mode === 'percentual') {
                return `${formatPercent(percent)}% (${formatMoney(amount)})`;
            }

            return formatMoney(amount);
        };

        const rowHasMeaningfulContent = (row) => {
            if (!(row instanceof HTMLElement)) {
                return false;
            }

            const descriptionInput = row.querySelector('[data-budget-item-description]');
            const referenceSelect = row.querySelector('[data-budget-item-reference]');
            const quantityInput = row.querySelector('[data-budget-item-quantity]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
            const notesInput = row.querySelector('[data-budget-item-notes]');
            const discountControl = getRowDiscountControl(row);
            const additionControl = getRowAdditionControl(row);
            const quantity = quantityInput instanceof HTMLInputElement ? toNumber(quantityInput.value) : 1;
            const unitPrice = unitPriceInput instanceof HTMLInputElement ? toNumber(unitPriceInput.value) : 0;
            const description = descriptionInput instanceof HTMLInputElement ? normalizeText(descriptionInput.value) : '';
            const reference = referenceSelect instanceof HTMLSelectElement ? normalizeText(referenceSelect.value) : '';
            const notes = notesInput instanceof HTMLTextAreaElement ? normalizeText(notesInput.value) : '';
            const discountAmount = discountControl ? toNumber(discountControl.amountInput.value) : 0;
            const discountPercent = discountControl ? toNumber(discountControl.percentInput.value) : 0;
            const additionAmount = additionControl ? toNumber(additionControl.amountInput.value) : 0;
            const additionPercent = additionControl ? toNumber(additionControl.percentInput.value) : 0;

            return description !== ''
                || reference !== ''
                || notes !== ''
                || Math.abs(quantity - 1) > 0.0001
                || unitPrice > 0
                || discountAmount > 0
                || discountPercent > 0
                || additionAmount > 0
                || additionPercent > 0;
        };

        const removeEmptyRows = () => {
            itemsBody.querySelectorAll('[data-budget-item-row]').forEach((row) => {
                if (!rowHasMeaningfulContent(row)) {
                    row.remove();
                }
            });
        };

        const collectReviewItems = () => {
            const items = [];

            itemsBody.querySelectorAll('[data-budget-item-row]').forEach((row, index) => {
                const rowTypeSelect = row.querySelector('[data-budget-item-type]');
                const referenceSelect = row.querySelector('[data-budget-item-reference]');
                const descriptionInput = row.querySelector('[data-budget-item-description]');
                const quantityInput = row.querySelector('[data-budget-item-quantity]');
                const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
                const notesInput = row.querySelector('[data-budget-item-notes]');
                const base = getRowBaseAmount(row);
                const discount = syncAdjustmentControl(getRowDiscountControl(row), base, { readDisplay: true, formatDisplay: true });
                const addition = syncAdjustmentControl(getRowAdditionControl(row), base, { readDisplay: true, formatDisplay: true });
                const total = updateRowTotal(row);
                const description = descriptionInput instanceof HTMLInputElement ? normalizeText(descriptionInput.value) : '';
                const referenceLabel = getSelectedOptionLabel(referenceSelect);
                const quantity = quantityInput instanceof HTMLInputElement ? toNumber(quantityInput.value) : 0;
                const unitPrice = unitPriceInput instanceof HTMLInputElement ? toNumber(unitPriceInput.value) : 0;
                const notes = notesInput instanceof HTMLTextAreaElement ? normalizeText(notesInput.value) : '';
                const type = rowTypeSelect instanceof HTMLSelectElement && rowTypeSelect.value === 'peca' ? 'peca' : 'servico';
                const hasContent = rowHasMeaningfulContent(row);

                if (!hasContent) {
                    return;
                }

                items.push({
                    index: index + 1,
                    type,
                    typeLabel: type === 'peca' ? 'Peca' : 'Servico',
                    referenceLabel,
                    description,
                    quantity,
                    unitPrice,
                    discount,
                    addition,
                    total,
                    notes,
                });
            });

            return items;
        };

        const collectReviewSnapshot = () => {
            updateSummary();

            const globalDiscount = syncAdjustmentControl(getGlobalDiscountControl(), toNumber(subtotalInput?.value), { readDisplay: true, formatDisplay: true });
            const globalAddition = syncAdjustmentControl(getGlobalAdditionControl(), toNumber(subtotalInput?.value), { readDisplay: true, formatDisplay: true });
            const items = collectReviewItems();
            const clientName = getSelectedOptionLabel(clientSelect) || normalizeText(clientFallbackInput?.value);
            const phone = normalizeText(phoneInput?.value);
            const digits = (phone.match(/\d/g) || []).join('');

            return {
                title: normalizeText(titleInput?.value),
                clientName,
                phone,
                email: normalizeText(emailInput?.value),
                orderLabel: getSelectedOptionLabel(orderSelect),
                equipmentLabel: getSelectedOptionLabel(equipmentSelect),
                typeLabel: typeDisplay instanceof HTMLInputElement ? normalizeText(typeDisplay.value) : '',
                originLabel: originDisplay instanceof HTMLInputElement ? normalizeText(originDisplay.value) : '',
                statusLabel: getSelectedOptionLabel(statusSelect),
                validityDays: normalizeText(validityDaysSelect?.value),
                validityDate: normalizeText(validityDateInput?.value),
                executionDeadline: normalizeText(executionDeadlineInput?.value),
                observations: normalizeText(observationsInput?.value),
                conditions: normalizeText(conditionsInput?.value),
                terms: collectCommercialTerms(),
                subtotal: toNumber(subtotalInput?.value),
                total: toNumber(totalInput?.value),
                globalDiscount,
                globalAddition,
                items,
                phoneDigits: digits,
            };
        };

        const collectReviewPendencies = (snapshot) => {
            const pendencies = [];

            if (snapshot.clientName === '') {
                pendencies.push('Informe um cliente cadastrado ou um nome de cliente eventual antes de enviar para aprovacao.');
            }

            if (snapshot.items.length === 0) {
                pendencies.push('Adicione ao menos um item com conteudo no orcamento.');
            }

            snapshot.items.forEach((item) => {
                if (item.description === '') {
                    pendencies.push(`Item ${item.index}: informe a descricao antes de enviar para aprovacao.`);
                }

                if (item.quantity <= 0) {
                    pendencies.push(`Item ${item.index}: a quantidade precisa ser maior que zero.`);
                }

                if (item.total <= 0) {
                    pendencies.push(`Item ${item.index}: o total precisa ser maior que zero para envio.`);
                }
            });

            if (snapshot.total <= 0) {
                pendencies.push('O total final precisa ser maior que zero para gerar a proposta de aprovacao.');
            }

            if (snapshot.phoneDigits.length < 10) {
                pendencies.push('Informe um telefone de contato com WhatsApp valido para enviar o PDF ao cliente.');
            }

            return pendencies;
        };

        /**
         * Le as condicoes comerciais marcadas no formulario (formas de
         * pagamento, parcelamento, chave Pix e garantia) para a revisao final
         * mostrar exatamente o que o cliente vai receber.
         */
        const collectCommercialTerms = () => {
            const termsSection = document.querySelector('[data-budget-terms]');

            if (!(termsSection instanceof HTMLElement)) {
                return { methods: [], installments: '', warranty: '', pixKeys: [], acceptsPix: false };
            }

            const checked = Array.from(termsSection.querySelectorAll('[data-budget-payment-method]'))
                .filter((input) => input.checked);

            const methods = checked.map((input) => {
                const label = termsSection.querySelector(`label[for="${CSS.escape(input.id)}"]`);

                return normalizeText(label?.textContent) || input.value;
            });

            const acceptsPix = checked.some((input) => input.getAttribute('data-pix') === '1');
            const installmentsSelect = termsSection.querySelector('[data-budget-installments]');
            const warrantySelect = document.getElementById('orcamentoGarantia');

            // Só os rótulos das chaves: o marcador exclui tanto o badge
            // "Principal" quanto o texto de estado vazio do bloco.
            const pixKeys = acceptsPix
                ? Array.from(termsSection.querySelectorAll('[data-budget-pix-key]'))
                    .map((node) => normalizeText(node.textContent))
                    .filter((text) => text !== '')
                : [];

            return {
                methods,
                acceptsPix,
                installments: installmentsSelect instanceof HTMLSelectElement && !installmentsSelect.closest('.d-none')
                    ? normalizeText(installmentsSelect.value)
                    : '',
                warranty: warrantySelect instanceof HTMLSelectElement
                    ? getSelectedOptionLabel(warrantySelect)
                    : '',
                pixKeys,
            };
        };

        const renderReviewTerms = (snapshot) => {
            const terms = snapshot.terms || { methods: [], pixKeys: [] };
            const entries = [
                {
                    label: 'Formas de pagamento',
                    value: terms.methods.length > 0
                        ? terms.methods.join(', ')
                        : 'Nenhuma forma selecionada',
                },
            ];

            if (terms.installments !== '') {
                entries.push({
                    label: 'Parcelamento sem juros',
                    value: `Em ate ${terms.installments}x sem juros`,
                });
            }

            if (terms.acceptsPix) {
                entries.push({
                    label: terms.pixKeys.length > 1 ? 'Chaves Pix' : 'Chave Pix',
                    value: terms.pixKeys.length > 0
                        ? terms.pixKeys.join(' | ')
                        : 'Nenhuma chave cadastrada nas configuracoes financeiras',
                });
            }

            entries.push({
                label: 'Garantia',
                value: terms.warranty !== '' && terms.warranty !== 'Sem garantia definida'
                    ? terms.warranty
                    : 'Sem garantia definida',
            });

            return renderReviewEntries(entries);
        };

        const renderReviewEntries = (entries) => entries
            .map(({ label, value }) => {
                const resolvedValue = normalizeText(value) !== '' ? value : '—';

                return `
                    <div class="budget-review-list-item">
                        <span>${escapeHtml(label)}</span>
                        <strong>${escapeHtml(resolvedValue)}</strong>
                    </div>
                `;
            })
            .join('');

        const renderReviewItems = (items) => {
            if (!Array.isArray(items) || items.length === 0) {
                return '<div class="budget-review-empty">Nenhum item preenchido ate o momento.</div>';
            }

            return items.map((item) => `
                <article class="budget-review-item">
                    <div class="budget-review-item-head">
                        <div>
                            <strong>${escapeHtml(item.description !== '' ? item.description : 'Item sem descricao')}</strong>
                            <span>${escapeHtml(item.typeLabel)}${item.referenceLabel !== '' ? ` • ${escapeHtml(item.referenceLabel)}` : ''}</span>
                        </div>
                        <strong>${escapeHtml(formatMoney(item.total))}</strong>
                    </div>
                    <div class="budget-review-item-meta">
                        <span>Qtd: ${escapeHtml(numberFormatter(item.quantity))}</span>
                        <span>Valor unit.: ${escapeHtml(formatMoney(item.unitPrice))}</span>
                        <span>Desconto: ${escapeHtml(formatAdjustmentSummary(item.discount))}</span>
                        <span>Acrescimo: ${escapeHtml(formatAdjustmentSummary(item.addition))}</span>
                    </div>
                    ${item.notes !== '' ? `<p class="budget-review-item-notes">${escapeHtml(item.notes)}</p>` : ''}
                </article>
            `).join('');
        };

        const renderReviewTotals = (snapshot) => renderReviewEntries([
            { label: 'Subtotal', value: formatMoney(snapshot.subtotal) },
            { label: 'Desconto geral', value: formatAdjustmentSummary(snapshot.globalDiscount) },
            { label: 'Acrescimo geral', value: formatAdjustmentSummary(snapshot.globalAddition) },
            { label: 'Total final', value: formatMoney(snapshot.total) },
        ]);

        const renderReviewNotes = (snapshot) => {
            const blocks = [
                {
                    label: 'Titulo do orcamento',
                    value: snapshot.title,
                },
                {
                    label: 'Observacoes internas',
                    value: snapshot.observations,
                },
                {
                    label: 'Observacoes complementares',
                    value: snapshot.conditions,
                },
            ];

            return blocks.map(({ label, value }) => `
                <div class="budget-review-note-block">
                    <span>${escapeHtml(label)}</span>
                    <strong>${escapeHtml(normalizeText(value) !== '' ? value : 'Nao informado')}</strong>
                </div>
            `).join('');
        };

        const numberFormatter = (value) => new Intl.NumberFormat('pt-BR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        }).format(toNumber(value));

        const renderReviewModal = () => {
            if (!(reviewModalElement instanceof HTMLElement)) {
                return { pendencies: [] };
            }

            const snapshot = collectReviewSnapshot();
            const pendencies = collectReviewPendencies(snapshot);
            const validityLabel = snapshot.validityDate !== ''
                ? snapshot.validityDate
                : (snapshot.validityDays !== '' ? `${snapshot.validityDays} dias` : '');

            if (reviewClientContainer instanceof HTMLElement) {
                reviewClientContainer.innerHTML = renderReviewEntries([
                    { label: 'Cliente', value: snapshot.clientName },
                    { label: 'Telefone', value: snapshot.phone },
                    { label: 'E-mail', value: snapshot.email },
                ]);
            }

            if (reviewContextContainer instanceof HTMLElement) {
                reviewContextContainer.innerHTML = renderReviewEntries([
                    { label: 'OS vinculada', value: snapshot.orderLabel },
                    { label: 'Equipamento', value: snapshot.equipmentLabel },
                    { label: 'Tipo', value: snapshot.typeLabel },
                    { label: 'Origem', value: snapshot.originLabel },
                    { label: 'Status', value: snapshot.statusLabel },
                    { label: 'Validade', value: validityLabel },
                    { label: 'Prazo de execucao', value: snapshot.executionDeadline },
                ]);
            }

            if (reviewItemsContainer instanceof HTMLElement) {
                reviewItemsContainer.innerHTML = renderReviewItems(snapshot.items);
            }

            if (reviewItemsCount instanceof HTMLElement) {
                reviewItemsCount.textContent = `${snapshot.items.length} item${snapshot.items.length === 1 ? '' : 's'}`;
            }

            if (reviewTotalsContainer instanceof HTMLElement) {
                reviewTotalsContainer.innerHTML = renderReviewTotals(snapshot);
            }

            if (reviewTermsContainer instanceof HTMLElement) {
                reviewTermsContainer.innerHTML = renderReviewTerms(snapshot);
            }

            if (reviewNotesContainer instanceof HTMLElement) {
                reviewNotesContainer.innerHTML = renderReviewNotes(snapshot);
            }

            if (reviewPendenciesWrapper instanceof HTMLElement && reviewPendenciesList instanceof HTMLElement) {
                if (pendencies.length > 0) {
                    reviewPendenciesList.innerHTML = pendencies.map((message) => `<li>${escapeHtml(message)}</li>`).join('');
                    reviewPendenciesWrapper.classList.remove('d-none');
                } else {
                    reviewPendenciesList.innerHTML = '';
                    reviewPendenciesWrapper.classList.add('d-none');
                }
            }

            reviewSubmitButtons.forEach((button) => {
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }

                const requiresReadyState = !isEditMode
                    || button.dataset.budgetReviewSubmit === 'send_for_approval';
                button.disabled = requiresReadyState && pendencies.length > 0;
                button.title = button.disabled ? 'Resolva as pendências obrigatórias antes de concluir.' : '';
            });

            return { pendencies };
        };

        const updateRowFromReference = (row) => {
            const referenceSelect = row.querySelector('[data-budget-item-reference]');
            const descriptionInput = row.querySelector('[data-budget-item-description]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');

            if (!(referenceSelect instanceof HTMLSelectElement) || !(descriptionInput instanceof HTMLInputElement) || !(unitPriceInput instanceof HTMLInputElement)) {
                return;
            }

            const option = referenceSelect.selectedOptions[0];

            if (option instanceof HTMLOptionElement && option.value !== '') {
                const description = String(option.dataset.description || option.textContent || '');
                const price = String(option.dataset.price || '0');

                if (description.trim() !== '') {
                    descriptionInput.value = description;
                }

                unitPriceInput.value = formatMoney(price);
            }

            updateRowTotal(row);
        };

        const bindRow = (row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }

            bindMoneyInputs(row);

            const typeSelect = row.querySelector('[data-budget-item-type]');
            const referenceSelect = row.querySelector('[data-budget-item-reference]');
            const quantityInput = row.querySelector('[data-budget-item-quantity]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
            const quickCreateButton = row.querySelector('[data-budget-item-quick-create]');
            const removeButton = row.querySelector('[data-budget-item-remove]');
            const discountControl = getRowDiscountControl(row);
            const additionControl = getRowAdditionControl(row);

            bindAdjustmentControl(discountControl, () => getRowBaseAmount(row), updateSummary);
            bindAdjustmentControl(additionControl, () => getRowBaseAmount(row), updateSummary);

            const handleTypeChange = () => {
                if (referenceSelect instanceof HTMLSelectElement) {
                    referenceSelect.dataset.selectedReference = '';
                    referenceSelect.value = '';
                }

                updateQuickCreateButtonLabel(row);
                populateReferenceSelect(row, false);
                updateSummary();
            };

            const handleReferenceChange = () => {
                if (referenceSelect instanceof HTMLSelectElement) {
                    referenceSelect.dataset.selectedReference = referenceSelect.value;
                }

                updateRowFromReference(row);
                updateSummary();
            };

            onSelectEvent(typeSelect, 'change', handleTypeChange);
            onSelectEvent(typeSelect, 'select2:select', handleTypeChange);
            onSelectEvent(typeSelect, 'select2:clear', handleTypeChange);

            onSelectEvent(referenceSelect, 'change', handleReferenceChange);
            onSelectEvent(referenceSelect, 'select2:select', handleReferenceChange);
            onSelectEvent(referenceSelect, 'select2:clear', handleReferenceChange);

            quickCreateButton?.addEventListener('click', () => openQuickItemModal(row));

            [quantityInput, unitPriceInput].forEach((input) => {
                input?.addEventListener('input', () => updateSummary());
                input?.addEventListener('change', () => updateSummary());
            });

            removeButton?.addEventListener('click', () => {
                if (itemsBody.querySelectorAll('[data-budget-item-row]').length <= 1) {
                    if (window.Swal) {
                        window.Swal.fire({
                            icon: 'info',
                            title: 'Ao menos um item',
                            text: 'O orçamento precisa manter pelo menos um item.',
                        });
                    }
                    return;
                }

                row.remove();
                updateSummary();
            });

            populateReferenceSelect(row);
            updateQuickCreateButtonLabel(row);
        };

        const createRow = (data = {}) => {
            const currentIndex = itemsBody.querySelectorAll('[data-budget-item-row]').length;
            const index = data.index ?? currentIndex;
            const html = template.innerHTML.replaceAll('__INDEX__', String(index));
            const wrapper = document.createElement('tbody');
            wrapper.innerHTML = html.trim();
            const row = wrapper.querySelector('[data-budget-item-row]');

            if (!(row instanceof HTMLElement)) {
                return null;
            }

            const fields = {
                tipo_item: 'servico',
                referencia_id: '',
                descricao: '',
                quantidade: 1,
                valor_unitario: 0,
                desconto: 0,
                desconto_tipo: 'valor',
                desconto_percentual: 0,
                acrescimo: 0,
                acrescimo_tipo: 'valor',
                acrescimo_percentual: 0,
                observacoes: '',
                modo_precificacao: 'manual',
                ...data,
            };

            const typeSelect = row.querySelector('[data-budget-item-type]');
            const referenceSelect = row.querySelector('[data-budget-item-reference]');
            const descriptionInput = row.querySelector('[data-budget-item-description]');
            const quantityInput = row.querySelector('[data-budget-item-quantity]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
            const discountDisplayInput = row.querySelector('[data-budget-item-discount-display]');
            const discountTypeSelect = row.querySelector('[data-budget-item-discount-type]');
            const discountInput = row.querySelector('[data-budget-item-discount]');
            const discountPercentInput = row.querySelector('[data-budget-item-discount-percent]');
            const additionDisplayInput = row.querySelector('[data-budget-item-addition-display]');
            const additionTypeSelect = row.querySelector('[data-budget-item-addition-type]');
            const additionInput = row.querySelector('[data-budget-item-addition]');
            const additionPercentInput = row.querySelector('[data-budget-item-addition-percent]');
            const notesInput = row.querySelector('[data-budget-item-notes]');
            const modeInput = row.querySelector('[data-budget-item-mode]');
            const discountMode = resolveAdjustmentMode(fields.desconto_tipo);
            const additionMode = resolveAdjustmentMode(fields.acrescimo_tipo);

            if (typeSelect instanceof HTMLSelectElement) {
                typeSelect.value = String(fields.tipo_item || 'servico');
            }
            if (referenceSelect instanceof HTMLSelectElement) {
                referenceSelect.dataset.selectedReference = String(fields.referencia_id || '');
            }
            if (descriptionInput instanceof HTMLInputElement) {
                descriptionInput.value = String(fields.descricao || '');
            }
            if (quantityInput instanceof HTMLInputElement) {
                quantityInput.value = String(fields.quantidade ?? 1);
            }
            if (unitPriceInput instanceof HTMLInputElement) {
                unitPriceInput.value = formatMoney(fields.valor_unitario ?? 0);
            }
            if ((discountTypeSelect instanceof HTMLInputElement) || (discountTypeSelect instanceof HTMLSelectElement)) {
                discountTypeSelect.value = discountMode;
            }
            if (discountInput instanceof HTMLInputElement) {
                discountInput.value = formatCanonicalNumber(fields.desconto ?? 0, 2);
            }
            if (discountPercentInput instanceof HTMLInputElement) {
                discountPercentInput.value = formatCanonicalNumber(fields.desconto_percentual ?? 0, 4);
            }
            if (discountDisplayInput instanceof HTMLInputElement) {
                discountDisplayInput.value = discountMode === 'percentual'
                    ? formatPercent(fields.desconto_percentual ?? 0)
                    : formatMoney(fields.desconto ?? 0);
            }
            if ((additionTypeSelect instanceof HTMLInputElement) || (additionTypeSelect instanceof HTMLSelectElement)) {
                additionTypeSelect.value = additionMode;
            }
            if (additionInput instanceof HTMLInputElement) {
                additionInput.value = formatCanonicalNumber(fields.acrescimo ?? 0, 2);
            }
            if (additionPercentInput instanceof HTMLInputElement) {
                additionPercentInput.value = formatCanonicalNumber(fields.acrescimo_percentual ?? 0, 4);
            }
            if (additionDisplayInput instanceof HTMLInputElement) {
                additionDisplayInput.value = additionMode === 'percentual'
                    ? formatPercent(fields.acrescimo_percentual ?? 0)
                    : formatMoney(fields.acrescimo ?? 0);
            }
            if (notesInput instanceof HTMLTextAreaElement) {
                notesInput.value = String(fields.observacoes || '');
            }
            if (modeInput instanceof HTMLInputElement) {
                modeInput.value = String(fields.modo_precificacao || 'manual');
            }

            bindRow(row);
            return row;
        };

        const collectState = () => {
            const fields = {};
            const items = [];

            form.querySelectorAll('input, select, textarea').forEach((element) => {
                if (!(element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement)) {
                    return;
                }

                if (!element.name || element.closest('[data-budget-item-row]')) {
                    return;
                }

                // Grupo de múltipla escolha (ex.: formas_pagamento[]): guarda a
                // lista de marcados. Sem isso todos colapsariam na mesma chave e
                // o rascunho perderia a seleção — justamente o dado que esta
                // tela existe para parar de perder.
                if (element.name.endsWith('[]')) {
                    if (element.type !== 'checkbox') {
                        return;
                    }

                    if (!Array.isArray(fields[element.name])) {
                        fields[element.name] = [];
                    }

                    if (element.checked) {
                        fields[element.name].push(element.value);
                    }

                    return;
                }

                if (element.type === 'checkbox') {
                    fields[element.name] = element.checked ? '1' : '0';
                    return;
                }

                fields[element.name] = element.value;
            });

            itemsBody.querySelectorAll('[data-budget-item-row]').forEach((row) => {
                const typeSelect = row.querySelector('[data-budget-item-type]');
                const referenceSelect = row.querySelector('[data-budget-item-reference]');
                const descriptionInput = row.querySelector('[data-budget-item-description]');
                const quantityInput = row.querySelector('[data-budget-item-quantity]');
                const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
                const discountTypeSelect = row.querySelector('[data-budget-item-discount-type]');
                const discountInput = row.querySelector('[data-budget-item-discount]');
                const discountPercentInput = row.querySelector('[data-budget-item-discount-percent]');
                const additionTypeSelect = row.querySelector('[data-budget-item-addition-type]');
                const additionInput = row.querySelector('[data-budget-item-addition]');
                const additionPercentInput = row.querySelector('[data-budget-item-addition-percent]');
                const notesInput = row.querySelector('[data-budget-item-notes]');
                const modeInput = row.querySelector('[data-budget-item-mode]');

                items.push({
                    tipo_item: typeSelect instanceof HTMLSelectElement ? typeSelect.value : 'servico',
                    referencia_id: referenceSelect instanceof HTMLSelectElement ? referenceSelect.value : '',
                    descricao: descriptionInput instanceof HTMLInputElement ? descriptionInput.value : '',
                    quantidade: quantityInput instanceof HTMLInputElement ? quantityInput.value : '',
                    valor_unitario: unitPriceInput instanceof HTMLInputElement ? unitPriceInput.value : '',
                    desconto_tipo: (discountTypeSelect instanceof HTMLInputElement) || (discountTypeSelect instanceof HTMLSelectElement) ? discountTypeSelect.value : 'valor',
                    desconto: discountInput instanceof HTMLInputElement ? discountInput.value : '',
                    desconto_percentual: discountPercentInput instanceof HTMLInputElement ? discountPercentInput.value : '',
                    acrescimo_tipo: (additionTypeSelect instanceof HTMLInputElement) || (additionTypeSelect instanceof HTMLSelectElement) ? additionTypeSelect.value : 'valor',
                    acrescimo: additionInput instanceof HTMLInputElement ? additionInput.value : '',
                    acrescimo_percentual: additionPercentInput instanceof HTMLInputElement ? additionPercentInput.value : '',
                    observacoes: notesInput instanceof HTMLTextAreaElement ? notesInput.value : '',
                    modo_precificacao: modeInput instanceof HTMLInputElement ? modeInput.value : 'manual',
                });
            });

            const selectedClientOption = clientSelect instanceof HTMLSelectElement
                ? clientSelect.selectedOptions[0]
                : null;
            const selectedClient = selectedClientOption instanceof HTMLOptionElement
                && normalizeText(selectedClientOption.value) !== ''
                ? {
                    id: normalizeText(selectedClientOption.value),
                    text: normalizeText(selectedClientOption.textContent),
                    name: normalizeText(selectedClientOption.dataset.clientName),
                    phone: normalizeText(selectedClientOption.dataset.clientPhone),
                    email: normalizeText(selectedClientOption.dataset.clientEmail),
                }
                : null;

            return { fields, items, selectedClient };
        };

        const saveDraft = () => {
            try {
                const state = collectState();
                localStorage.setItem(draftKey, JSON.stringify(state));
            } catch (error) {
                console.error('[OrcamentosForm] Falha ao salvar rascunho.', error);
            }
        };

        const saveDraftDebounced = debounce(saveDraft, 350);

        const restoreState = (state) => {
            if (!state || typeof state !== 'object') {
                return;
            }

            const fields = state.fields || {};
            const items = Array.isArray(state.items) ? state.items : [];
            const restoredClient = state.selectedClient && typeof state.selectedClient === 'object'
                ? state.selectedClient
                : null;
            const restoredClientId = normalizeText(fields.cliente_id || restoredClient?.id || '');

            if (clientSelect instanceof HTMLSelectElement
                && restoredClientId !== ''
                && !Array.from(clientSelect.options).some((option) => option.value === restoredClientId)) {
                const label = normalizeText(restoredClient?.text || restoredClient?.name || `Cliente #${restoredClientId}`);
                const option = new Option(label, restoredClientId, true, true);
                rememberClientOption(option, restoredClient || {});
                clientSelect.appendChild(option);
            }

            Object.entries(fields).forEach(([name, value]) => {
                if (Array.isArray(value)) {
                    form.querySelectorAll(`[name="${CSS.escape(name)}"]`).forEach((element) => {
                        if (!(element instanceof HTMLInputElement) || element.type !== 'checkbox') {
                            return;
                        }

                        const restored = value.includes(element.value);
                        if (element.checked === restored) {
                            return;
                        }

                        element.checked = restored;
                        // Avisa quem depende do grupo (ex.: exibição do
                        // parcelamento e da chave Pix) — restaurar o rascunho
                        // pelo banner acontece depois da sincronização inicial.
                        element.dispatchEvent(new Event('change', { bubbles: true }));
                    });

                    return;
                }

                const field = form.querySelector(`[name="${CSS.escape(name)}"]`);

                if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) {
                    return;
                }

                if (field.type === 'checkbox') {
                    field.checked = value === '1' || value === true;
                    return;
                }

                field.value = String(value ?? '');
            });

            // Guarda os ids crus de OS/equipamento do rascunho para que o
            // recarregamento por cliente (disparado pelo change abaixo) os
            // reaplique quando pertencerem ao cliente restaurado.
            pendingRestoreOrderId = normalizeText(fields.os_id || '');
            pendingRestoreEquipmentId = normalizeText(fields.equipamento_id || '');

            if (clientSelect instanceof HTMLSelectElement && restoredClientId !== '') {
                clientSelect.value = restoredClientId;

                if (typeof window.jQuery !== 'undefined' && window.jQuery.fn && window.jQuery(clientSelect).data('select2')) {
                    window.jQuery(clientSelect).val(restoredClientId).trigger('change');
                }
            }

            itemsBody.innerHTML = '';

            const sourceItems = items.length > 0 ? items : [{}];
            sourceItems.forEach((item, index) => {
                const row = createRow({ ...item, index });
                if (row) {
                    itemsBody.appendChild(row);
                }
            });

            updateSummary();
        };

        const showBanner = () => {
            if (banner instanceof HTMLElement) {
                banner.classList.remove('d-none');
            }
        };

        const hideBanner = () => {
            if (banner instanceof HTMLElement) {
                banner.classList.add('d-none');
            }
        };

        const loadDraft = () => {
            if (state.draftLoaded) {
                return;
            }

            try {
                const raw = localStorage.getItem(draftKey);
                if (!raw) {
                    return;
                }

                const parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object') {
                    return;
                }

                showBanner();

                restoreButton?.addEventListener('click', () => {
                    restoreState(parsed);
                    hideBanner();
                    if (window.Swal) {
                        window.Swal.fire({
                            icon: 'success',
                            title: 'Rascunho restaurado',
                            text: 'Os dados locais foram carregados com sucesso.',
                            timer: 1600,
                            showConfirmButton: false,
                        });
                    }
                });

                discardButton?.addEventListener('click', () => {
                    const confirmDiscard = () => {
                        localStorage.removeItem(draftKey);
                        hideBanner();
                        if (window.Swal) {
                            window.Swal.fire({
                                icon: 'success',
                                title: 'Rascunho descartado',
                                text: 'O estado local deste orçamento foi removido.',
                                timer: 1600,
                                showConfirmButton: false,
                            });
                        }
                    };

                    if (window.Swal) {
                        window.Swal.fire({
                            icon: 'warning',
                            title: 'Descartar rascunho?',
                            text: 'Esta ação remove os dados salvos localmente para este orçamento.',
                            showCancelButton: true,
                            confirmButtonText: 'Descartar',
                            cancelButtonText: 'Cancelar',
                        }).then((result) => {
                            if (result.isConfirmed) {
                                confirmDiscard();
                            }
                        });
                        return;
                    }

                    confirmDiscard();
                });

                state.draftLoaded = true;
            } catch (error) {
                console.error('[OrcamentosForm] Falha ao ler rascunho.', error);
            }
        };

        const switchTab = (name) => {
            tabButtons.forEach((button) => {
                const active = button.dataset.budgetTab === name;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            tabPanels.forEach((panel) => {
                panel.classList.toggle('is-active', panel.dataset.budgetPanel === name);
            });
        };

        tabButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (button.dataset.budgetTab) {
                    switchTab(button.dataset.budgetTab);
                }
            });
        });

        // Trava do botão principal. Na criação, o navegador guia o operador até a
        // primeira pendência; a validação equivalente também existe no controller
        // desktop, portanto alterar o DOM ou enviar POST manual não cria rascunho
        // incompleto no banco.
        const budgetIsEdit = form.dataset.budgetIsEdit === '1';
        const primaryActionButton = form.querySelector('[data-budget-primary-action]');
        const primaryActionIcon = form.querySelector('[data-budget-primary-action-icon]');
        const primaryActionLabel = form.querySelector('[data-budget-primary-action-label]');
        const budgetTabFlags = new Map(
            tabButtons.map((button) => [button.dataset.budgetTab, button.querySelector('[data-budget-tab-flag]')])
        );
        const budgetTabOrder = tabButtons
            .map((button) => normalizeText(button.dataset.budgetTab))
            .filter(Boolean);

        const collectCreatePendencies = () => {
            if (budgetIsEdit) {
                return [];
            }

            const pendencies = [];
            const phoneDigits = normalizeText(phoneInput?.value).replace(/\D/g, '');

            if (!filledSelect(clientSelect) && !filledInput(clientFallbackInput)) {
                pendencies.push({
                    tab: 'cliente',
                    field: clientSelect instanceof HTMLSelectElement && !clientSelect.disabled
                        ? clientSelect
                        : clientFallbackInput,
                    message: 'Selecione um cliente cadastrado ou informe o nome do cliente eventual.',
                });
            }

            if (phoneDigits.length < 10 || phoneDigits.length > 11) {
                pendencies.push({
                    tab: 'cliente',
                    field: phoneInput,
                    message: 'Informe um telefone de contato válido, com DDD.',
                });
            }

            if (emailInput instanceof HTMLInputElement
                && normalizeText(emailInput.value) !== ''
                && !emailInput.validity.valid) {
                pendencies.push({
                    tab: 'cliente',
                    field: emailInput,
                    message: 'Corrija o endereço de e-mail informado.',
                });
            }

            if (envolveCheckbox instanceof HTMLInputElement && envolveCheckbox.checked) {
                const hasOrder = orderSelect instanceof HTMLSelectElement
                    && !orderSelect.disabled
                    && filledSelect(orderSelect);
                const hasRegisteredEquipment = equipmentSelect instanceof HTMLSelectElement
                    && !equipmentSelect.disabled
                    && filledSelect(equipmentSelect);

                if (!hasOrder && !hasRegisteredEquipment) {
                    [
                        ['orcamentoEquipTipoAvulso', 'Informe o tipo do equipamento eventual.'],
                        ['orcamentoEquipMarcaAvulso', 'Informe a marca do equipamento eventual.'],
                        ['orcamentoEquipModeloAvulso', 'Informe o modelo do equipamento eventual.'],
                        ['orcamentoEquipCorAvulso', 'Informe a cor do equipamento eventual.'],
                    ].forEach(([id, message]) => {
                        const field = document.getElementById(id);

                        // Tipo virou Select2 (HTMLSelectElement); os demais seguem inputs.
                        if (!(field instanceof HTMLElement) || !filledControl(field)) {
                            pendencies.push({ tab: 'equipamento', field: field instanceof HTMLElement ? field : null, message });
                        }
                    });
                }
            }

            // Relato do cliente / defeito relatado: obrigatório sempre (inclusive
            // em serviço sem aparelho). O campo fica na aba "Dados do equipamento".
            const relatoField = document.getElementById('orcamentoRelatoCliente');
            if (!filledInput(relatoField)) {
                pendencies.push({
                    tab: 'equipamento',
                    field: relatoField instanceof HTMLElement ? relatoField : null,
                    message: 'Informe o relato do cliente / defeito relatado.',
                });
            }

            // Prazo de execução: obrigatório sempre. O campo fica na aba
            // "Dados operacionais".
            const prazoExecucaoField = document.getElementById('orcamentoPrazoExecucao');
            if (!filledControl(prazoExecucaoField)) {
                pendencies.push({
                    tab: 'operacional',
                    field: prazoExecucaoField instanceof HTMLElement ? prazoExecucaoField : null,
                    message: 'Informe o prazo de execução.',
                });
            }

            const meaningfulRows = Array.from(itemsBody.querySelectorAll('[data-budget-item-row]'))
                .filter((row) => rowHasMeaningfulContent(row));

            if (meaningfulRows.length === 0) {
                const firstRow = itemsBody.querySelector('[data-budget-item-row]');
                pendencies.push({
                    tab: 'financeiro',
                    field: firstRow?.querySelector('[data-budget-item-description]') || addButton,
                    message: 'Adicione ao menos um item ao orçamento.',
                });
            }

            meaningfulRows.forEach((row, index) => {
                const descriptionInput = row.querySelector('[data-budget-item-description]');
                const quantityInput = row.querySelector('[data-budget-item-quantity]');
                const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
                const itemTotalInput = row.querySelector('[data-budget-item-total]');
                const itemNumber = index + 1;

                if (!(descriptionInput instanceof HTMLInputElement) || normalizeText(descriptionInput.value) === '') {
                    pendencies.push({
                        tab: 'financeiro',
                        field: descriptionInput,
                        message: `Informe a descrição do item ${itemNumber}.`,
                    });
                }

                if (!(quantityInput instanceof HTMLInputElement) || toNumber(quantityInput.value) <= 0) {
                    pendencies.push({
                        tab: 'financeiro',
                        field: quantityInput,
                        message: `Informe uma quantidade maior que zero no item ${itemNumber}.`,
                    });
                }

                if (!(unitPriceInput instanceof HTMLInputElement) || toNumber(unitPriceInput.value) <= 0) {
                    pendencies.push({
                        tab: 'financeiro',
                        field: unitPriceInput,
                        message: `Informe um valor unitário maior que zero no item ${itemNumber}.`,
                    });
                } else if (!(itemTotalInput instanceof HTMLInputElement) || toNumber(itemTotalInput.value) <= 0) {
                    pendencies.push({
                        tab: 'financeiro',
                        field: unitPriceInput,
                        message: `Os ajustes do item ${itemNumber} não podem resultar em total igual ou menor que zero.`,
                    });
                }
            });

            if (meaningfulRows.length > 0
                && (!(totalInput instanceof HTMLInputElement) || toNumber(totalInput.value) <= 0)) {
                pendencies.push({
                    tab: 'financeiro',
                    field: totalInput,
                    message: 'O total final do orçamento deve ser maior que zero.',
                });
            }

            return pendencies;
        };

        const focusCreatePendency = (pendency) => {
            if (!pendency || typeof pendency !== 'object') {
                return;
            }

            switchTab(String(pendency.tab || 'cliente'));
            showToast('warning', String(pendency.message || 'Preencha os campos obrigatórios.'));

            window.requestAnimationFrame(() => {
                const field = pendency.field;
                const focusTarget = field instanceof HTMLElement
                    ? (field.closest('[data-budget-item-row]') || field)
                    : null;

                focusTarget?.scrollIntoView({ behavior: 'smooth', block: 'center' });

                if (field instanceof HTMLSelectElement
                    && !field.disabled
                    && typeof window.jQuery !== 'undefined'
                    && window.jQuery.fn
                    && Boolean(window.jQuery(field).data('select2'))) {
                    window.jQuery(field).select2('open');
                    return;
                }

                if (field instanceof HTMLElement && !field.hasAttribute('disabled')) {
                    field.focus({ preventScroll: true });
                }
            });
        };

        const syncPrimaryAction = () => {
            if (budgetIsEdit
                || !(primaryActionButton instanceof HTMLButtonElement)
                || !(primaryActionLabel instanceof HTMLElement)) {
                return;
            }

            const pendencies = collectCreatePendencies();

            budgetTabFlags.forEach((flag, name) => {
                if (flag instanceof HTMLElement) {
                    flag.hidden = !pendencies.some((pendency) => pendency.tab === name);
                }
            });

            const firstPendency = pendencies[0] || null;
            const ready = firstPendency === null;
            const nextLabel = normalizeText(primaryActionButton.dataset.budgetNextLabel) || 'Próximo';
            const submitLabel = normalizeText(primaryActionButton.dataset.budgetSubmitLabel) || 'Criar orçamento';

            primaryActionLabel.textContent = ready ? submitLabel : nextLabel;
            primaryActionButton.dataset.budgetReady = ready ? '1' : '0';
            primaryActionButton.setAttribute(
                'aria-label',
                ready ? submitLabel : `${nextLabel}: ${firstPendency.message}`
            );

            if (primaryActionIcon instanceof HTMLElement) {
                primaryActionIcon.classList.toggle('bi-arrow-right-circle', !ready);
                primaryActionIcon.classList.toggle('bi-check2-circle', ready);
            }
        };

        const handlePrimaryActionClick = (event) => {
            if (budgetIsEdit) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            try {
                const pendencies = collectCreatePendencies();
                const firstPendency = pendencies[0] || null;
                const activeTabButton = tabButtons.find((button) => button.classList.contains('is-active'));
                const currentTabName = normalizeText(activeTabButton?.dataset.budgetTab);
                const currentTabPendency = pendencies.find((pendency) => pendency.tab === currentTabName);

                if (currentTabPendency) {
                    focusCreatePendency(currentTabPendency);
                    return;
                }

                if (!firstPendency) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                        return;
                    }

                    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    return;
                }

                const currentTabIndex = budgetTabOrder.indexOf(currentTabName);
                if (currentTabIndex >= 0 && currentTabIndex < budgetTabOrder.length - 1) {
                    switchTab(budgetTabOrder[currentTabIndex + 1]);
                    return;
                }

                focusCreatePendency(firstPendency);
            } catch (error) {
                if (window.DesktopUi && typeof window.DesktopUi.logError === 'function') {
                    window.DesktopUi.logError('orcamentos-form.primary-action', error);
                } else {
                    console.error('[OrcamentosForm] Falha ao avançar o formulário.', error);
                }

                showAlert(
                    'error',
                    'Não foi possível avançar',
                    'Recarregue a página e tente novamente. Se o problema persistir, informe o suporte.'
                );
            }
        };

        primaryActionButton?.addEventListener('click', handlePrimaryActionClick, { capture: true });

        const formatDateInput = (date) => {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };

        const syncValidityDate = () => {
            if (!(validityDaysSelect instanceof HTMLSelectElement) || !(validityDateInput instanceof HTMLInputElement)) {
                return;
            }

            const days = Number.parseInt(validityDaysSelect.value, 10);
            if (!Number.isFinite(days)) {
                return;
            }

            const dueDate = new Date();
            dueDate.setDate(dueDate.getDate() + days);
            validityDateInput.value = formatDateInput(dueDate);
        };

        validityDaysSelect?.addEventListener('change', syncValidityDate);

        if (validityDateInput instanceof HTMLInputElement && validityDateInput.value === '') {
            syncValidityDate();
        }

        addButton?.addEventListener('click', () => {
            const row = createRow({});
            if (!row) {
                return;
            }

            itemsBody.appendChild(row);
            updateSummary();
        });

        itemsBody.querySelectorAll('[data-budget-item-row]').forEach((row) => bindRow(row));

        bindAdjustmentControl(getGlobalDiscountControl(), () => toNumber(subtotalInput?.value), updateSummary);
        bindAdjustmentControl(getGlobalAdditionControl(), () => toNumber(subtotalInput?.value), updateSummary);

        form.addEventListener('input', () => updateSummary());
        form.addEventListener('change', () => updateSummary());
        form.addEventListener('submit', (event) => {
            if (!budgetIsEdit) {
                const firstPendency = collectCreatePendencies()[0] || null;

                if (firstPendency) {
                    event.preventDefault();
                    state.reviewConfirmed = false;
                    focusCreatePendency(firstPendency);
                    return;
                }
            }

            if (state.reviewConfirmed) {
                if (budgetIsEncerrada && !state.adminConfirmed) {
                    event.preventDefault();
                    if (adminConfirmModalElement instanceof HTMLElement) {
                        getModal(adminConfirmModalElement)?.show();
                    }
                    return;
                }

                removeEmptyRows();
                updateSummary();
                // Reabilita os campos de exclusividade (já com valor limpo quando
                // inativos) para que postem e sobrescrevam o registro na edição.
                enableManagedControlsForSubmit();

                try {
                    localStorage.removeItem(draftKey);
                } catch (error) {
                    console.error('[OrcamentosForm] Falha ao limpar rascunho antes do envio.', error);
                }

                return;
            }

            if (!(reviewModalElement instanceof HTMLElement)) {
                return;
            }

            event.preventDefault();
            renderReviewModal();
            getModal(reviewModalElement)?.show();
        });

        adminConfirmSubmitButton?.addEventListener('click', () => {
            const email = (adminEmailInput?.value || '').trim();
            const password = adminPasswordInput?.value || '';

            if (email === '' || password === '') {
                if (adminConfirmError instanceof HTMLElement) {
                    adminConfirmError.textContent = 'Informe e-mail e senha do administrador.';
                    adminConfirmError.classList.remove('d-none');
                }
                return;
            }

            if (adminEmailHidden instanceof HTMLInputElement) {
                adminEmailHidden.value = email;
            }
            if (adminPasswordHidden instanceof HTMLInputElement) {
                adminPasswordHidden.value = password;
            }

            state.adminConfirmed = true;
            getModal(adminConfirmModalElement)?.hide();

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        });

        adminConfirmModalElement?.addEventListener('hidden.bs.modal', () => {
            if (adminConfirmError instanceof HTMLElement) {
                adminConfirmError.classList.add('d-none');
                adminConfirmError.textContent = '';
            }
            if (adminPasswordInput instanceof HTMLInputElement) {
                adminPasswordInput.value = '';
            }
        });

        reviewSubmitButtons.forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            button.addEventListener('click', () => {
                const mode = button.dataset.budgetReviewSubmit === 'send_for_approval'
                    ? 'send_for_approval'
                    : 'save_only';

                if (button.disabled) {
                    showAlert('warning', 'Existem pendências', 'Resolva os campos obrigatórios antes de concluir o orçamento.');
                    return;
                }

                if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    getModal(reviewModalElement)?.hide();
                    form.reportValidity?.();
                    return;
                }

                if (submissionModeInput instanceof HTMLInputElement) {
                    submissionModeInput.value = mode;
                }

                state.reviewConfirmed = true;
                getModal(reviewModalElement)?.hide();

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }

                form.submit();
            });
        });

        reviewModalElement?.addEventListener('hidden.bs.modal', () => {
            if (!state.reviewConfirmed && submissionModeInput instanceof HTMLInputElement) {
                submissionModeInput.value = 'save_only';
            }
        });

        if (quickItemType instanceof HTMLSelectElement) {
            quickItemType.addEventListener('change', () => updateQuickItemMode(quickItemType.value));
        }

        if (quickItemForm instanceof HTMLFormElement) {
            quickItemForm.addEventListener('submit', handleQuickItemSubmit);
        }

        quickItemSubmit?.addEventListener('click', () => {
            if (!(quickItemForm instanceof HTMLFormElement)) {
                return;
            }

            if (typeof quickItemForm.requestSubmit === 'function') {
                quickItemForm.requestSubmit();
                return;
            }

            quickItemForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });

        quickItemModal?.addEventListener('hidden.bs.modal', () => {
            state.quickItemRow = null;
            state.quickItemType = 'servico';
            resetQuickItemForm();
            updateQuickItemMode(getResolvedQuickType('servico'));
        });

        // Uma OS sempre está vinculada a um único equipamento cadastrado — ao
        // escolher a OS, pré-seleciona esse equipamento automaticamente em
        // "Equipamento cadastrado", para o técnico não precisar escolher o
        // mesmo aparelho duas vezes.
        const applyOrderLinkedEquipment = () => {
            if (!(orderSelect instanceof HTMLSelectElement) || !(equipmentSelect instanceof HTMLSelectElement)) {
                return;
            }

            const selectedOption = orderSelect.selectedOptions[0];
            const linkedEquipmentId = selectedOption instanceof HTMLOptionElement
                ? normalizeText(selectedOption.dataset.equipamentoId)
                : '';

            if (linkedEquipmentId === '' || linkedEquipmentId === '0' || equipmentSelect.value === linkedEquipmentId) {
                return;
            }

            const hasMatchingOption = Array.from(equipmentSelect.options)
                .some((option) => option.value === linkedEquipmentId);

            if (!hasMatchingOption) {
                return;
            }

            equipmentSelect.value = linkedEquipmentId;

            const $ = jq();
            if ($ && Boolean($(equipmentSelect).data('select2'))) {
                $(equipmentSelect).val(linkedEquipmentId).trigger('change');
            }
        };

        // Uma OS tem um único relato do cliente / defeito relatado registrado —
        // ao escolher a OS, preenche esse texto em "Relato do cliente / defeito
        // relatado" para o técnico não digitar de novo o que já está na OS.
        const applyOrderLinkedRelato = () => {
            if (!(orderSelect instanceof HTMLSelectElement)) {
                return;
            }

            const relatoField = document.getElementById('orcamentoRelatoCliente');
            if (!(relatoField instanceof HTMLTextAreaElement)) {
                return;
            }

            const selectedOption = orderSelect.selectedOptions[0];
            const linkedRelato = selectedOption instanceof HTMLOptionElement
                ? normalizeText(selectedOption.dataset.relatoCliente)
                : '';

            if (linkedRelato === '' || relatoField.value === linkedRelato) {
                return;
            }

            relatoField.value = linkedRelato;
        };

        // syncEquipmentMode() já chama syncDerivedClassification() ao final.
        const handleOrderChange = () => {
            applyOrderLinkedEquipment();
            applyOrderLinkedRelato();
            syncEquipmentMode();
        };
        onSelectEvent(orderSelect, 'change', handleOrderChange);
        onSelectEvent(orderSelect, 'select2:select', handleOrderChange);
        onSelectEvent(orderSelect, 'select2:clear', handleOrderChange);
        onSelectEvent(clientSelect, 'change', syncEventualFields);
        onSelectEvent(clientSelect, 'select2:select', syncEventualFields);
        onSelectEvent(clientSelect, 'select2:clear', syncEventualFields);
        // Trocar o cliente recarrega as OS abertas e os equipamentos daquele
        // cliente (filtra "OS vinculada" e "Equipamento cadastrado").
        onSelectEvent(clientSelect, 'change', handleClientChange);
        onSelectEvent(clientSelect, 'select2:select', handleClientChange);
        onSelectEvent(clientSelect, 'select2:clear', handleClientChange);
        onSelectEvent(equipmentSelect, 'change', syncEquipmentMode);
        onSelectEvent(equipmentSelect, 'select2:select', syncEquipmentMode);
        onSelectEvent(equipmentSelect, 'select2:clear', syncEquipmentMode);
        clientFallbackInput?.addEventListener('input', syncEventualFields);
        envolveCheckbox?.addEventListener('change', syncEquipmentMode);
        eventualInputs.forEach((input) => input.addEventListener('input', syncEquipmentMode));
        // O Select2 do "Tipo" dispara 'change' só via jQuery — não cai no listener
        // nativo de 'input' do form. Ligamos explicitamente para reavaliar as
        // pendências (rótulo do botão) e a exclusividade registrado × eventual.
        const handleEquipTypeChange = () => {
            syncEquipmentMode();
            updateSummary();
        };
        onSelectEvent(equipTypeSelect, 'change', handleEquipTypeChange);
        onSelectEvent(equipTypeSelect, 'select2:select', handleEquipTypeChange);
        onSelectEvent(equipTypeSelect, 'select2:clear', handleEquipTypeChange);

        initClientSelect();
        initEquipmentSelect();
        initEquipmentTypeSelect(equipTypeSelect);
        initClientDependentFields();
        loadDraft();
        syncEventualFields();
        updateSummary();
        // Reassere o estado do botao principal ("Proximo" x "Salvar orcamento")
        // depois que a fila de tarefas drena — cobre o caso de o Select2 do cliente
        // (initClientSelect) so terminar de montar num tick posterior e disparar
        // eventos que so entao refletem o valor real dos campos.
        syncPrimaryAction();

        window.setTimeout(() => {
            syncPrimaryAction();
            if (!isEditMode && banner instanceof HTMLElement && !banner.classList.contains('d-none')) {
                return;
            }
        }, 0);
    });

    // Condições comerciais: parcelamento só existe acompanhando um cartão
    // parcelável, e as chaves Pix só aparecem quando o Pix é aceito. Bloco
    // independente do resto do formulário — não depende do estado dos itens.
    document.addEventListener('DOMContentLoaded', () => {
        const terms = document.querySelector('[data-budget-terms]');

        if (!(terms instanceof HTMLElement)) {
            return;
        }

        const checkboxes = Array.from(terms.querySelectorAll('[data-budget-payment-method]'));
        const installmentsWrapper = terms.querySelector('[data-budget-installments-wrapper]');
        const installmentsSelect = terms.querySelector('[data-budget-installments]');
        const pixPreview = terms.querySelector('[data-budget-pix-preview]');

        const isChecked = (attribute) => checkboxes.some(
            (input) => input.checked && input.getAttribute(attribute) === '1'
        );

        const syncTerms = () => {
            const allowsInstallments = isChecked('data-installments');

            if (installmentsWrapper instanceof HTMLElement) {
                installmentsWrapper.classList.toggle('d-none', !allowsInstallments);
            }

            // Sem cartão parcelável não faz sentido mandar parcelas: zera para o
            // backend não receber um número órfão.
            if (!allowsInstallments && installmentsSelect instanceof HTMLSelectElement) {
                installmentsSelect.value = '';
            }

            if (pixPreview instanceof HTMLElement) {
                pixPreview.classList.toggle('d-none', !isChecked('data-pix'));
            }
        };

        checkboxes.forEach((input) => input.addEventListener('change', syncTerms));
        syncTerms();
    });
})();
