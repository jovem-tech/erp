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
            const hasOrder = orderSelect instanceof HTMLSelectElement
                ? String(orderSelect.value || '').trim() !== ''
                : false;
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
                    label: 'Condicoes comerciais',
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

                const requiresReadyState = button.dataset.budgetReviewSubmit === 'send_for_approval';
                button.disabled = requiresReadyState && pendencies.length > 0;
                button.title = button.disabled ? 'Resolva as pendencias antes de enviar para aprovacao.' : '';
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

            return { fields, items };
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

            Object.entries(fields).forEach(([name, value]) => {
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
                    showAlert('warning', 'Existem pendencias', 'Resolva as pendencias destacadas antes de enviar para aprovacao.');
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

        onSelectEvent(orderSelect, 'change', syncDerivedClassification);
        onSelectEvent(orderSelect, 'select2:select', syncDerivedClassification);
        onSelectEvent(orderSelect, 'select2:clear', syncDerivedClassification);

        loadDraft();
        syncDerivedClassification();
        updateSummary();

        window.setTimeout(() => {
            if (!isEditMode && banner instanceof HTMLElement && !banner.classList.contains('d-none')) {
                return;
            }
        }, 0);
    });
})();
