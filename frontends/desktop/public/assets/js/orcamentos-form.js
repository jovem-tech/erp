(function () {
    const config = window.__DESKTOP_ORCAMENTO_FORM || {};
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const catalogs = {
        services: Array.isArray(config.catalogs?.services) ? config.catalogs.services : [],
        parts: Array.isArray(config.catalogs?.parts) ? config.catalogs.parts : [],
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

        if (!(form instanceof HTMLFormElement) || !(itemsBody instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
            return;
        }

        const tabButtons = Array.from(document.querySelectorAll('[data-budget-tab]'));
        const tabPanels = Array.from(document.querySelectorAll('[data-budget-panel]'));
        const state = {
            draftLoaded: false,
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
            updateRowFromReference(row);
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
            const removeButton = row.querySelector('[data-budget-item-remove]');

            typeSelect?.addEventListener('change', () => {
                if (referenceSelect instanceof HTMLSelectElement) {
                    referenceSelect.dataset.selectedReference = '';
                    referenceSelect.value = '';
                }

                populateReferenceSelect(row, false);
                updateSummary();
            });

            referenceSelect?.addEventListener('change', () => {
                if (referenceSelect instanceof HTMLSelectElement) {
                    referenceSelect.dataset.selectedReference = referenceSelect.value;
                }

                updateRowFromReference(row);
                updateSummary();
            });

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

        loadDraft();
        updateSummary();

        window.setTimeout(() => {
            if (!isEditMode && banner instanceof HTMLElement && !banner.classList.contains('d-none')) {
                return;
            }
        }, 0);
    });
})();
