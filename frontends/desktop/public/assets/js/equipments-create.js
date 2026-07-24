(function () {
    const config = window.__EQUIPMENT_CREATE;

    if (!config) {
        return;
    }

    const form = document.getElementById('equipmentCreateForm');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const maxPhotos = Number(config.maxPhotos || 4);
    const isEditMode = Boolean(config.isEdit);
    const isEmbeddedMode = document.body.classList.contains('desktop-body-embedded');

    const state = {
        brands: Array.isArray(config.formData?.brands) ? [...config.formData.brands] : [],
        models: Array.isArray(config.formData?.models) ? [...config.formData.models] : [],
        catalogRelations: Array.isArray(config.formData?.catalog_relations) ? [...config.formData.catalog_relations] : [],
        transientBrandIdsByType: new Map(),
        transientModelIdsByTypeBrand: new Map(),
        isApplyingSnapshot: false,
        photos: [],
        photoQueue: [],
        cropper: null,
        cropTargetFileName: '',
        activeStream: null,
        collectorTimer: null,
        collectorSnapshot: null,
    };

    const els = {
        tabs: Array.from(document.querySelectorAll('[data-equipment-tab]')),
        panels: Array.from(document.querySelectorAll('[data-equipment-panel]')),
        colorTab: document.querySelector('[data-equipment-tab="cor"]'),
        clientSelect: document.getElementById('equipmentClientSelect'),
        clientLabel: document.getElementById('equipmentClientLabel'),
        type: document.getElementById('equipmentType'),
        brand: document.getElementById('equipmentBrand'),
        model: document.getElementById('equipmentModel'),
        desktopMode: document.getElementById('equipmentDesktopMode'),
        technicalPanel: document.getElementById('equipmentTechnicalPanel'),
        collectorCard: document.getElementById('equipmentCollectorCard'),
        passwordMode: document.getElementById('equipmentPasswordMode'),
        passwordText: document.getElementById('equipmentPasswordText'),
        passwordPattern: document.getElementById('equipmentPasswordPattern'),
        passwordPatternLabel: document.getElementById('equipmentPasswordPatternLabel'),
        passwordPatternWrapper: document.getElementById('equipmentPasswordPatternWrapper'),
        passwordPatternToggle: document.getElementById('equipmentPasswordPatternToggle'),
        passwordToggles: Array.from(document.querySelectorAll('[data-password-mode]')),
        passwordPanels: Array.from(document.querySelectorAll('[data-password-panel]')),
        passwordDots: Array.from(document.querySelectorAll('[data-pattern-node]')),
        passwordPatternClear: document.getElementById('equipmentPasswordPatternClear'),
        colorName: document.getElementById('equipmentColorName'),
        colorHex: document.getElementById('equipmentColorHex'),
        colorRgb: document.getElementById('equipmentColorRgb'),
        colorPreview: document.getElementById('equipmentColorPreview'),
        colorNameLabel: document.getElementById('equipmentColorNameLabel'),
        colorRgbLabel: document.getElementById('equipmentColorRgbLabel'),
        colorSwatches: Array.from(document.querySelectorAll('.equipment-color-swatch')),
        photosInput: document.getElementById('equipmentPhotosInput'),
        photoGrid: document.getElementById('equipmentPhotoGrid'),
        photoGalleryButton: document.getElementById('equipmentPhotoGalleryButton'),
        photoCameraButton: document.getElementById('equipmentPhotoCameraButton'),
        primaryPhotoIndex: document.getElementById('equipmentPrimaryPhotoIndex'),
        primaryExistingPhotoId: document.getElementById('equipmentPrimaryExistingPhotoId'),
        existingPhotoSync: document.getElementById('equipmentExistingPhotoSync'),
        existingPhotoIdsContainer: document.getElementById('equipmentExistingPhotoIdsContainer'),
        quickClientModal: document.getElementById('quickClientModal'),
        quickClientSubmit: document.getElementById('quickClientSubmit'),
        quickBrandModal: document.getElementById('quickBrandModal'),
        quickBrandName: document.getElementById('quickBrandName'),
        quickBrandTrigger: document.getElementById('quickBrandTrigger'),
        quickBrandSubmit: document.getElementById('quickBrandSubmit'),
        quickModelModal: document.getElementById('quickModelModal'),
        quickModelBrand: document.getElementById('quickModelBrand'),
        quickModelName: document.getElementById('quickModelName'),
        quickModelTrigger: document.getElementById('quickModelTrigger'),
        quickModelSubmit: document.getElementById('quickModelSubmit'),
        quickModelSuggest: document.getElementById('quickModelSuggest'),
        quickModelSuggestions: document.getElementById('quickModelSuggestions'),
        embeddedCancelButton: document.querySelector('[data-equipment-embedded-cancel]'),
        submitButton: form.querySelector('button[type="submit"]'),
        collectorCode: document.getElementById('collectorPairingCode'),
        collectorDisplay: document.getElementById('collectorPairingDisplay'),
        collectorStatus: document.getElementById('collectorPairingStatus'),
        collectorCreate: document.getElementById('collectorPairingCreate'),
        collectorImport: document.getElementById('collectorPairingImport'),
        collectorDownloadWindows: document.getElementById('collectorPairingDownloadWindows'),
        collectorCommandWrapperWindows: document.getElementById('collectorPairingCommandWrapperWindows'),
        collectorCommandCodeWindows: document.getElementById('collectorPairingCommandWindows'),
        collectorCommandCopyWindows: document.getElementById('collectorPairingCommandCopyWindows'),
        collectorCommandWrapperLinux: document.getElementById('collectorPairingCommandWrapperLinux'),
        collectorCommandCodeLinux: document.getElementById('collectorPairingCommandLinux'),
        collectorCommandCopyLinux: document.getElementById('collectorPairingCommandCopyLinux'),
        collectorPairingCodeInput: document.getElementById('equipmentCollectorPairingCode'),
        cameraModal: document.getElementById('equipmentCameraModal'),
        cameraVideo: document.getElementById('equipmentCameraVideo'),
        cameraCapture: document.getElementById('equipmentCameraCapture'),
        cropModal: document.getElementById('equipmentCropModal'),
        cropImage: document.getElementById('equipmentCropImage'),
        cropConfirm: document.getElementById('equipmentCropConfirm'),
        fillButtons: Array.from(document.querySelectorAll('[data-fill-target]')),
    };

    const getModal = (element) => element ? bootstrap.Modal.getOrCreateInstance(element) : null;
    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const getReadableTextColor = (hex) => {
        const normalized = String(hex || '').trim().replace('#', '');
        if (!/^[0-9a-f]{6}$/i.test(normalized)) {
            return '#fff';
        }

        const [r, g, b] = [0, 2, 4].map((index) => parseInt(normalized.slice(index, index + 2), 16) / 255);
        const toLinear = (channel) => (channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
        const luminance = 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);

        return luminance > 0.5 ? '#0f172a' : '#fff';
    };

    const dispatchSelectChange = (select) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        // Select2 (ver initSelect2 em desktop.js) só re-renderiza sua própria
        // UI quando o `change` chega via jQuery — um dispatchEvent nativo
        // ainda alcança o listener interno do Select2, mas com o DOM em
        // estado inconsistente para ele (mesmo bug documentado em
        // orders-closure.js, na direção oposta). Reaproveita a instância já
        // inicializada quando existir; cai para o evento nativo só se o
        // select nunca virou Select2.
        if (
            typeof window.jQuery !== 'undefined'
            && window.jQuery.fn
            && typeof window.jQuery.fn.trigger === 'function'
            && select.dataset.select2Ready === '1'
        ) {
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

    const reinitSelect2 = (select) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        if (window.DesktopUi && typeof window.DesktopUi.refreshSelect2 === 'function') {
            window.DesktopUi.refreshSelect2(select);
        }
    };

    const getTypeById = (typeId) => {
        const normalizedId = String(typeId || '');

        return (Array.isArray(config.formData?.types) ? config.formData.types : [])
            .find((type) => String(type.id || '') === normalizedId) || null;
    };

    const getSelectedTypeId = () => (els.type instanceof HTMLSelectElement ? String(els.type.value || '') : '');
    const hasSelectedType = () => getSelectedTypeId() !== '';
    const hasSelectedBrand = () => getSelectedBrandId() !== '';

    const getSelectedTypeFamily = () => {
        const selected = els.type?.selectedOptions?.[0];

        return selected?.dataset.family || getTypeById(getSelectedTypeId())?.family || 'other';
    };

    // Restrito a "desktop" de propósito: os defaults de catálogo abaixo existem apenas
    // para o fluxo de "Desktop montado". Notebook nunca usa esses defaults (é sempre OEM).
    const isMountedDesktopFamilyType = (typeId = getSelectedTypeId()) => {
        const family = getTypeById(typeId)?.family || (typeId === '' ? 'other' : '');

        return family === 'desktop';
    };

    const getDesktopDefaultBrandId = () => String(config.formData?.desktop_defaults?.marca_id || '');
    const getDesktopDefaultModelId = () => String(config.formData?.desktop_defaults?.modelo_id || '');
    const toggleDisabled = (element, disabled) => {
        if (
            !(element instanceof HTMLSelectElement)
            && !(element instanceof HTMLButtonElement)
            && !(element instanceof HTMLInputElement)
            && !(element instanceof HTMLTextAreaElement)
        ) {
            return;
        }

        element.disabled = disabled;
    };

    const getTransientBrandIdsForType = (typeId) => Array.from(state.transientBrandIdsByType.get(String(typeId || '')) || []);
    const getTransientModelIdsForTypeBrand = (typeId, brandId) => Array.from(state.transientModelIdsByTypeBrand.get(`${String(typeId || '')}|${String(brandId || '')}`) || []);

    const addTransientBrandForType = (typeId, brandId) => {
        if (!typeId || !brandId) {
            return;
        }

        const key = String(typeId);
        if (!state.transientBrandIdsByType.has(key)) {
            state.transientBrandIdsByType.set(key, new Set());
        }

        state.transientBrandIdsByType.get(key)?.add(String(brandId));
    };

    const addTransientModelForTypeBrand = (typeId, brandId, modelId) => {
        if (!typeId || !brandId || !modelId) {
            return;
        }

        const key = `${String(typeId)}|${String(brandId)}`;
        if (!state.transientModelIdsByTypeBrand.has(key)) {
            state.transientModelIdsByTypeBrand.set(key, new Set());
        }

        state.transientModelIdsByTypeBrand.get(key)?.add(String(modelId));
    };

    const getAllowedBrandsForType = (typeId) => {
        const normalizedTypeId = String(typeId || '');

        if (normalizedTypeId === '') {
            return [];
        }

        const allowedIds = new Set();

        state.catalogRelations.forEach((relation) => {
            if (String(relation.tipo_id || '') !== normalizedTypeId) {
                return;
            }

            allowedIds.add(String(relation.marca_id || ''));
        });

        getTransientBrandIdsForType(normalizedTypeId).forEach((brandId) => {
            allowedIds.add(String(brandId));
        });

        if (isMountedDesktopFamilyType(normalizedTypeId)) {
            const defaultBrandId = getDesktopDefaultBrandId();
            if (defaultBrandId !== '') {
                allowedIds.add(defaultBrandId);
            }
        }

        return state.brands.filter((brand) => allowedIds.has(String(brand.id || '')));
    };

    const getAllowedModelsForContext = (typeId, brandId) => {
        const normalizedTypeId = String(typeId || '');
        const normalizedBrandId = String(brandId || '');

        if (normalizedTypeId === '' || normalizedBrandId === '') {
            return [];
        }

        const allowedIds = new Set();

        state.catalogRelations.forEach((relation) => {
            if (String(relation.tipo_id || '') !== normalizedTypeId || String(relation.marca_id || '') !== normalizedBrandId) {
                return;
            }

            allowedIds.add(String(relation.modelo_id || ''));
        });

        getTransientModelIdsForTypeBrand(normalizedTypeId, normalizedBrandId).forEach((modelId) => {
            allowedIds.add(String(modelId));
        });

        if (isMountedDesktopFamilyType(normalizedTypeId) && normalizedBrandId === getDesktopDefaultBrandId()) {
            const defaultModelId = getDesktopDefaultModelId();
            if (defaultModelId !== '') {
                allowedIds.add(defaultModelId);
            }
        }

        return state.models.filter((model) => (
            String(model.marca_id || '') === normalizedBrandId && allowedIds.has(String(model.id || ''))
        ));
    };

    const rebuildSelectOptions = (select, options, {
        placeholder = 'Selecione...',
        selectedValue = '',
    } = {}) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        const normalizedSelectedValue = String(selectedValue || '');

        select.innerHTML = '';

        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = placeholder;
        select.appendChild(placeholderOption);

        options.forEach((item) => {
            const option = document.createElement('option');
            option.value = String(item.id ?? '');
            option.textContent = String(item.nome ?? '');

            if (item.brand_id !== undefined) {
                option.dataset.brandId = String(item.brand_id);
            }

            if (item.tipo_id !== undefined) {
                option.dataset.typeId = String(item.tipo_id);
            }

            if (String(item.id ?? '') === normalizedSelectedValue) {
                option.selected = true;
            }

            select.appendChild(option);
        });

        if (normalizedSelectedValue !== '' && !options.some((item) => String(item.id ?? '') === normalizedSelectedValue)) {
            select.value = '';
        }

        reinitSelect2(select);
    };

    const syncClientLabel = () => {
        if (!(els.clientSelect instanceof HTMLSelectElement) || !(els.clientLabel instanceof HTMLInputElement)) {
            return;
        }

        const selectedOption = els.clientSelect.selectedOptions?.[0];
        els.clientLabel.value = selectedOption?.textContent?.trim() || '';
    };

    const select2Language = {
        errorLoading: () => 'Os resultados não puderam ser carregados.',
        inputTooLong: (args) => {
            const overChars = args.input.length - args.maximum;
            const suffix = overChars === 1 ? 'caractere' : 'caracteres';
            return `Apague ${overChars} ${suffix}`;
        },
        inputTooShort: (args) => {
            const remaining = args.minimum - args.input.length;
            const suffix = remaining === 1 ? 'caractere' : 'caracteres';
            return `Digite mais ${remaining} ${suffix}`;
        },
        loadingMore: () => 'Carregando mais resultados…',
        maximumSelected: (args) => {
            const suffix = args.maximum === 1 ? 'item' : 'itens';
            return `Você só pode selecionar ${args.maximum} ${suffix}`;
        },
        noResults: () => 'Nenhum resultado encontrado',
        searching: () => 'Buscando…',
        removeAllItems: () => 'Remover todos os itens',
    };

    const getSelect2DropdownParent = (select, $) => {
        const modal = select.closest('.modal');

        if (modal) {
            return $(modal);
        }

        const offcanvas = select.closest('.offcanvas');

        if (offcanvas) {
            return $(offcanvas);
        }

        return $(document.body);
    };

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

    const renderQuickClientErrors = (messages, fallbackMessage = '') => {
        const errorBox = document.getElementById('quickClientErrors');
        if (!(errorBox instanceof HTMLElement)) {
            return;
        }

        const items = Array.isArray(messages) ? messages.filter(Boolean) : [];
        errorBox.innerHTML = items.length > 0
            ? `<ul class="mb-0 ps-3">${items.map((message) => `<li>${escapeHtml(message)}</li>`).join('')}</ul>`
            : escapeHtml(fallbackMessage || 'Nao foi possivel cadastrar o cliente.');
        errorBox.classList.remove('d-none');
    };

    const clearQuickClientErrors = () => {
        const errorBox = document.getElementById('quickClientErrors');
        if (!(errorBox instanceof HTMLElement)) {
            return;
        }

        errorBox.classList.add('d-none');
        errorBox.innerHTML = '';
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

            if (window.DesktopUi && typeof window.DesktopUi.logError === 'function') {
                window.DesktopUi.logError('equipments-create.requestJson', error, {
                    path: String(url).split('?')[0],
                    method,
                });
            }

            throw error;
        }

        return payload;
    };

    const requestMultipart = async (url, { method = 'POST', body = null } = {}) => {
        const options = {
            method,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
        };

        if (body !== null) {
            options.body = body;
        }

        const response = await fetch(url, options);
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload.success === false) {
            const error = new Error(payload.message || 'Falha ao processar a solicitação.');
            error.status = response.status;
            error.details = payload.errors || null;

            if (window.DesktopUi && typeof window.DesktopUi.logError === 'function') {
                window.DesktopUi.logError('equipments-create.requestMultipart', error, {
                    path: String(url).split('?')[0],
                    method,
                });
            }

            throw error;
        }

        return payload;
    };

    const setActiveTab = (name) => {
        els.tabs.forEach((button) => {
            const active = button.dataset.equipmentTab === name;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        els.panels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.equipmentPanel === name);
        });
    };

    const updateTechnicalPanel = () => {
        const selected = els.type?.selectedOptions?.[0];
        const family = selected?.dataset.family || 'other';
        const visible = family === 'desktop' || family === 'notebook';

        els.technicalPanel?.classList.toggle('is-hidden', !visible);

        if (els.desktopMode instanceof HTMLSelectElement) {
            const montadoOption = els.desktopMode.querySelector('option[value="montado"]');
            if (montadoOption instanceof HTMLOptionElement) {
                montadoOption.disabled = family !== 'desktop';
            }

            toggleDisabled(els.desktopMode, family === 'notebook');

            if (!visible) {
                els.desktopMode.value = '';
            } else if (family === 'notebook') {
                els.desktopMode.value = 'oem';
            } else if (!els.desktopMode.value) {
                els.desktopMode.value = 'montado';
            }

            reinitSelect2(els.desktopMode);
        }
    };

    const updateCollectorVisibility = () => {
        const visible = isCollectorCompatibleType();

        if (els.collectorCard instanceof HTMLElement) {
            els.collectorCard.hidden = !visible;
            els.collectorCard.setAttribute('aria-hidden', visible ? 'false' : 'true');
            els.collectorCard.classList.toggle('d-none', !visible);
        }
    };

    const isCollectorCompatibleType = () => {
        const selected = els.type?.selectedOptions?.[0];
        const family = selected?.dataset.family || 'other';

        return family === 'desktop' || family === 'notebook';
    };

    const updateModelOptions = ({
        forceDesktopDefaults = false,
        selectedBrandId = els.brand instanceof HTMLSelectElement ? String(els.brand.value || '') : '',
        selectedModelId = els.model instanceof HTMLSelectElement ? String(els.model.value || '') : '',
        selectedQuickBrandId = els.quickModelBrand instanceof HTMLSelectElement ? String(els.quickModelBrand.value || '') : '',
    } = {}) => {
        if (!(els.model instanceof HTMLSelectElement) || !(els.brand instanceof HTMLSelectElement)) {
            return;
        }

        const typeId = getSelectedTypeId();
        const typeBrands = getAllowedBrandsForType(typeId);
        const typeBrandIds = new Set(typeBrands.map((brand) => String(brand.id || '')));
        const hasType = typeId !== '';
        const brandPlaceholder = !hasType
            ? 'Selecione o tipo primeiro...'
            : (typeBrands.length === 0 ? 'Nenhuma marca disponível para este tipo' : 'Selecione a marca...');

        let nextBrandId = String(selectedBrandId || '');
        if (nextBrandId !== '' && !typeBrandIds.has(nextBrandId)) {
            nextBrandId = '';
        }

        if (forceDesktopDefaults && isMountedDesktopFamilyType(typeId)) {
            const defaultBrandId = getDesktopDefaultBrandId();
            if (defaultBrandId !== '' && typeBrandIds.has(defaultBrandId)) {
                nextBrandId = defaultBrandId;
            }
        }

        toggleDisabled(els.brand, !hasType);
        rebuildSelectOptions(els.brand, typeBrands, {
            placeholder: brandPlaceholder,
            selectedValue: nextBrandId,
        });
        toggleDisabled(els.quickBrandTrigger, !hasType);

        const quickBrandValue = String(selectedQuickBrandId || '');
        let nextQuickBrandId = quickBrandValue;
        if (nextQuickBrandId !== '' && !typeBrandIds.has(nextQuickBrandId)) {
            nextQuickBrandId = nextBrandId;
        }
        if (nextQuickBrandId === '' && nextBrandId !== '') {
            nextQuickBrandId = nextBrandId;
        }

        if (els.quickModelBrand instanceof HTMLSelectElement) {
            toggleDisabled(els.quickModelBrand, !hasType);
            rebuildSelectOptions(els.quickModelBrand, typeBrands, {
                placeholder: !hasType
                    ? 'Selecione o tipo primeiro...'
                    : (typeBrands.length === 0 ? 'Nenhuma marca disponível para este tipo' : 'Selecione...'),
                selectedValue: nextQuickBrandId,
            });
        }
        toggleDisabled(els.quickModelTrigger, !hasType || typeBrands.length === 0);

        const typeModels = getAllowedModelsForContext(typeId, nextBrandId);
        const typeModelIds = new Set(typeModels.map((model) => String(model.id || '')));
        const modelPlaceholder = !hasType
            ? 'Selecione o tipo primeiro...'
            : (nextBrandId === ''
                ? 'Selecione a marca primeiro...'
                : (typeModels.length === 0 ? 'Nenhum modelo disponível para esta marca' : 'Selecione o modelo...'));

        let nextModelId = String(selectedModelId || '');
        if (nextModelId !== '' && !typeModelIds.has(nextModelId)) {
            nextModelId = '';
        }

        if (forceDesktopDefaults && isMountedDesktopFamilyType(typeId) && nextBrandId === getDesktopDefaultBrandId()) {
            const defaultModelId = getDesktopDefaultModelId();
            if (defaultModelId !== '' && typeModelIds.has(defaultModelId)) {
                nextModelId = defaultModelId;
            }
        }

        toggleDisabled(els.model, !hasType || nextBrandId === '');
        rebuildSelectOptions(els.model, typeModels, {
            placeholder: modelPlaceholder,
            selectedValue: nextModelId,
        });
    };

    const applyDesktopDefaults = () => {
        if (!(els.desktopMode instanceof HTMLSelectElement)) {
            return;
        }

        if (els.desktopMode.value !== 'montado' || !isMountedDesktopFamilyType()) {
            return;
        }

        updateModelOptions({
            forceDesktopDefaults: true,
            selectedBrandId: getDesktopDefaultBrandId(),
            selectedModelId: getDesktopDefaultModelId(),
            selectedQuickBrandId: getDesktopDefaultBrandId(),
        });
    };

    const buildClientLabel = (client) => {
        const parts = [
            String(client?.nome_razao || '').trim(),
            String(client?.cpf_cnpj || '').trim(),
            String(client?.telefone1 || '').trim(),
            String(client?.telefone2 || '').trim(),
            String(client?.nome_contato || '').trim() !== '' ? `Contato: ${String(client?.nome_contato || '').trim()}` : '',
            String(client?.telefone_contato || '').trim(),
            String(client?.email || '').trim(),
        ].filter(Boolean);

        return parts.join(' - ');
    };

    const applyClientSelection = (client) => {
        if (!(els.clientSelect instanceof HTMLSelectElement)) {
            return;
        }

        const clientId = String(client?.id || '').trim();
        const clientLabel = buildClientLabel(client);

        if (clientId === '' || clientLabel === '') {
            return;
        }

        const existingOption = Array.from(els.clientSelect.options).find((option) => String(option.value) === clientId);

        if (existingOption) {
            existingOption.textContent = clientLabel;
            existingOption.selected = true;
        } else {
            const option = new Option(clientLabel, clientId, true, true);
            els.clientSelect.add(option);
        }

        if (typeof window.jQuery !== 'undefined' && window.jQuery.fn && typeof window.jQuery.fn.select2 === 'function') {
            window.jQuery(els.clientSelect).trigger('change');
        } else {
            dispatchSelectChange(els.clientSelect);
        }

        syncClientLabel();
    };

    const initClientSelect = () => {
        if (!(els.clientSelect instanceof HTMLSelectElement)) {
            return;
        }

        if (typeof window.jQuery === 'undefined' || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') {
            syncClientLabel();
            return;
        }

        const $ = window.jQuery;

        if ($(els.clientSelect).data('select2')) {
            try {
                $(els.clientSelect).select2('destroy');
            } catch (error) {
                console.error('[Desktop][Clientes][Select2] Falha ao destruir a instancia anterior.', {
                    elementId: els.clientSelect.id || null,
                    error,
                });
            }
        }

        const placeholder = els.clientSelect.dataset.select2Placeholder || 'Selecione ou busque um cliente...';

        $(els.clientSelect).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder,
            allowClear: true,
            dropdownParent: getSelect2DropdownParent(els.clientSelect, $),
            language: select2Language,
            templateResult: (client) => {
                if (client.loading) {
                    return client.text;
                }

                const details = [client.telefone1, client.email].filter(Boolean).join(' · ');
                const markup = `
                    <span class="d-block">
                        <strong>${escapeHtml(client.text || '')}</strong>
                        ${details !== '' ? `<small class="d-block text-muted">${escapeHtml(details)}</small>` : ''}
                    </span>
                `;

                return $(markup);
            },
            templateSelection: (client) => client.text || placeholder,
        });

        els.clientSelect.dataset.select2Ready = '1';

        onSelectEvent(els.clientSelect, 'change', syncClientLabel);
        onSelectEvent(els.clientSelect, 'select2:select', syncClientLabel);
        onSelectEvent(els.clientSelect, 'select2:clear', syncClientLabel);

        syncClientLabel();
    };

    const setPasswordMode = (mode) => {
        if (els.passwordMode instanceof HTMLInputElement) {
            els.passwordMode.value = mode;
        }

        els.passwordToggles.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.passwordMode === mode);
        });

        els.passwordPanels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.passwordPanel === mode);
        });

        if (mode !== 'desenho') {
            if (els.passwordPatternWrapper instanceof HTMLElement) {
                els.passwordPatternWrapper.classList.add('d-none');
            }

            if (els.passwordPatternToggle instanceof HTMLButtonElement) {
                els.passwordPatternToggle.textContent = 'Mostrar desenho';
            }
        }
    };

    const togglePasswordPatternVisibility = () => {
        if (!(els.passwordPatternWrapper instanceof HTMLElement)) {
            return;
        }

        const isHidden = els.passwordPatternWrapper.classList.contains('d-none');
        els.passwordPatternWrapper.classList.toggle('d-none', !isHidden);

        if (els.passwordPatternToggle instanceof HTMLButtonElement) {
            els.passwordPatternToggle.textContent = isHidden ? 'Ocultar desenho' : 'Mostrar desenho';
        }
    };

    const updatePatternUi = () => {
        const sequence = (els.passwordPattern instanceof HTMLInputElement ? els.passwordPattern.value : '')
            .split('-')
            .filter(Boolean);

        els.passwordDots.forEach((button) => {
            button.classList.toggle('is-selected', sequence.includes(button.dataset.patternNode || ''));
        });

        if (els.passwordPatternLabel instanceof HTMLElement) {
            els.passwordPatternLabel.textContent = sequence.length > 0
                ? `Desenho definido: ${sequence.join(' → ')}`
                : 'Nenhum desenho definido.';
        }
    };

    const updateColorPreview = (nameOverride = '') => {
        const hex = els.colorHex instanceof HTMLInputElement ? els.colorHex.value : '#64748b';
        const rgb = hex
            ? [1, 3, 5].map((index) => parseInt(hex.slice(index, index + 2), 16)).join(', ')
            : '100, 116, 139';
        const colorName = nameOverride || (els.colorName instanceof HTMLInputElement ? els.colorName.value.trim() : '') || 'Sem cor definida';

        if (els.colorPreview instanceof HTMLElement) {
            els.colorPreview.style.background = hex;
        }

        if (els.colorRgb instanceof HTMLInputElement) {
            els.colorRgb.value = rgb;
        }

        if (els.colorNameLabel instanceof HTMLElement) {
            els.colorNameLabel.textContent = colorName;
        }

        if (els.colorRgbLabel instanceof HTMLElement) {
            els.colorRgbLabel.textContent = rgb;
        }

        if (els.colorTab instanceof HTMLElement) {
            els.colorTab.style.setProperty('--equipment-tab-color-accent', hex);
            els.colorTab.style.setProperty('--equipment-tab-color-text', getReadableTextColor(hex));
        }
    };

    const getNewPhotos = () => state.photos.filter((item) => item.source === 'new');
    const getExistingPhotos = () => state.photos.filter((item) => item.source === 'existing');

    const resolvePrimaryPhotoIndex = () => {
        if (state.photos.length === 0) {
            return -1;
        }

        const existingPrimaryId = Number(els.primaryExistingPhotoId instanceof HTMLInputElement ? els.primaryExistingPhotoId.value || 0 : 0);
        if (existingPrimaryId > 0) {
            const existingIndex = state.photos.findIndex((item) => (
                item.source === 'existing' && Number(item.id || 0) === existingPrimaryId
            ));

            if (existingIndex >= 0) {
                return existingIndex;
            }
        }

        const requestedNewIndex = Number(els.primaryPhotoIndex instanceof HTMLInputElement ? els.primaryPhotoIndex.value : '');
        if (Number.isInteger(requestedNewIndex) && requestedNewIndex >= 0) {
            let currentNewIndex = -1;

            for (let index = 0; index < state.photos.length; index += 1) {
                if (state.photos[index].source !== 'new') {
                    continue;
                }

                currentNewIndex += 1;

                if (currentNewIndex === requestedNewIndex) {
                    return index;
                }
            }
        }

        return -1;
    };

    const setPrimaryPhotoByGlobalIndex = (globalIndex) => {
        if (!(els.primaryPhotoIndex instanceof HTMLInputElement)) {
            return;
        }

        if (state.photos.length === 0) {
            els.primaryPhotoIndex.value = '';
            if (els.primaryExistingPhotoId instanceof HTMLInputElement) {
                els.primaryExistingPhotoId.value = '';
            }
            return;
        }

        const normalizedIndex = Math.max(0, Math.min(state.photos.length - 1, Number(globalIndex || 0)));
        const item = state.photos[normalizedIndex];

        if (item.source === 'existing') {
            els.primaryPhotoIndex.value = '';
            if (els.primaryExistingPhotoId instanceof HTMLInputElement) {
                els.primaryExistingPhotoId.value = String(item.id || '');
            }
            return;
        }

        let newIndex = 0;
        for (let index = 0; index < normalizedIndex; index += 1) {
            if (state.photos[index].source === 'new') {
                newIndex += 1;
            }
        }

        els.primaryPhotoIndex.value = String(newIndex);
        if (els.primaryExistingPhotoId instanceof HTMLInputElement) {
            els.primaryExistingPhotoId.value = '';
        }
    };

    const ensurePrimaryPhotoState = () => {
        if (state.photos.length === 0) {
            setPrimaryPhotoByGlobalIndex(0);
            return;
        }

        if (resolvePrimaryPhotoIndex() >= 0) {
            return;
        }

        setPrimaryPhotoByGlobalIndex(0);
    };

    const syncExistingPhotoInputs = () => {
        if (!(els.existingPhotoIdsContainer instanceof HTMLElement)) {
            return;
        }

        els.existingPhotoIdsContainer.innerHTML = '';

        getExistingPhotos().forEach((item) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'existing_photo_ids[]';
            input.value = String(item.id || '');
            els.existingPhotoIdsContainer.appendChild(input);
        });
    };

    const syncPhotoInput = () => {
        if (els.photosInput instanceof HTMLInputElement) {
            const dt = new DataTransfer();
            getNewPhotos().forEach((item) => {
                if (item.file instanceof File) {
                    dt.items.add(item.file);
                }
            });
            els.photosInput.files = dt.files;
        }

        syncExistingPhotoInputs();
        ensurePrimaryPhotoState();
        if (typeof updateEquipmentSubmitState === 'function') {
            updateEquipmentSubmitState();
        }
    };

    const renderPhotos = () => {
        if (!(els.photoGrid instanceof HTMLElement)) {
            return;
        }

        if (state.photos.length === 0) {
            els.photoGrid.innerHTML = `
                <div class="equipment-photo-empty">
                    <i class="bi bi-camera"></i>
                    <strong>${isEditMode ? 'Nenhuma foto vinculada' : 'Nenhuma foto adicionada'}</strong>
                    <span>Use galeria ou camera. Pelo menos uma foto principal e obrigatoria antes do envio.</span>
                </div>
            `;
            return;
        }

        const primaryIndex = resolvePrimaryPhotoIndex();

        els.photoGrid.innerHTML = state.photos.map((item, index) => `
            <article class="equipment-photo-card ${index === primaryIndex ? 'is-primary' : ''}">
                <a href="${escapeHtml(item.previewUrl)}"
                    class="d-block"
                    data-photo-viewer-trigger
                    data-photo-viewer-group="equipment-editor-photos"
                    data-photo-viewer-title="${escapeHtml(item.name || `Foto ${index + 1}`)}">
                    <img src="${escapeHtml(item.previewUrl)}" alt="Preview ${index + 1}">
                </a>
                <div class="equipment-photo-card-body">
                    <strong>${escapeHtml(item.name || `Foto ${index + 1}`)}</strong>
                    <span>${escapeHtml(item.meta || 'Arquivo de imagem')}</span>
                </div>
                <div class="equipment-photo-card-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-photo-primary="${index}">Principal</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-photo-remove="${index}">Remover</button>
                </div>
            </article>
        `).join('');

        els.photoGrid.querySelectorAll('[data-photo-primary]').forEach((button) => {
            button.addEventListener('click', () => {
                setPrimaryPhotoByGlobalIndex(button.dataset.photoPrimary || 0);
                renderPhotos();
            });
        });

        if (window.DesktopUi && typeof window.DesktopUi.refreshPhotoViewers === 'function') {
            window.DesktopUi.refreshPhotoViewers(els.photoGrid);
        }

        els.photoGrid.querySelectorAll('[data-photo-remove]').forEach((button) => {
            button.addEventListener('click', () => {
                const index = Number(button.dataset.photoRemove || -1);
                if (index < 0) {
                    return;
                }

                const [removedPhoto] = state.photos.splice(index, 1);
                if (removedPhoto?.source === 'new' && typeof removedPhoto.previewUrl === 'string' && removedPhoto.previewUrl.startsWith('blob:')) {
                    URL.revokeObjectURL(removedPhoto.previewUrl);
                }

                syncPhotoInput();
                renderPhotos();
            });
        });
    };

    const ensureRequiredPhotos = () => {
        if (state.photos.length > 0) {
            return true;
        }

        setActiveTab('fotos');
        showAlert(
            'warning',
            'Foto obrigatoria',
            'Adicione ao menos uma foto principal antes de salvar o equipamento.'
        );
        els.photoGalleryButton?.focus?.();

        return false;
    };

    const setFormSubmitState = (loading) => {
        if (!(els.submitButton instanceof HTMLButtonElement)) {
            return;
        }

        if (loading) {
            if (!els.submitButton.dataset.originalHtml) {
                els.submitButton.dataset.originalHtml = els.submitButton.innerHTML;
            }

            els.submitButton.disabled = true;
            els.submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Salvando...';
            return;
        }

        els.submitButton.disabled = false;
        els.submitButton.innerHTML = els.submitButton.dataset.originalHtml
            || '<i class="bi bi-save me-2"></i>Criar equipamento';
    };

    const applyDominantColor = (file) => {
        const image = new Image();
        image.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = 1;
            canvas.height = 1;
            const context = canvas.getContext('2d');
            if (!context) {
                return;
            }
            context.drawImage(image, 0, 0, 1, 1);
            const [r, g, b] = context.getImageData(0, 0, 1, 1).data;
            const hex = `#${[r, g, b].map((value) => value.toString(16).padStart(2, '0')).join('')}`;
            if (els.colorHex instanceof HTMLInputElement) {
                els.colorHex.value = hex;
            }
            if (els.colorName instanceof HTMLInputElement && !els.colorName.value.trim()) {
                els.colorName.value = 'Cor detectada';
            }
            updateColorPreview();
        };
        image.src = URL.createObjectURL(file);
    };

    const addPhotoFile = (file) => {
        if (!(file instanceof File)) {
            return;
        }

        if (state.photos.length >= maxPhotos) {
            showAlert('warning', 'Limite atingido', `O cadastro aceita no máximo ${maxPhotos} fotos.`);
            return;
        }

        state.photos.push({
            source: 'new',
            file,
            previewUrl: URL.createObjectURL(file),
            name: file.name,
            meta: `${Math.round(file.size / 1024)} KB`,
        });

        syncPhotoInput();
        renderPhotos();

        if (state.photos.length === 1) {
            applyDominantColor(file);
        }
    };

    const openCropperForFile = (file) => {
        if (!(els.cropImage instanceof HTMLImageElement) || !window.Cropper) {
            addPhotoFile(file);
            return;
        }

        state.cropTargetFileName = file.name;
        els.cropImage.src = URL.createObjectURL(file);

        const modal = getModal(els.cropModal);
        modal?.show();

        els.cropImage.onload = () => {
            state.cropper?.destroy?.();
            state.cropper = new Cropper(els.cropImage, {
                viewMode: 1,
                autoCropArea: 1,
                background: false,
            });
        };
    };

    const processPhotoQueue = () => {
        const next = state.photoQueue.shift();
        if (!next) {
            return;
        }

        openCropperForFile(next);
    };

    const stopCameraStream = () => {
        if (!state.activeStream) {
            return;
        }

        state.activeStream.getTracks().forEach((track) => track.stop());
        state.activeStream = null;
    };

    const startCamera = async () => {
        if (!(els.cameraVideo instanceof HTMLVideoElement)) {
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            showAlert('error', 'Câmera indisponível', 'Este navegador não oferece suporte a captura de câmera.');
            return;
        }

        try {
            state.activeStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' },
                audio: false,
            });
            els.cameraVideo.srcObject = state.activeStream;
            getModal(els.cameraModal)?.show();
        } catch (error) {
            console.error('[equipments-create] Falha ao iniciar câmera', error);
            showAlert('error', 'Não foi possível abrir a câmera', 'Você ainda pode usar a galeria para enviar a foto.');
        }
    };

    const captureCameraFrame = () => {
        if (!(els.cameraVideo instanceof HTMLVideoElement)) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = els.cameraVideo.videoWidth || 1280;
        canvas.height = els.cameraVideo.videoHeight || 720;
        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }

        context.drawImage(els.cameraVideo, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            if (!blob) {
                showAlert('error', 'Falha ao capturar', 'Não foi possível gerar a imagem da câmera.');
                return;
            }

            const file = new File([blob], `camera-${Date.now()}.jpg`, { type: 'image/jpeg' });
            getModal(els.cameraModal)?.hide();
            stopCameraStream();
            openCropperForFile(file);
        }, 'image/jpeg', 0.92);
    };

    const confirmCrop = () => {
        if (!state.cropper) {
            return;
        }

        state.cropper.getCroppedCanvas({ width: 1600, height: 1200 }).toBlob((blob) => {
            if (!blob) {
                showAlert('error', 'Falha ao recortar', 'Não foi possível preparar a foto.');
                return;
            }

            const file = new File([blob], state.cropTargetFileName || `equipamento-${Date.now()}.jpg`, { type: 'image/jpeg' });
            addPhotoFile(file);
            getModal(els.cropModal)?.hide();
            state.cropper?.destroy?.();
            state.cropper = null;
            processPhotoQueue();
        }, 'image/jpeg', 0.92);
    };

    const appendTextValue = (inputId, value) => {
        const input = document.getElementById(inputId);
        if (!(input instanceof HTMLTextAreaElement || input instanceof HTMLInputElement)) {
            return;
        }

        const current = input.value.trim();
        input.value = current === '' ? value : `${current}, ${value}`;
    };

    const syncQuickModelBrands = () => {
        if (!(els.quickModelBrand instanceof HTMLSelectElement)) {
            return;
        }

        updateModelOptions({
            forceDesktopDefaults: false,
            selectedBrandId: els.brand instanceof HTMLSelectElement ? String(els.brand.value || '') : '',
            selectedModelId: els.model instanceof HTMLSelectElement ? String(els.model.value || '') : '',
            selectedQuickBrandId: String(els.quickModelBrand.value || ''),
        });
    };

    const pollCollectorPairing = (code) => {
        if (!code) {
            return;
        }

        window.clearInterval(state.collectorTimer);

        const updateStatus = async () => {
            try {
                const payload = await requestJson(config.routes.getPairing.replace('__CODE__', encodeURIComponent(code)));
                const pairing = payload.pairing || {};
                state.collectorSnapshot = pairing.snapshot || null;

                if (els.collectorDisplay instanceof HTMLElement) {
                    els.collectorDisplay.textContent = pairing.code || code;
                }

                const statusLabels = {
                    waiting: 'Aguardando envio do cliente...',
                    ready: 'Snapshot recebido — pronto para importar',
                    expired: 'Código expirado',
                    consumed: 'Código já utilizado',
                };
                const pairingStatus = pairing.status || 'waiting';

                if (els.collectorStatus instanceof HTMLElement) {
                    els.collectorStatus.textContent = statusLabels[pairingStatus] || pairingStatus;
                    els.collectorStatus.dataset.status = pairingStatus;
                }

                if (els.collectorImport instanceof HTMLButtonElement) {
                    els.collectorImport.disabled = !(pairingStatus === 'ready' && pairing.snapshot);
                }

                if (pairingStatus === 'expired' || pairingStatus === 'consumed') {
                    window.clearInterval(state.collectorTimer);
                }
            } catch (error) {
                console.error('[equipments-create] Falha ao consultar pareamento', error);
            }
        };

        updateStatus();
        state.collectorTimer = window.setInterval(updateStatus, 4000);
    };

    const applySnapshotToForm = (snapshot) => {
        if (!snapshot || typeof snapshot !== 'object') {
            return;
        }

        const fill = (id, value) => {
            const input = document.getElementById(id);
            if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement || input instanceof HTMLSelectElement) {
                input.value = value || '';
            }
        };

        fill('equipmentSerial', snapshot.numero_serie || snapshot.imei || '');
        fill('equipmentCaseType', snapshot.gabinete_tipo || '');
        fill('equipmentCaseNotes', snapshot.gabinete_observacao || '');
        fill('equipmentMotherboard', snapshot.placa_mae || '');
        fill('equipmentChipset', snapshot.chipset || '');
        fill('equipmentCpu', snapshot.processador || '');
        fill('equipmentRam', snapshot.memoria_ram || '');
        fill('equipmentStorage', snapshot.armazenamento || '');
        fill('equipmentGpu', snapshot.placa_video || '');
        fill('equipmentPowerSupply', snapshot.fonte_alimentacao || '');
        fill('equipmentPasswordText', snapshot.senha_acesso || '');

        const findByName = (collection, targetName, matcher = null) => {
            const normalizedTarget = String(targetName || '').trim().toLowerCase();
            if (normalizedTarget === '') {
                return null;
            }

            return collection.find((item) => {
                if (matcher instanceof Function && !matcher(item)) {
                    return false;
                }

                return String(item?.nome || '').trim().toLowerCase() === normalizedTarget;
            }) || null;
        };

        const snapshotType = findByName(config.formData?.types || [], snapshot.tipo_nome);
        const snapshotBrand = findByName(state.brands, snapshot.marca_nome);
        const snapshotModel = findByName(
            state.models,
            snapshot.modelo_nome,
            (item) => !snapshotBrand || String(item?.marca_id || '') === String(snapshotBrand.id || '')
        );

        state.isApplyingSnapshot = true;

        try {
            if (snapshotType && snapshotBrand) {
                addTransientBrandForType(snapshotType.id, snapshotBrand.id);
            }

            if (snapshotType && snapshotBrand && snapshotModel) {
                addTransientModelForTypeBrand(snapshotType.id, snapshotBrand.id, snapshotModel.id);
            }

            if (snapshot.desktop_modalidade && els.desktopMode instanceof HTMLSelectElement) {
                els.desktopMode.value = snapshot.desktop_modalidade;
                dispatchSelectChange(els.desktopMode);
            }

            const trySelectByText = (select, collection, targetName) => {
                if (!(select instanceof HTMLSelectElement) || !targetName) {
                    return;
                }

                const match = collection.find((item) => String(item.nome || '').toLowerCase() === String(targetName).toLowerCase());
                if (match) {
                    select.value = String(match.id);
                }
            };

            trySelectByText(els.type, config.formData?.types || [], snapshot.tipo_nome);
            dispatchSelectChange(els.type);
            trySelectByText(els.brand, getAllowedBrandsForType(getSelectedTypeId()), snapshot.marca_nome);
            dispatchSelectChange(els.brand);
            trySelectByText(els.model, getAllowedModelsForContext(getSelectedTypeId(), els.brand.value), snapshot.modelo_nome);
            dispatchSelectChange(els.model);

            if (snapshot.cor_hex && els.colorHex instanceof HTMLInputElement) {
                els.colorHex.value = snapshot.cor_hex;
            }
            if (snapshot.cor && els.colorName instanceof HTMLInputElement) {
                els.colorName.value = snapshot.cor;
            }
            updateColorPreview(snapshot.cor || '');
        } finally {
            state.isApplyingSnapshot = false;
        }

        showToast('success', 'Snapshot importado no formulario.');
    };

    const initTabs = () => {
        els.tabs.forEach((button) => {
            button.addEventListener('click', () => setActiveTab(button.dataset.equipmentTab || 'informacoes'));
        });
    };

    const initClients = () => {
        initClientSelect();
        syncClientLabel();
    };

    const initCatalogs = () => {
        updateModelOptions();
        updateTechnicalPanel();
        updateCollectorVisibility();
        if (els.desktopMode instanceof HTMLSelectElement && els.desktopMode.value === 'montado') {
            applyDesktopDefaults();
        }
        syncQuickModelBrands();

        const handleTypeChange = () => {
            updateTechnicalPanel();
            updateCollectorVisibility();
            if (!state.isApplyingSnapshot && els.desktopMode instanceof HTMLSelectElement && els.desktopMode.value === 'montado') {
                applyDesktopDefaults();
                return;
            }
            updateModelOptions();
        };

        onSelectEvent(els.type, 'change', handleTypeChange);
        onSelectEvent(els.type, 'select2:select', handleTypeChange);
        onSelectEvent(els.type, 'select2:clear', handleTypeChange);
        onSelectEvent(els.brand, 'change', () => {
            if (!state.isApplyingSnapshot && els.desktopMode instanceof HTMLSelectElement && els.desktopMode.value === 'montado') {
                applyDesktopDefaults();
                return;
            }

            updateModelOptions();
        });
        onSelectEvent(els.desktopMode, 'change', () => {
            if (!state.isApplyingSnapshot && els.desktopMode instanceof HTMLSelectElement && els.desktopMode.value === 'montado') {
                applyDesktopDefaults();
                return;
            }

            updateModelOptions();
        });
    };

    const initPassword = () => {
        els.passwordToggles.forEach((button) => {
            button.addEventListener('click', () => setPasswordMode(button.dataset.passwordMode || 'desenho'));
        });

        els.passwordPatternToggle?.addEventListener('click', () => {
            if (!(els.passwordMode instanceof HTMLInputElement)) {
                return;
            }

            if (els.passwordMode.value !== 'desenho') {
                setPasswordMode('desenho');
            }

            togglePasswordPatternVisibility();
        });

        els.passwordDots.forEach((button) => {
            button.addEventListener('click', () => {
                if (!(els.passwordPattern instanceof HTMLInputElement)) {
                    return;
                }

                const current = els.passwordPattern.value.split('-').filter(Boolean);
                const value = button.dataset.patternNode || '';
                if (!value || current.includes(value)) {
                    return;
                }

                current.push(value);
                els.passwordPattern.value = current.join('-');
                updatePatternUi();
            });
        });

        els.passwordPatternClear?.addEventListener('click', () => {
            if (els.passwordPattern instanceof HTMLInputElement) {
                els.passwordPattern.value = '';
                updatePatternUi();
            }
        });

        if (els.passwordPatternWrapper instanceof HTMLElement) {
            els.passwordPatternWrapper.classList.add('d-none');
        }

        if (els.passwordPatternToggle instanceof HTMLButtonElement) {
            els.passwordPatternToggle.textContent = 'Mostrar desenho';
        }

        updatePatternUi();
    };

    const initColors = () => {
        els.colorHex?.addEventListener('input', () => updateColorPreview());
        els.colorName?.addEventListener('input', () => updateColorPreview());
        els.colorSwatches.forEach((button) => {
            button.addEventListener('click', () => {
                if (els.colorHex instanceof HTMLInputElement) {
                    els.colorHex.value = button.dataset.colorHex || '#64748b';
                }
                if (els.colorName instanceof HTMLInputElement) {
                    els.colorName.value = button.dataset.colorName || '';
                }
                updateColorPreview(button.dataset.colorName || '');
            });
        });
        updateColorPreview();
    };

    const initPhotos = () => {
        if (Array.isArray(config.existingPhotos) && config.existingPhotos.length > 0) {
            state.photos = config.existingPhotos
                .filter((photo) => Number(photo?.id || 0) > 0 && String(photo?.url || '') !== '')
                .map((photo, index) => ({
                    source: 'existing',
                    id: Number(photo.id || 0),
                    previewUrl: String(photo.url || ''),
                    name: `Foto atual ${index + 1}`,
                    meta: photo.is_principal ? 'Arquivo atual · principal' : 'Arquivo atual',
                }));

            syncPhotoInput();
        }

        els.photoGalleryButton?.addEventListener('click', () => els.photosInput?.click());
        els.photosInput?.addEventListener('change', (event) => {
            const files = Array.from(event.target.files || []);
            state.photoQueue.push(...files);
            event.target.value = '';
            if (!state.cropper) {
                processPhotoQueue();
            }
        });

        els.photoCameraButton?.addEventListener('click', startCamera);
        els.cameraCapture?.addEventListener('click', captureCameraFrame);
        els.cropConfirm?.addEventListener('click', confirmCrop);

        els.cameraModal?.addEventListener('hidden.bs.modal', stopCameraStream);
        els.cropModal?.addEventListener('hidden.bs.modal', () => {
            state.cropper?.destroy?.();
            state.cropper = null;
            if (state.photoQueue.length > 0) {
                processPhotoQueue();
            }
        });

        renderPhotos();
    };

    const isDeferMode = isEmbeddedMode
        && new URLSearchParams(window.location.search).get('defer') === '1';

    const captureAndPostEquipment = () => {
        const fd = new FormData(form);
        const equipment = {};
        fd.forEach((value, key) => {
            if (value instanceof File) {
                return; // fotos não são capturadas no modo diferido
            }
            equipment[key] = value;
        });

        const optionText = (id) => {
            const select = document.getElementById(id);
            if (select instanceof HTMLSelectElement && select.selectedIndex >= 0) {
                return (select.options[select.selectedIndex]?.textContent || '').trim();
            }
            return '';
        };
        equipment.tipo_nome = optionText('equipmentType');
        equipment.marca_nome = optionText('equipmentBrand');
        equipment.modelo_nome = optionText('equipmentModel');

        // Fotos novas (File): transferidas por structured clone via postMessage e
        // enviadas junto com a OS (criação atômica) — não persistem aqui.
        const photos = getNewPhotos()
            .map((item) => item.file)
            .filter((file) => file instanceof File);

        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'equipment-captured',
                message: 'Equipamento capturado.',
                equipment,
                photos,
            }, window.location.origin);
        }
    };

    // --- Gate Próximo/Criar: só libera "Criar equipamento" com os obrigatórios --
    // Obrigatórios: Tipo sempre; Cliente só fora do modo diferido (no diferido o
    // cliente vem da OS); Foto principal sempre. Enquanto faltar algo, o botão
    // vira "Próximo" e leva para a aba/campo pendente.
    const getEquipmentPendingField = () => {
        const tipo = document.getElementById('equipmentType');
        if (!(tipo instanceof HTMLSelectElement) || String(tipo.value || '').trim() === '') {
            return { tab: 'informacoes', el: tipo };
        }
        if (!isDeferMode) {
            const cliente = document.getElementById('equipmentClientSelect');
            if (!(cliente instanceof HTMLSelectElement) || String(cliente.value || '').trim() === '') {
                return { tab: 'informacoes', el: cliente };
            }
        }
        if (state.photos.length === 0) {
            return { tab: 'fotos', el: els.photoGalleryButton };
        }
        return null;
    };

    const originalSubmitHtml = els.submitButton instanceof HTMLButtonElement ? els.submitButton.innerHTML : '';
    const nextSubmitHtml = '<i class="bi bi-arrow-right-circle me-2"></i>Próximo';
    const updateEquipmentSubmitState = () => {
        if (!(els.submitButton instanceof HTMLButtonElement) || els.submitButton.disabled) {
            return;
        }
        const html = getEquipmentPendingField() ? nextSubmitHtml : originalSubmitHtml;
        if (els.submitButton.innerHTML !== html) {
            els.submitButton.innerHTML = html;
        }
        // Mantém o originalHtml coerente para setFormSubmitState restaurar o label.
        els.submitButton.dataset.originalHtml = html;
    };

    const initFormSubmission = () => {
        // Modo diferido (abertura de OS): o equipamento é apenas capturado e
        // criado junto com a OS ao salvar — nada é persistido aqui. O cliente
        // pode ainda ser um cadastro novo pendente, por isso não exigimos cliente.
        if (isDeferMode) {
            const params = new URLSearchParams(window.location.search);
            const clientField = document.getElementById('equipmentClientSelect');
            if (clientField instanceof HTMLElement) {
                clientField.removeAttribute('required');
            }

            // Cliente novo pendente (sem cliente_id): não dá para selecioná-lo no
            // catálogo (ainda não existe). Mostra o nome como contexto read-only —
            // o vínculo é feito pelo backend com o cliente da OS, ao salvar.
            const hasRealClient = (params.get('cliente_id') || '').trim() !== '';
            const clienteNome = (params.get('cliente_nome') || '').trim();
            if (!hasRealClient && clientField instanceof HTMLElement) {
                const wrapper = clientField.closest('.equipment-inline-field');
                if (wrapper instanceof HTMLElement && wrapper.parentNode) {
                    wrapper.classList.add('d-none');
                    const info = document.createElement('div');
                    info.className = 'form-control bg-body-secondary';
                    info.setAttribute('aria-readonly', 'true');
                    info.textContent = clienteNome !== ''
                        ? `${clienteNome} — novo cliente da OS (vínculo ao salvar)`
                        : 'Cliente da OS (vínculo ao salvar)';
                    wrapper.parentNode.insertBefore(info, wrapper.nextSibling);
                }
            }
        }

        form.addEventListener('submit', (event) => {
            // Botão "Próximo": ainda há campo obrigatório pendente — leva até ele
            // em vez de enviar.
            const pending = getEquipmentPendingField();
            if (pending) {
                event.preventDefault();
                setActiveTab(pending.tab);
                if (pending.el && typeof pending.el.focus === 'function') {
                    pending.el.focus();
                }
                return;
            }

            if (!form.reportValidity()) {
                event.preventDefault();
                return;
            }

            if (isDeferMode) {
                event.preventDefault();
                captureAndPostEquipment();
                return;
            }

            if (!ensureRequiredPhotos()) {
                event.preventDefault();
                return;
            }

            if (!isEmbeddedMode) {
                return;
            }

            event.preventDefault();
            setFormSubmitState(true);

            (async () => {
                try {
                    const response = await requestMultipart(form.action, {
                        method: form.method || 'POST',
                        body: new FormData(form),
                    });

                    const equipment = response.equipment || {};
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({
                            type: 'equipment-created',
                            message: response.message || 'Equipamento cadastrado com sucesso.',
                            equipment,
                        }, window.location.origin);
                    } else {
                        showAlert('success', 'Equipamento cadastrado com sucesso.');
                    }
                } catch (error) {
                    const details = extractErrorMessages(error?.details);
                    showAlert(
                        'error',
                        'Falha ao cadastrar equipamento',
                        details.length > 0 ? details.join(' · ') : error.message
                    );
                } finally {
                    setFormSubmitState(false);
                }
            })();
        });

        // Atualiza o rótulo do botão (Próximo/Criar) conforme os obrigatórios.
        form.addEventListener('input', updateEquipmentSubmitState);
        form.addEventListener('change', updateEquipmentSubmitState);
        updateEquipmentSubmitState();
    };

    const initQuickAdd = () => {
        const quickClientForm = document.getElementById('quickClientForm');

        const submitQuickClientForm = async (event) => {
            event.preventDefault();

            if (!(quickClientForm instanceof HTMLFormElement)) {
                return;
            }

            clearQuickClientErrors();

            if (!quickClientForm.reportValidity()) {
                renderQuickClientErrors([], 'Informe nome/razao social e telefone principal antes de salvar.');
                return;
            }

            setQuickClientSubmitState(true);

            try {
                const payload = Object.fromEntries(new FormData(quickClientForm).entries());
                const response = await requestJson(config.routes.quickClient, {
                    method: 'POST',
                    body: payload,
                });

                applyClientSelection(response.client || {});
                getModal(els.quickClientModal)?.hide();
                showToast('success', 'Cliente cadastrado e selecionado.');
            } catch (error) {
                const details = extractErrorMessages(error?.details);
                renderQuickClientErrors(details, error.message);
                showAlert('error', 'Falha ao cadastrar cliente', error.message);
            } finally {
                setQuickClientSubmitState(false);
            }
        };

        els.quickClientSubmit?.addEventListener('click', () => {
            if (!(quickClientForm instanceof HTMLFormElement)) {
                return;
            }

            if (typeof quickClientForm.requestSubmit === 'function') {
                quickClientForm.requestSubmit();
                return;
            }

            quickClientForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });
        quickClientForm?.addEventListener('submit', submitQuickClientForm);
        els.quickClientModal?.addEventListener('hidden.bs.modal', () => {
            if (quickClientForm instanceof HTMLFormElement) {
                quickClientForm.reset();
            }

            clearQuickClientErrors();
            setQuickClientSubmitState(false);
        });

        els.quickBrandSubmit?.addEventListener('click', async () => {
            const currentTypeId = getSelectedTypeId();

            if (currentTypeId === '') {
                showAlert('warning', 'Selecione o tipo', 'Escolha o tipo antes de cadastrar uma marca.');
                return;
            }

            const name = els.quickBrandName instanceof HTMLInputElement ? els.quickBrandName.value.trim() : '';
            if (name === '') {
                showAlert('warning', 'Informe a marca', 'Preencha o nome da marca antes de salvar.');
                return;
            }

            try {
                const response = await requestJson(config.routes.quickBrand, {
                    method: 'POST',
                    body: {
                        tipo_id: Number(currentTypeId),
                        nome: name,
                    },
                });
                state.brands.push(response.brand);
                const nextBrandId = String(response.brand.id);
                addTransientBrandForType(currentTypeId, nextBrandId);
                updateModelOptions({
                    selectedBrandId: nextBrandId,
                    selectedModelId: '',
                    selectedQuickBrandId: nextBrandId,
                    forceDesktopDefaults: false,
                });
                getModal(els.quickBrandModal)?.hide();
                if (els.quickBrandName instanceof HTMLInputElement) {
                    els.quickBrandName.value = '';
                }
                showToast('success', 'Marca cadastrada com sucesso.');
            } catch (error) {
                showAlert('error', 'Falha ao cadastrar marca', error.message);
            }
        });

        els.quickModelSuggest?.addEventListener('click', async () => {
            if (!hasSelectedType()) {
                showAlert('warning', 'Selecione o tipo', 'Escolha o tipo antes de buscar sugestões de modelo.');
                return;
            }

            const nome = els.quickModelName instanceof HTMLInputElement ? els.quickModelName.value.trim() : '';
            const brandText = els.quickModelBrand instanceof HTMLSelectElement ? els.quickModelBrand.selectedOptions[0]?.textContent?.trim() || '' : '';
            const typeText = els.type instanceof HTMLSelectElement ? els.type.selectedOptions[0]?.textContent?.trim() || '' : '';

            if (nome.length < 2) {
                showAlert('warning', 'Informe um nome base', 'Digite pelo menos duas letras para buscar sugestões.');
                return;
            }

            try {
                const response = await requestJson(`${config.routes.suggestModels}?nome=${encodeURIComponent(nome)}&marca_nome=${encodeURIComponent(brandText)}&tipo_nome=${encodeURIComponent(typeText)}`);
                const suggestions = response.suggestions || [];

                if (!Array.isArray(suggestions) || suggestions.length === 0) {
                    els.quickModelSuggestions.innerHTML = '<div class="equipment-search-empty">Nenhuma sugestão encontrada agora.</div>';
                    return;
                }

                els.quickModelSuggestions.innerHTML = suggestions.map((item) => `
                    <button type="button" class="equipment-suggestion-item" data-suggestion-name="${escapeHtml(item.nome)}">
                        <strong>${escapeHtml(item.nome)}</strong>
                        <span>Origem: ${escapeHtml(item.source || 'externa')}</span>
                    </button>
                `).join('');

                els.quickModelSuggestions.querySelectorAll('[data-suggestion-name]').forEach((button) => {
                    button.addEventListener('click', () => {
                        if (els.quickModelName instanceof HTMLInputElement) {
                            els.quickModelName.value = button.dataset.suggestionName || '';
                        }
                    });
                });
            } catch (error) {
                els.quickModelSuggestions.innerHTML = '<div class="equipment-search-empty">Falha ao consultar sugestões externas.</div>';
            }
        });

        els.quickModelSubmit?.addEventListener('click', async () => {
            const currentTypeId = getSelectedTypeId();

            if (currentTypeId === '') {
                showAlert('warning', 'Selecione o tipo', 'Escolha o tipo antes de cadastrar um modelo.');
                return;
            }

            const marcaId = els.quickModelBrand instanceof HTMLSelectElement ? els.quickModelBrand.value : '';
            const nome = els.quickModelName instanceof HTMLInputElement ? els.quickModelName.value.trim() : '';

            if (!marcaId || nome === '') {
                showAlert('warning', 'Campos obrigatórios', 'Selecione a marca e informe o nome do modelo.');
                return;
            }

            try {
                const response = await requestJson(config.routes.quickModel, {
                    method: 'POST',
                    body: {
                        tipo_id: Number(currentTypeId),
                        marca_id: Number(marcaId),
                        nome,
                    },
                });

                state.models.push(response.model);
                const brandId = String(response.model.marca_id);
                const modelId = String(response.model.id);

                addTransientBrandForType(currentTypeId, brandId);
                addTransientModelForTypeBrand(currentTypeId, brandId, modelId);

                updateModelOptions({
                    selectedBrandId: brandId,
                    selectedModelId: modelId,
                    selectedQuickBrandId: brandId,
                    forceDesktopDefaults: false,
                });
                getModal(els.quickModelModal)?.hide();
                if (els.quickModelName instanceof HTMLInputElement) {
                    els.quickModelName.value = '';
                }
                els.quickModelSuggestions.innerHTML = '';
                showToast('success', 'Modelo cadastrado com sucesso.');
            } catch (error) {
                showAlert('error', 'Falha ao cadastrar modelo', error.message);
            }
        });
    };

    const closeParentEquipmentModal = () => {
        try {
            if (window.parent && window.parent !== window) {
                const parentDocument = window.parent.document;
                const modalElement = parentDocument.getElementById('quickEquipmentModal');

                if (modalElement && window.parent.bootstrap && window.parent.bootstrap.Modal) {
                    const modal = window.parent.bootstrap.Modal.getInstance(modalElement)
                        || window.parent.bootstrap.Modal.getOrCreateInstance(modalElement);

                    modal.hide();
                    return true;
                }

                const dismissButton = parentDocument.querySelector('#quickEquipmentModal [data-bs-dismiss="modal"]');
                if (dismissButton instanceof HTMLElement) {
                    dismissButton.click();
                    return true;
                }
            }
        } catch (error) {
            console.warn('[equipments-create] Falha ao fechar o modal pai', error);
        }

        return false;
    };

    const initEmbeddedCancel = () => {
        if (!isEmbeddedMode) {
            return;
        }

        if (!(els.embeddedCancelButton instanceof HTMLButtonElement)) {
            return;
        }

        els.embeddedCancelButton.addEventListener('click', (event) => {
            event.preventDefault();
            closeParentEquipmentModal();
        });
    };

    const setPairingStatus = (text, status = 'idle') => {
        if (els.collectorStatus instanceof HTMLElement) {
            els.collectorStatus.textContent = text;
            els.collectorStatus.dataset.status = status;
        }
    };

    const buildPairingCommand = (prefix, code, token) => {
        const erpBaseUrl = String(config.formData?.collector?.erp_base_url || '').trim();
        const parts = [prefix, `--pairing-code=${code}`];

        if (erpBaseUrl !== '') {
            parts.push(`--erp-base-url=${erpBaseUrl}`);
        }

        if (token) {
            parts.push(`--collector-token=${token}`);
        }

        return parts.join(' ');
    };

    const showPairingCommandFor = (wrapperEl, codeEl, prefix, code, token) => {
        if (!(wrapperEl instanceof HTMLElement) || !(codeEl instanceof HTMLElement)) {
            return;
        }

        if (!code || !token) {
            wrapperEl.classList.add('d-none');
            return;
        }

        codeEl.textContent = buildPairingCommand(prefix, code, token);
        wrapperEl.classList.remove('d-none');
    };

    const showWindowsDownloadLink = (code, token) => {
        if (!(els.collectorDownloadWindows instanceof HTMLAnchorElement)) {
            return;
        }

        if (!code || !token || !config.routes?.downloadWindowsCollector) {
            els.collectorDownloadWindows.classList.add('d-none');
            els.collectorDownloadWindows.href = '#';
            return;
        }

        els.collectorDownloadWindows.href = config.routes.downloadWindowsCollector.replace('__CODE__', encodeURIComponent(code));
        els.collectorDownloadWindows.classList.remove('d-none');
    };

    const showPairingCommand = (code, token) => {
        showWindowsDownloadLink(code, token);
        showPairingCommandFor(
            els.collectorCommandWrapperWindows,
            els.collectorCommandCodeWindows,
            'powershell -ExecutionPolicy Bypass -File .\\jovemtech-bench-collector.ps1',
            code,
            token
        );
        showPairingCommandFor(
            els.collectorCommandWrapperLinux,
            els.collectorCommandCodeLinux,
            './jovemtech-bench-collector.sh',
            code,
            token
        );
    };

    const initCollector = () => {
        const ensureCompatibleType = () => {
            if (isCollectorCompatibleType()) {
                return true;
            }

            showAlert('warning', 'Tipo incompatível', 'Selecione um equipamento do tipo Desktop ou Notebook antes de gerar o código de pareamento.');
            return false;
        };

        els.collectorCreate?.addEventListener('click', async () => {
            if (!ensureCompatibleType()) {
                return;
            }

            setPairingStatus('Gerando código...', 'running');

            try {
                const response = await requestJson(config.routes.createPairing, { method: 'POST', body: {} });
                const pairing = response.pairing || {};
                const code = pairing.code || '';

                if (!code) {
                    throw new Error('O ERP não retornou um código de pareamento.');
                }

                state.collectorSnapshot = null;

                if (els.collectorDisplay instanceof HTMLElement) {
                    els.collectorDisplay.textContent = code;
                }

                if (els.collectorPairingCodeInput instanceof HTMLInputElement) {
                    els.collectorPairingCodeInput.value = code;
                }

                if (els.collectorImport instanceof HTMLButtonElement) {
                    els.collectorImport.disabled = true;
                }

                showPairingCommand(code, pairing.submission_token || '');
                setPairingStatus('Aguardando envio do cliente...', 'waiting');
                pollCollectorPairing(code);
            } catch (error) {
                setPairingStatus('Falha ao gerar código', 'error');
                showAlert('error', 'Falha ao gerar código de pareamento', error.message);
            }
        });

        const copyCommand = async (codeEl) => {
            const text = codeEl?.textContent || '';
            if (!text) {
                return;
            }

            try {
                await navigator.clipboard.writeText(text);
                showToast('success', 'Comando copiado');
            } catch (error) {
                showAlert('error', 'Não foi possível copiar', 'Selecione e copie o comando manualmente.');
            }
        };

        els.collectorCommandCopyWindows?.addEventListener('click', () => copyCommand(els.collectorCommandCodeWindows));
        els.collectorCommandCopyLinux?.addEventListener('click', () => copyCommand(els.collectorCommandCodeLinux));

        els.collectorImport?.addEventListener('click', () => {
            if (!state.collectorSnapshot) {
                showAlert('warning', 'Nenhum snapshot recebido', 'Aguarde o coletor na máquina do cliente enviar os dados antes de importar.');
                return;
            }

            state.isApplyingSnapshot = false;
            applySnapshotToForm(state.collectorSnapshot);
            setPairingStatus('Snapshot importado', 'snapshot');
        });
    };

    const initFillButtons = () => {
        els.fillButtons.forEach((button) => {
            button.addEventListener('click', () => appendTextValue(button.dataset.fillTarget || '', button.dataset.fillValue || ''));
        });
    };

    initTabs();
    initClients();
    initCatalogs();
    initPassword();
    initColors();
    initPhotos();
    initFormSubmission();
    initQuickAdd();
    initEmbeddedCancel();
    initCollector();
    initFillButtons();
})();
