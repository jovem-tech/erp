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

    const toNumber = (value) => {
        const normalized = String(value ?? '')
            .replaceAll('R$', '')
            .replaceAll('.', '')
            .replaceAll(',', '.')
            .replace(/[^\d.-]/g, '');

        const parsed = Number.parseFloat(normalized);

        return Number.isFinite(parsed) ? parsed : 0;
    };

    const formatNumber = (value) => Number(value || 0).toFixed(2);

    const normalizeText = (value) => String(value ?? '').trim();

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

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
        const discountInput = document.querySelector('[data-budget-global-discount]');
        const additionInput = document.querySelector('[data-budget-global-addition]');
        const totalInput = document.querySelector('[data-budget-total]');
        const banner = document.querySelector('[data-budget-draft-banner]');
        const restoreButton = document.querySelector('[data-budget-draft-restore]');
        const discardButton = document.querySelector('[data-budget-draft-discard]');
        const itemsCount = document.querySelector('[data-budget-items-count]');
        const validityDaysSelect = document.querySelector('[data-budget-validity-days]');
        const validityDateInput = document.querySelector('[data-budget-validity-date]');
        const quickItemModal = document.getElementById('orcamentoQuickItemModal');
        const quickItemForm = document.getElementById('orcamentoQuickItemForm');
        const quickItemSubmit = document.getElementById('orcamentoQuickItemSubmit');
        const quickItemType = document.getElementById('orcamentoQuickItemType');
        const quickItemTitle = document.querySelector('[data-budget-quick-title]');
        const quickItemNote = document.querySelector('[data-budget-quick-note]');
        const quickItemErrors = document.getElementById('orcamentoQuickItemErrors');
        const quickItemNameLabel = document.querySelector('[data-budget-quick-name-label]');
        const quickItemServiceGroup = document.querySelector('[data-budget-quick-group="servico"]');
        const quickItemPartGroup = document.querySelector('[data-budget-quick-group="peca"]');

        if (!(form instanceof HTMLFormElement) || !(itemsBody instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
            return;
        }

        const tabButtons = Array.from(document.querySelectorAll('[data-budget-tab]'));
        const tabPanels = Array.from(document.querySelectorAll('[data-budget-panel]'));
        const state = {
            draftLoaded: false,
            quickItemRow: null,
            quickItemType: 'servico',
            quickItemSubmitting: false,
        };

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

            const equipmentTypeField = quickItemForm.querySelector('[name="tipo_equipamento"]');
            if (equipmentTypeField instanceof HTMLInputElement) {
                equipmentTypeField.value = '';
            }

            if ((type === 'peca' || quickItemType?.value === 'peca') && quickItemForm instanceof HTMLFormElement) {
                const salePriceField = quickItemForm.querySelector('[name="preco_venda"]');
                if (salePriceField instanceof HTMLInputElement) {
                    salePriceField.value = formatNumber(toNumber(preferredPrice));
                }
            }

            if ((type === 'servico' || quickItemType?.value === 'servico') && quickItemForm instanceof HTMLFormElement) {
                const serviceValueField = quickItemForm.querySelector('[name="valor"]');
                if (serviceValueField instanceof HTMLInputElement) {
                    serviceValueField.value = formatNumber(toNumber(preferredPrice));
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

        const updateRowTotal = (row) => {
            const quantityInput = row.querySelector('[data-budget-item-quantity]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
            const discountInput = row.querySelector('[data-budget-item-discount]');
            const additionInput = row.querySelector('[data-budget-item-addition]');
            const totalInput = row.querySelector('[data-budget-item-total]');

            if (!(quantityInput instanceof HTMLInputElement) || !(unitPriceInput instanceof HTMLInputElement) || !(discountInput instanceof HTMLInputElement) || !(additionInput instanceof HTMLInputElement) || !(totalInput instanceof HTMLInputElement)) {
                return 0;
            }

            const quantity = toNumber(quantityInput.value);
            const unitPrice = toNumber(unitPriceInput.value);
            const discount = toNumber(discountInput.value);
            const addition = toNumber(additionInput.value);

            const total = (quantity * unitPrice) - discount + addition;
            totalInput.value = formatNumber(total);

            return total;
        };

        const updateSummary = () => {
            let subtotal = 0;

            itemsBody.querySelectorAll('[data-budget-item-row]').forEach((row) => {
                subtotal += updateRowTotal(row);
            });

            if (subtotalInput instanceof HTMLInputElement) {
                subtotalInput.value = formatNumber(subtotal);
            }

            const discount = discountInput instanceof HTMLInputElement ? toNumber(discountInput.value) : 0;
            const addition = additionInput instanceof HTMLInputElement ? toNumber(additionInput.value) : 0;
            const total = subtotal - discount + addition;

            if (totalInput instanceof HTMLInputElement) {
                totalInput.value = formatNumber(total);
            }

            updateItemsCount();
            saveDraftDebounced();
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

                unitPriceInput.value = formatNumber(toNumber(price));
            }

            updateRowTotal(row);
        };

        const bindRow = (row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }

            const typeSelect = row.querySelector('[data-budget-item-type]');
            const referenceSelect = row.querySelector('[data-budget-item-reference]');
            const quantityInput = row.querySelector('[data-budget-item-quantity]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
            const discountField = row.querySelector('[data-budget-item-discount]');
            const additionField = row.querySelector('[data-budget-item-addition]');
            const quickCreateButton = row.querySelector('[data-budget-item-quick-create]');
            const removeButton = row.querySelector('[data-budget-item-remove]');

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

            [quantityInput, unitPriceInput, discountField, additionField].forEach((input) => {
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
                acrescimo: 0,
                observacoes: '',
                modo_precificacao: 'manual',
                ...data,
            };

            const typeSelect = row.querySelector('[data-budget-item-type]');
            const referenceSelect = row.querySelector('[data-budget-item-reference]');
            const descriptionInput = row.querySelector('[data-budget-item-description]');
            const quantityInput = row.querySelector('[data-budget-item-quantity]');
            const unitPriceInput = row.querySelector('[data-budget-item-unit-price]');
            const discountInput = row.querySelector('[data-budget-item-discount]');
            const additionInput = row.querySelector('[data-budget-item-addition]');
            const notesInput = row.querySelector('[data-budget-item-notes]');
            const modeInput = row.querySelector('[data-budget-item-mode]');

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
                unitPriceInput.value = formatNumber(fields.valor_unitario ?? 0);
            }
            if (discountInput instanceof HTMLInputElement) {
                discountInput.value = formatNumber(fields.desconto ?? 0);
            }
            if (additionInput instanceof HTMLInputElement) {
                additionInput.value = formatNumber(fields.acrescimo ?? 0);
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
                const discountInput = row.querySelector('[data-budget-item-discount]');
                const additionInput = row.querySelector('[data-budget-item-addition]');
                const notesInput = row.querySelector('[data-budget-item-notes]');
                const modeInput = row.querySelector('[data-budget-item-mode]');

                items.push({
                    tipo_item: typeSelect instanceof HTMLSelectElement ? typeSelect.value : 'servico',
                    referencia_id: referenceSelect instanceof HTMLSelectElement ? referenceSelect.value : '',
                    descricao: descriptionInput instanceof HTMLInputElement ? descriptionInput.value : '',
                    quantidade: quantityInput instanceof HTMLInputElement ? quantityInput.value : '',
                    valor_unitario: unitPriceInput instanceof HTMLInputElement ? unitPriceInput.value : '',
                    desconto: discountInput instanceof HTMLInputElement ? discountInput.value : '',
                    acrescimo: additionInput instanceof HTMLInputElement ? additionInput.value : '',
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

        form.addEventListener('input', () => updateSummary());
        form.addEventListener('change', () => updateSummary());
        form.addEventListener('submit', () => {
            itemsBody.querySelectorAll('[data-budget-item-row]').forEach((row) => {
                const description = row.querySelector('[data-budget-item-description]');
                const reference = row.querySelector('[data-budget-item-reference]');
                const quantity = row.querySelector('[data-budget-item-quantity]');
                const unitPrice = row.querySelector('[data-budget-item-unit-price]');

                if (description instanceof HTMLInputElement && reference instanceof HTMLSelectElement && quantity instanceof HTMLInputElement && unitPrice instanceof HTMLInputElement) {
                    const hasContent = description.value.trim() !== '' || reference.value.trim() !== '' || toNumber(quantity.value) > 0 || toNumber(unitPrice.value) > 0;

                    if (!hasContent && itemsBody.querySelectorAll('[data-budget-item-row]').length > 1) {
                        row.remove();
                    }
                }
            });

            updateSummary();
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

        loadDraft();
        updateSummary();

        window.setTimeout(() => {
            if (!isEditMode && banner instanceof HTMLElement && !banner.classList.contains('d-none')) {
                return;
            }
        }, 0);
    });
})();
