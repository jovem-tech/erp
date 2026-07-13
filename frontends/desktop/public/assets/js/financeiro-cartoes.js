(function () {
    const config = window.__FINANCEIRO_CARTOES || {};
    const tabs = Array.from(document.querySelectorAll('[data-cartoes-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-cartoes-panel]'));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const simulatorForm = document.querySelector('[data-financeiro-cartoes-simulator]');
    const simulatorStatus = document.querySelector('[data-financeiro-cartoes-simulator-status]');
    const simulatorResults = document.querySelector('[data-financeiro-cartoes-simulation-results]');
    const gatewayProviderSelect = document.getElementById('gatewayProvider');
    const gatewayModeSelect = document.getElementById('gatewayMode');

    const formatMoney = (value) => {
        const number = Number(value ?? 0);
        return `R$ ${number.toFixed(2).replace('.', ',')}`;
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const refreshSelect2 = (container) => {
        if (window.DesktopUi && typeof window.DesktopUi.refreshSelect2 === 'function') {
            window.DesktopUi.refreshSelect2(container instanceof HTMLElement ? container : document);
        }
    };

    const getForm = (name) => document.querySelector(`[data-cartoes-form="${name}"]`);

    const setActiveTab = (tabName, updateUrl = true) => {
        tabs.forEach((tab) => {
            const active = tab.getAttribute('data-cartoes-tab') === tabName;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        panels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.getAttribute('data-cartoes-panel') === tabName);
        });

        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
        }

        const panel = panels.find((item) => item.getAttribute('data-cartoes-panel') === tabName);
        refreshSelect2(panel || document);

        if (tabName === 'gateway') {
            syncGatewayModes();
        }
    };

    const clearForm = (formName) => {
        const form = getForm(formName);
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        form.reset();

        const idField = form.querySelector('[data-cartoes-field="id"]');
        if (idField instanceof HTMLInputElement) {
            idField.value = '';
        }

        form.querySelectorAll('[data-cartoes-field]').forEach((field) => {
            if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement)) {
                return;
            }

            if (field.type === 'checkbox') {
                field.checked = true;
                return;
            }

            if (field.tagName === 'SELECT') {
                field.value = '';
                field.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }

            if (field.type !== 'hidden') {
                field.value = '';
            }
        });

        if (formName === 'gateway') {
            syncGatewayModes();
        }

        refreshSelect2(form);
        form.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
    };

    const fillForm = (formName, values) => {
        const form = getForm(formName);
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        Object.entries(values).forEach(([fieldName, rawValue]) => {
            const field = form.querySelector(`[data-cartoes-field="${fieldName}"]`);

            if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement)) {
                return;
            }

            if (field.type === 'checkbox') {
                field.checked = rawValue === true || rawValue === 1 || rawValue === '1' || rawValue === 'true';
                return;
            }

            field.value = rawValue ?? '';

            if (field.tagName === 'SELECT') {
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        refreshSelect2(form);

        // Os forms de taxa e taxa online vivem dentro de modais (ficam
        // display:none até serem abertos) — rolar até eles não faz sentido e
        // o scrollIntoView de um elemento oculto é um no-op silencioso mesmo.
        // Os outros forms (operadora/bandeira) continuam inline na página.
        if (!MODAL_FORMS.includes(formName)) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    const MODAL_FORMS = ['taxa', 'gateway'];

    const modals = {
        taxa: {
            el: document.getElementById('cartaoTaxaModal'),
            title: document.querySelector('[data-cartoes-taxa-modal-title]'),
        },
        gateway: {
            el: document.getElementById('cartaoGatewayModal'),
            title: document.querySelector('[data-cartoes-gateway-modal-title]'),
        },
    };

    const openFormModal = (formName, title) => {
        const modal = modals[formName];
        if (!modal) {
            return;
        }

        if (modal.title instanceof HTMLElement) {
            modal.title.textContent = title;
        }

        if (modal.el instanceof HTMLElement && window.bootstrap?.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal.el).show();
        }
    };

    const syncGatewayModes = () => {
        if (!(gatewayProviderSelect instanceof HTMLSelectElement) || !(gatewayModeSelect instanceof HTMLSelectElement)) {
            return;
        }

        const provider = gatewayProviderSelect.value || Object.keys(config.gatewayCatalog || {})[0] || '';
        const selectedMode = gatewayModeSelect.value || '';
        const providerData = config.gatewayCatalog?.[provider] || {};
        const modes = Array.isArray(providerData.modes) ? providerData.modes : [];

        gatewayModeSelect.innerHTML = [
            '<option value=""></option>',
            ...modes.map((mode) => {
                const code = String(mode?.code ?? '');
                const label = String(mode?.label ?? code);
                return `<option value="${escapeHtml(code)}">${escapeHtml(label)}</option>`;
            }),
        ].join('');

        const validMode = modes.some((mode) => String(mode?.code ?? '') === selectedMode) ? selectedMode : '';
        gatewayModeSelect.value = validMode;

        refreshSelect2(gatewayModeSelect.closest('form') || gatewayModeSelect);
    };

    const setRowFilterState = (buttons, activeButton) => {
        buttons.forEach((button) => {
            const isActive = button === activeButton;
            button.classList.toggle('btn-primary', isActive);
            button.classList.toggle('btn-outline-light', !isActive);
        });
    };

    const filterRows = (rows, attrName, value) => {
        rows.forEach((row) => {
            const rowValue = row.getAttribute(attrName) || '';
            const visible = value === 'all' || rowValue === value;
            row.hidden = !visible;
        });
    };

    const bindRowFilters = () => {
        const taxaButtons = Array.from(document.querySelectorAll('[data-cartoes-taxa-filter]'));
        const taxaRows = Array.from(document.querySelectorAll('[data-cartoes-row="taxa"]'));
        const gatewayButtons = Array.from(document.querySelectorAll('[data-cartoes-gateway-filter]'));
        const gatewayRows = Array.from(document.querySelectorAll('[data-cartoes-row="gateway"]'));

        if (taxaButtons.length > 0) {
            taxaButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const value = button.getAttribute('data-cartoes-taxa-filter') || 'all';
                    filterRows(taxaRows, 'data-operadora-id', value);
                    setRowFilterState(taxaButtons, button);
                });
            });

            const initialButton = taxaButtons.find((button) => (button.getAttribute('data-cartoes-taxa-filter') || 'all') === 'all') || taxaButtons[0];
            if (initialButton) {
                filterRows(taxaRows, 'data-operadora-id', 'all');
                setRowFilterState(taxaButtons, initialButton);
            }
        }

        if (gatewayButtons.length > 0) {
            gatewayButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const value = button.getAttribute('data-cartoes-gateway-filter') || 'all';
                    filterRows(gatewayRows, 'data-provider', value);
                    setRowFilterState(gatewayButtons, button);
                });
            });

            const initialButton = gatewayButtons.find((button) => (button.getAttribute('data-cartoes-gateway-filter') || 'all') === 'all') || gatewayButtons[0];
            if (initialButton) {
                filterRows(gatewayRows, 'data-provider', 'all');
                setRowFilterState(gatewayButtons, initialButton);
            }
        }
    };

    const renderSimulation = (simulation) => {
        if (!(simulatorResults instanceof HTMLElement)) {
            return;
        }

        const fee = Number(simulation?.valor_taxa ?? 0);
        const net = Number(simulation?.valor_liquido ?? 0);
        const percent = Number(simulation?.taxa_percentual ?? 0);
        const due = simulation?.data_prevista_recebimento || simulation?.data_prevista_repasse || '-';
        const operadora = simulation?.operadora_nome || '-';
        const bandeira = simulation?.bandeira_nome || 'Todas';
        const modalidade = simulation?.modalidade_label || '-';
        const parcelas = Number(simulation?.parcelas ?? 0);
        const prazo = Number(simulation?.prazo_recebimento_dias ?? 0);

        const fields = {
            fee: formatMoney(fee),
            net: formatMoney(net),
            percent: `${percent.toFixed(4).replace('.', ',')}%`,
            due,
        };

        Object.entries(fields).forEach(([key, value]) => {
            const field = simulatorResults.querySelector(`[data-sim-field="${key}"]`);
            if (field instanceof HTMLElement) {
                field.textContent = value;
            }
        });

        const meta = {
            fee: `${operadora} · ${modalidade}`,
            net: `${bandeira} · ${Number.isFinite(parcelas) && parcelas > 0 ? `${parcelas}x` : 'À vista'}`,
            percent: `Prazo de ${prazo} dias`,
            due: simulation?.summary || 'Simulação concluída com a taxa ativa mais compatível.',
        };

        Object.entries(meta).forEach(([key, value]) => {
            const field = simulatorResults.querySelector(`[data-sim-meta="${key}"]`);
            if (field instanceof HTMLElement) {
                field.textContent = value;
            }
        });

        if (simulatorStatus instanceof HTMLElement) {
            simulatorStatus.textContent = `Operadora ${operadora}, modalidade ${modalidade}, bandeira ${bandeira}.`;
        }
    };

    const renderSimulationError = (message) => {
        if (simulatorStatus instanceof HTMLElement) {
            simulatorStatus.textContent = message;
        }
    };

    const bindSimulator = () => {
        if (!(simulatorForm instanceof HTMLFormElement)) {
            return;
        }

        simulatorForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!(simulatorStatus instanceof HTMLElement)) {
                return;
            }

            simulatorStatus.textContent = 'Simulando recebimento...';

            const formData = new FormData(simulatorForm);

            try {
                const response = await fetch(config.routes?.simulate || simulatorForm.getAttribute('action') || '', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: formData,
                    credentials: 'same-origin',
                });

                if (response.status === 401) {
                    window.location.href = '/login';
                    return;
                }

                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload?.success === false) {
                    const message = payload?.message || 'Não foi possível simular o recebimento agora.';
                    renderSimulationError(message);

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Simulação indisponível',
                            text: message,
                        });
                    }

                    return;
                }

                renderSimulation(payload?.simulation || payload?.data?.simulation || {});

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Simulação concluída',
                        text: 'A taxa e o valor líquido foram atualizados.',
                        timer: 1800,
                        showConfirmButton: false,
                    });
                }
            } catch (error) {
                console.error('[Desktop][FinanceiroCartoes][Simulator]', error);
                renderSimulationError('Não foi possível simular o recebimento agora.');

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Erro de simulação',
                        text: 'Não foi possível simular o recebimento agora.',
                    });
                }
            }
        });
    };

    const bindTabButtons = () => {
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const tabName = tab.getAttribute('data-cartoes-tab') || 'operadoras';
                setActiveTab(tabName);
            });
        });
    };

    const bindResetButtons = () => {
        document.querySelectorAll('[data-cartoes-reset]').forEach((button) => {
            button.addEventListener('click', () => {
                const formName = button.getAttribute('data-cartoes-reset') || '';
                clearForm(formName);
            });
        });
    };

    const bindEditButtons = () => {
        document.querySelectorAll('[data-cartoes-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.getAttribute('data-cartoes-edit') || '';
                const values = {};

                Array.from(button.attributes).forEach((attribute) => {
                    if (!attribute.name.startsWith('data-cartoes-') || attribute.name === 'data-cartoes-edit') {
                        return;
                    }

                    // Os campos do form usam data-cartoes-field="snake_case"
                    // (mesmo nome do atributo `name`) — converter pra
                    // camelCase aqui fazia o lookup em fillForm() nunca bater
                    // pra nenhum campo com hifen (operadora-id, parcelas-inicial,
                    // taxa-percentual, etc.), deixando "Editar" em branco pra
                    // tudo exceto os campos de uma palavra só.
                    const key = attribute.name.replace('data-cartoes-', '').replaceAll('-', '_');
                    values[key] = attribute.value;
                });

                fillForm(target, values);

                if (target === 'taxa') {
                    openFormModal('taxa', 'Editar taxa');
                } else if (target === 'gateway') {
                    openFormModal('gateway', 'Editar taxa online');
                }
            });
        });
    };

    const bindNewModalFormButtons = () => {
        document.querySelector('[data-cartoes-new="taxa"]')?.addEventListener('click', () => {
            clearForm('taxa');
            openFormModal('taxa', 'Nova taxa');
        });

        document.querySelector('[data-cartoes-new="gateway"]')?.addEventListener('click', () => {
            clearForm('gateway');
            openFormModal('gateway', 'Nova taxa online');
        });
    };

    const initGatewaySelect = () => {
        if (!(gatewayProviderSelect instanceof HTMLSelectElement) || !(gatewayModeSelect instanceof HTMLSelectElement)) {
            return;
        }

        gatewayProviderSelect.addEventListener('change', () => {
            syncGatewayModes();
        });

        syncGatewayModes();
    };

    const init = () => {
        bindTabButtons();
        bindResetButtons();
        bindEditButtons();
        bindNewModalFormButtons();
        bindRowFilters();
        bindSimulator();
        initGatewaySelect();

        const initialTab = config.activeTab || 'operadoras';
        setActiveTab(initialTab, false);

        refreshSelect2(document);
    };

    document.addEventListener('DOMContentLoaded', init);
})();
