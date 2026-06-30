(function () {
    const config = window.__DESKTOP_ORDER_CREATE || {};
    const form = document.querySelector('[data-order-create-form]');

    if (!(form instanceof HTMLFormElement) && !config.quickClientStoreUrl) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const hasWizardForm = form instanceof HTMLFormElement;
    const maxPhotos = Math.max(1, Number(config.maxPhotos || 4));
    const clientSearchUrl = String(config.clientSearchUrl || '').trim();
    const equipmentSearchUrl = String(config.equipmentSearchUrl || '').trim();
    const reportedDefectsSearchUrl = String(config.reportedDefectsSearchUrl || '').trim();

    const summarySelectors = config.summarySelectors || {};

    const els = {
        tabs: Array.from(document.querySelectorAll('[data-order-tab]')),
        panels: Array.from(document.querySelectorAll('[data-order-tab-panel]')),
        clientSelect: document.getElementById(config.clientSelectId || 'clienteId'),
        equipmentSelect: document.getElementById(config.equipmentSelectId || 'equipamentoId'),
        technicianSelect: document.getElementById(config.technicianSelectId || 'tecnicoId'),
        prioritySelect: document.getElementById('prioridade'),
        relatoField: document.getElementById('relatoCliente'),
        defectSuggestions: document.querySelector('[data-order-create-defect-suggestions]'),
        observacoesField: document.getElementById('observacoesInternas'),
        previsaoField: document.getElementById('dataPrevisao'),
        prazoEntregaSelect: document.getElementById('prazoEntrega'),
        photosInput: document.getElementById(config.photosInputId || 'orderPhotos'),
        photosPickButton: document.querySelector(config.photosPickButtonSelector || '[data-order-create-photos-pick]'),
        photosClearButton: document.querySelector(config.photosClearButtonSelector || '[data-order-create-photos-clear]'),
        photosPreview: document.querySelector(config.photosPreviewSelector || '[data-order-create-photos-preview]'),
        mainPhoto: document.querySelector(config.mainPhotoSelector || '[data-order-create-main-photo]'),
        mainPhotoPlaceholder: document.querySelector(config.mainPhotoPlaceholderSelector || '[data-order-create-main-photo-placeholder]'),
        mainPhotoFrame: document.querySelector('[data-order-create-photo-frame]'),
        summaryStatus: document.querySelector(summarySelectors.status || '[data-order-create-summary-status]'),
        summaryClient: document.querySelector(summarySelectors.client || '[data-order-create-summary-client]'),
        summaryClientIcon: document.querySelector('[data-order-create-summary-client-icon]'),
        summaryEquipment: document.querySelector(summarySelectors.equipment || '[data-order-create-summary-equipment]'),
        summaryEquipmentIcon: document.querySelector('[data-order-create-summary-equipment-icon]'),
        summaryTechnician: document.querySelector(summarySelectors.technician || '[data-order-create-summary-technician]'),
        summaryTechnicianIcon: document.querySelector('[data-order-create-summary-technician-icon]'),
        summaryPriority: document.querySelector(summarySelectors.priority || '[data-order-create-summary-priority]'),
        summaryPrevisao: document.querySelector(summarySelectors.previsao || '[data-order-create-summary-previsao]'),
        summaryPrevisaoIcon: document.querySelector('[data-order-create-summary-previsao-icon]'),
        summaryRelato: document.querySelector(summarySelectors.relato || '[data-order-create-summary-relato]'),
        summaryRelatoIcon: document.querySelector('[data-order-create-summary-relato-icon]'),
        summaryPhotos: document.querySelector(summarySelectors.photos || '[data-order-create-summary-photos]'),
        summaryPhotosIcon: document.querySelector('[data-order-create-summary-photos-icon]'),
        clientEditButton: document.querySelector('[data-order-create-client-edit-link]'),
        equipmentEditButton: document.querySelector('[data-order-create-equipment-edit-link]'),
        quickClientModal: document.getElementById('quickClientModal'),
        quickClientForm: document.getElementById('quickClientForm'),
        quickClientSubmit: document.getElementById('quickClientSubmit'),
        quickClientButton: document.getElementById('btnNovoClienteRapido'),
        quickEquipmentModal: document.getElementById('quickEquipmentModal'),
        quickEquipmentFrame: document.querySelector('[data-order-create-equipment-frame]'),
    };

    const state = {
        clientCache: new Map(),
        equipmentCache: new Map(),
        photoEntries: [],
        existingPhotosCount: Math.max(0, Number(config.existingPhotosCount || 0)),
    };

    const select2Language = {
        errorLoading: () => 'Os resultados nao puderam ser carregados.',
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
        loadingMore: () => 'Carregando mais resultados...',
        maximumSelected: (args) => {
            const suffix = args.maximum === 1 ? 'item' : 'itens';
            return `Voce so pode selecionar ${args.maximum} ${suffix}`;
        },
        noResults: () => 'Nenhum resultado encontrado',
        searching: () => 'Buscando...',
        removeAllItems: () => 'Remover todos os itens',
    };

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

    const getSelect2DropdownParent = (select, $) => {
        if (typeof $ !== 'function') {
            return null;
        }

        const body = $(document.body);
        if (!(select instanceof HTMLElement)) {
            return body;
        }

        const modal = select.closest('.modal');
        if (modal) {
            return $(modal);
        }

        const offcanvas = select.closest('.offcanvas');
        if (offcanvas) {
            return $(offcanvas);
        }

        return body;
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

    const refreshSelect2 = (element) => {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        if (window.DesktopUi && typeof window.DesktopUi.refreshSelect2 === 'function') {
            window.DesktopUi.refreshSelect2(element);
        }
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

            if (window.DesktopUi && typeof window.DesktopUi.logError === 'function') {
                window.DesktopUi.logError('orders-create.requestJson', error, {
                    path: String(url).split('?')[0],
                    method,
                });
            }

            throw error;
        }

        return payload;
    };

    const normalizeText = (value) => String(value ?? '').trim();

    const formatDate = (value) => {
        const normalized = normalizeText(value);
        if (normalized === '') {
            return 'Nao informada';
        }

        const date = new Date(`${normalized}T12:00:00`);
        if (Number.isNaN(date.getTime())) {
            return normalized;
        }

        return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(date);
    };

    const toDateInputValue = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    const handlePrazoEntregaChange = () => {
        if (!(els.prazoEntregaSelect instanceof HTMLSelectElement) || !(els.previsaoField instanceof HTMLInputElement)) {
            return;
        }

        const days = Number(els.prazoEntregaSelect.value || 0);
        if (!Number.isInteger(days) || days <= 0) {
            return;
        }

        const target = new Date();
        target.setDate(target.getDate() + days);
        els.previsaoField.value = toDateInputValue(target);
        updateSummary();
    };

    const joinEquipmentLabelParts = (...parts) => parts
        .map((part) => normalizeText(part))
        .filter((part) => part !== '')
        .join(' / ');

    const buildEquipmentLabel = (equipment) => {
        const summary = normalizeText(equipment?.summary || '');
        if (summary !== '') {
            return summary;
        }

        const brandModel = joinEquipmentLabelParts(
            equipment?.brandName || equipment?.marca_nome || '',
            equipment?.modelName || equipment?.modelo_nome || '',
        );
        if (brandModel !== '') {
            return brandModel;
        }

        const fallbackText = normalizeText(equipment?.text || equipment?.label || '');
        if (fallbackText !== '') {
            return fallbackText;
        }

        const equipmentId = Number(equipment?.id || 0) || 0;
        return equipmentId > 0 ? `Equipamento #${equipmentId}` : '';
    };

    const buildEquipmentMeta = (equipment) => {
        const summary = normalizeText(equipment?.summary || '');
        const brandModel = joinEquipmentLabelParts(
            equipment?.brandName || equipment?.marca_nome || '',
            equipment?.modelName || equipment?.modelo_nome || '',
        );
        const serial = normalizeText(equipment?.serial || '');
        const meta = [];

        if (summary !== '' && brandModel !== '' && brandModel !== summary) {
            meta.push(brandModel);
        }

        if (serial !== '') {
            meta.push(`S/N ${serial}`);
        }

        return meta;
    };

    const getSelectedOption = (select) => {
        if (!(select instanceof HTMLSelectElement)) {
            return null;
        }

        return select.selectedOptions?.[0] || null;
    };

    const getClientData = () => {
        const selected = getSelectedOption(els.clientSelect);
        const cachedRecord = state.clientCache.get(String(els.clientSelect instanceof HTMLSelectElement ? els.clientSelect.value : '')) || null;
        const record = getClientRecordFromOption(selected) || cachedRecord;
        const name = normalizeText(record?.name || selected?.textContent || '');

        return {
            id: Number(els.clientSelect instanceof HTMLSelectElement ? els.clientSelect.value : 0) || 0,
            name: name !== '' ? name : 'Nao selecionado',
            phone: normalizeText(record?.phone || selected?.dataset.clientPhone || ''),
            email: normalizeText(record?.email || selected?.dataset.clientEmail || ''),
            contact: normalizeText(record?.contact || selected?.dataset.clientContact || ''),
            city: normalizeText(record?.city || selected?.dataset.clientCity || ''),
            uf: normalizeText(record?.uf || selected?.dataset.clientUf || ''),
        };
    };

    const getClientRecordFromOption = (option) => {
        if (!(option instanceof HTMLOptionElement) || option.value === '') {
            return null;
        }

        return {
            id: String(option.value || ''),
            name: normalizeText(option.dataset.clientName || option.textContent || ''),
            phone: normalizeText(option.dataset.clientPhone || ''),
            email: normalizeText(option.dataset.clientEmail || ''),
            contact: normalizeText(option.dataset.clientContact || ''),
            city: normalizeText(option.dataset.clientCity || ''),
            uf: normalizeText(option.dataset.clientUf || ''),
        };
    };

    const rememberClientRecord = (client, ensureOption = false) => {
        const clientId = String(client?.id || '');
        if (clientId === '') {
            return null;
        }

        const record = {
            id: clientId,
            name: normalizeText(client?.nome_razao || client?.name || client?.text || `Cliente #${clientId}`),
            phone: normalizeText(client?.telefone1 || client?.phone || ''),
            email: normalizeText(client?.email || ''),
            contact: normalizeText(client?.nome_contato || client?.contact || ''),
            city: normalizeText(client?.cidade || client?.city || ''),
            uf: normalizeText(client?.uf || ''),
        };

        state.clientCache.set(clientId, record);

        if (ensureOption && els.clientSelect instanceof HTMLSelectElement) {
            let option = Array.from(els.clientSelect.options).find((item) => item.value === clientId) || null;

            if (!(option instanceof HTMLOptionElement)) {
                option = document.createElement('option');
                option.value = clientId;
                els.clientSelect.appendChild(option);
            }

            option.textContent = record.name;
            option.dataset.clientName = record.name;
            option.dataset.clientPhone = record.phone;
            option.dataset.clientEmail = record.email;
            option.dataset.clientContact = record.contact;
            option.dataset.clientCity = record.city;
            option.dataset.clientUf = record.uf;
        }

        return record;
    };

    const getEquipmentRecordFromOption = (option) => {
        if (!(option instanceof HTMLOptionElement) || option.value === '') {
            return null;
        }

        const equipmentId = String(option.value || '');
        const summary = normalizeText(option.dataset.equipmentSummary || '');
        const brandName = normalizeText(option.dataset.equipmentBrandName || '');
        const modelName = normalizeText(option.dataset.equipmentModelName || '');

        return {
            id: equipmentId,
            label: buildEquipmentLabel({
                id: equipmentId,
                summary,
                brandName,
                modelName,
                text: option.textContent || '',
            }),
            summary,
            brandName,
            modelName,
            serial: normalizeText(option.dataset.equipmentSerial || ''),
            photoUrl: normalizeText(option.dataset.equipmentPhotoUrl || ''),
            clientId: Number(option.dataset.clientId || 0) || 0,
            clientName: normalizeText(option.dataset.clientName || ''),
            tipoId: Number(option.dataset.equipmentTipoId || 0) || 0,
            tipoName: normalizeText(option.dataset.equipmentTipoName || ''),
        };
    };

    const rememberEquipmentRecord = (equipment, ensureOption = false) => {
        const equipmentId = String(equipment?.id || '');
        if (equipmentId === '') {
            return null;
        }

        const record = {
            id: equipmentId,
            label: buildEquipmentLabel(equipment),
            summary: normalizeText(equipment?.summary || ''),
            brandName: normalizeText(equipment?.brandName || equipment?.marca_nome || equipment?.brand_name || ''),
            modelName: normalizeText(equipment?.modelName || equipment?.modelo_nome || equipment?.model_name || ''),
            serial: normalizeText(equipment?.serial || ''),
            photoUrl: normalizeText(equipment?.photoUrl || ''),
            clientId: Number(equipment?.clientId ?? equipment?.cliente_id ?? 0) || 0,
            clientName: normalizeText(equipment?.clientName || equipment?.cliente_nome || ''),
            tipoId: Number(equipment?.tipoId ?? equipment?.tipo_id ?? 0) || 0,
            tipoName: normalizeText(equipment?.tipoName || equipment?.tipo_nome || ''),
        };

        state.equipmentCache.set(equipmentId, record);

        if (ensureOption && els.equipmentSelect instanceof HTMLSelectElement) {
            let option = Array.from(els.equipmentSelect.options).find((item) => item.value === equipmentId) || null;

            if (!(option instanceof HTMLOptionElement)) {
                option = document.createElement('option');
                option.value = equipmentId;
                els.equipmentSelect.appendChild(option);
            }

            option.textContent = record.label;
            option.dataset.clientId = String(record.clientId || 0);
            option.dataset.clientName = record.clientName;
            option.dataset.equipmentSummary = record.summary;
            option.dataset.equipmentBrandName = record.brandName;
            option.dataset.equipmentModelName = record.modelName;
            option.dataset.equipmentSerial = record.serial;
            option.dataset.equipmentPhotoUrl = record.photoUrl;
            option.dataset.equipmentTipoId = String(record.tipoId || 0);
            option.dataset.equipmentTipoName = record.tipoName;
        }

        return record;
    };

    const getEquipmentData = () => {
        const selected = getSelectedOption(els.equipmentSelect);
        const optionRecord = getEquipmentRecordFromOption(selected);
        const cachedRecord = state.equipmentCache.get(String(els.equipmentSelect?.value || '')) || null;
        const record = {
            id: optionRecord?.id || cachedRecord?.id || '',
            label: normalizeText(optionRecord?.label || cachedRecord?.label || ''),
            summary: normalizeText(optionRecord?.summary || cachedRecord?.summary || ''),
            brandName: normalizeText(optionRecord?.brandName || cachedRecord?.brandName || ''),
            modelName: normalizeText(optionRecord?.modelName || cachedRecord?.modelName || ''),
            serial: normalizeText(optionRecord?.serial || cachedRecord?.serial || ''),
            photoUrl: normalizeText(optionRecord?.photoUrl || cachedRecord?.photoUrl || ''),
            clientId: Number(optionRecord?.clientId || cachedRecord?.clientId || 0) || 0,
            clientName: normalizeText(optionRecord?.clientName || cachedRecord?.clientName || ''),
            tipoId: Number(optionRecord?.tipoId || cachedRecord?.tipoId || 0) || 0,
            tipoName: normalizeText(optionRecord?.tipoName || cachedRecord?.tipoName || ''),
        };
        const name = normalizeText(buildEquipmentLabel({
            ...record,
            text: selected?.textContent || '',
        }));

        return {
            id: Number(els.equipmentSelect instanceof HTMLSelectElement ? els.equipmentSelect.value : 0) || 0,
            name: name !== '' ? name : 'Nao selecionado',
            serial: normalizeText(record?.serial || ''),
            photoUrl: normalizeText(record?.photoUrl || ''),
            clientId: Number(record?.clientId || 0) || 0,
            clientName: normalizeText(record?.clientName || ''),
            brandName: normalizeText(record?.brandName || ''),
            modelName: normalizeText(record?.modelName || ''),
            tipoId: Number(record?.tipoId || 0) || 0,
            tipoName: normalizeText(record?.tipoName || ''),
        };
    };

    const getTechnicianData = () => {
        const selected = getSelectedOption(els.technicianSelect);

        return {
            id: Number(els.technicianSelect instanceof HTMLSelectElement ? els.technicianSelect.value : 0) || 0,
            name: normalizeText(selected?.textContent || 'Nao atribuido') || 'Nao atribuido',
            email: normalizeText(selected?.dataset.technicianEmail || ''),
        };
    };

    const setText = (element, value) => {
        if (element instanceof HTMLElement) {
            element.textContent = value;
        }
    };

    const setSummaryIcon = (iconEl, isComplete) => {
        if (!(iconEl instanceof HTMLElement)) {
            return;
        }

        iconEl.classList.toggle('is-complete', isComplete);
        iconEl.classList.toggle('is-pending', !isComplete);
        iconEl.classList.toggle('bi-check-circle-fill', isComplete);
        iconEl.classList.toggle('bi-x-circle-fill', !isComplete);
    };

    const setRowTitle = (textEl, title) => {
        const row = textEl instanceof HTMLElement ? textEl.closest('.order-create-summary-row') : null;

        if (row instanceof HTMLElement) {
            row.title = title;
        }
    };

    const setMainPhoto = (photoUrl, label = 'Foto do equipamento selecionado') => {
        if (!(els.mainPhoto instanceof HTMLImageElement) || !(els.mainPhotoPlaceholder instanceof HTMLElement)) {
            return;
        }

        const url = normalizeText(photoUrl);
        if (url === '') {
            els.mainPhoto.removeAttribute('src');
            els.mainPhoto.classList.add('d-none');
            els.mainPhoto.alt = label;
            els.mainPhotoPlaceholder.classList.remove('d-none');
            els.mainPhotoFrame?.classList.remove('has-photo');
            return;
        }

        els.mainPhoto.alt = label;
        els.mainPhoto.src = url;
        els.mainPhoto.classList.remove('d-none');
        els.mainPhotoPlaceholder.classList.add('d-none');
        els.mainPhotoFrame?.classList.add('has-photo');
    };

    if (els.mainPhoto instanceof HTMLImageElement) {
        els.mainPhoto.addEventListener('error', () => setMainPhoto('', 'Foto indisponível'));
    }

    const syncClientOptions = (client) => {
        const record = rememberClientRecord(client, true);

        if (!(els.clientSelect instanceof HTMLSelectElement) || !record) {
            return null;
        }

        return record;
    };

    const updateClientEditAction = (client) => {
        if (!(els.clientEditButton instanceof HTMLAnchorElement)) {
            return;
        }

        const clientId = Number(client?.id || 0) || 0;
        const template = String(els.clientEditButton.dataset.clientEditUrlTemplate || '').trim();

        if (clientId <= 0 || template === '') {
            els.clientEditButton.classList.add('d-none');
            els.clientEditButton.setAttribute('aria-disabled', 'true');
            els.clientEditButton.setAttribute('tabindex', '-1');
            els.clientEditButton.setAttribute('href', '#');
            return;
        }

        els.clientEditButton.href = template.replace('__CLIENT_ID__', String(clientId));
        els.clientEditButton.classList.remove('d-none');
        els.clientEditButton.removeAttribute('aria-disabled');
        els.clientEditButton.removeAttribute('tabindex');
    };

    const updateEquipmentEditAction = (equipment) => {
        if (!(els.equipmentEditButton instanceof HTMLAnchorElement)) {
            return;
        }

        const equipmentId = Number(equipment?.id || 0) || 0;
        const template = String(els.equipmentEditButton.dataset.equipmentEditUrlTemplate || '').trim();

        if (equipmentId <= 0 || template === '') {
            els.equipmentEditButton.classList.add('d-none');
            els.equipmentEditButton.setAttribute('aria-disabled', 'true');
            els.equipmentEditButton.setAttribute('tabindex', '-1');
            els.equipmentEditButton.setAttribute('href', '#');
            return;
        }

        els.equipmentEditButton.href = template.replace('__EQUIPMENT_ID__', String(equipmentId));
        els.equipmentEditButton.classList.remove('d-none');
        els.equipmentEditButton.removeAttribute('aria-disabled');
        els.equipmentEditButton.removeAttribute('tabindex');
    };

    const getEquipmentCreateUrl = () => {
        if (!(els.quickEquipmentModal instanceof HTMLElement)) {
            return '';
        }

        const baseUrl = String(els.quickEquipmentModal.dataset.orderCreateEquipmentCreateUrl || '').trim();
        if (baseUrl === '') {
            return '';
        }

        const url = new URL(baseUrl, window.location.origin);
        const client = getClientData();
        url.searchParams.set('embedded', '1');

        if (client.id > 0) {
            url.searchParams.set('cliente_id', String(client.id));

            if (client.name !== '' && client.name !== 'Nao selecionado') {
                url.searchParams.set('cliente_busca_label', client.name);
            }
        }

        return url.toString();
    };

    const syncEquipmentCreateFrame = () => {
        if (!(els.quickEquipmentFrame instanceof HTMLIFrameElement)) {
            return;
        }

        const url = getEquipmentCreateUrl();
        els.quickEquipmentFrame.src = url !== '' ? url : 'about:blank';
    };

    const resetEquipmentCreateFrame = () => {
        if (els.quickEquipmentFrame instanceof HTMLIFrameElement) {
            els.quickEquipmentFrame.src = 'about:blank';
        }
    };

    const renderClientTemplate = (client) => {
        if (!client || client.loading) {
            return escapeHtml(client?.text || client?.name || '');
        }

        const title = escapeHtml(normalizeText(client.name || client.text || `Cliente #${client.id}`));
        const meta = [
            normalizeText(client.phone || ''),
            normalizeText(client.email || ''),
            client.contact ? `Contato: ${normalizeText(client.contact)}` : '',
            client.city || client.uf ? [client.city, client.uf].filter(Boolean).join(' / ') : '',
        ].filter(Boolean);

        return `
            <div class="d-flex flex-column py-1">
                <strong>${title}</strong>
                ${meta.length > 0 ? `<small class="text-secondary">${escapeHtml(meta.join(' / '))}</small>` : ''}
            </div>
        `;
    };

    const setClientSelectValue = (clientId) => {
        if (!(els.clientSelect instanceof HTMLSelectElement)) {
            return;
        }

        const value = String(clientId || '');
        els.clientSelect.value = value;

        if (typeof window.jQuery !== 'undefined'
            && window.jQuery.fn
            && typeof window.jQuery.fn.select2 === 'function'
            && Boolean(window.jQuery(els.clientSelect).data('select2'))) {
            window.jQuery(els.clientSelect).val(value).trigger('change.select2');
        }
    };

    const setEquipmentSelectValue = (equipmentId) => {
        if (!(els.equipmentSelect instanceof HTMLSelectElement)) {
            return;
        }

        const value = String(equipmentId || '');
        els.equipmentSelect.value = value;

        if (typeof window.jQuery !== 'undefined'
            && window.jQuery.fn
            && typeof window.jQuery.fn.select2 === 'function'
            && Boolean(window.jQuery(els.equipmentSelect).data('select2'))) {
            window.jQuery(els.equipmentSelect).val(value).trigger('change.select2');
        }
    };

    const initClientSelect = () => {
        if (!(els.clientSelect instanceof HTMLSelectElement) || clientSearchUrl === '') {
            return;
        }

        if (typeof window.jQuery === 'undefined' || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') {
            return;
        }

        const $ = window.jQuery;

        if ($(els.clientSelect).data('select2')) {
            els.clientSelect.dataset.select2Ready = '1';
            return;
        }

        const placeholder = els.clientSelect.dataset.select2Placeholder || 'Selecione o cliente';

        $(els.clientSelect).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder,
            allowClear: true,
            dropdownParent: getSelect2DropdownParent(els.clientSelect, $),
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
                    const results = clients.map((item) => {
                        const record = rememberClientRecord({
                            id: item?.id,
                            nome_razao: item?.text || item?.name || '',
                            telefone1: item?.phone || '',
                            email: item?.email || '',
                            nome_contato: item?.contact || '',
                            cidade: item?.city || '',
                            uf: item?.uf || '',
                        }) || null;

                        return {
                            id: String(item?.id || record?.id || ''),
                            text: normalizeText(item?.text || record?.name || ''),
                            name: normalizeText(item?.name || record?.name || item?.text || ''),
                            phone: normalizeText(item?.phone || record?.phone || ''),
                            email: normalizeText(item?.email || record?.email || ''),
                            contact: normalizeText(item?.contact || record?.contact || ''),
                            city: normalizeText(item?.city || record?.city || ''),
                            uf: normalizeText(item?.uf || record?.uf || ''),
                        };
                    });

                    return {
                        results,
                        pagination: {
                            more: Number(data?.pagination?.current_page || page) < Number(data?.pagination?.last_page || page),
                        },
                    };
                },
            },
        });

        els.clientSelect.dataset.select2Ready = '1';

        const selectedRecord = getClientRecordFromOption(getSelectedOption(els.clientSelect));
        if (selectedRecord) {
            rememberClientRecord(selectedRecord, true);
        }
    };

    const updateSummary = () => {
        const client = getClientData();
        const equipment = getEquipmentData();
        const technician = getTechnicianData();
        const priority = normalizeText(els.prioritySelect instanceof HTMLSelectElement
            ? els.prioritySelect.selectedOptions?.[0]?.textContent
            : '') || 'Normal';
        const relato = normalizeText(els.relatoField instanceof HTMLTextAreaElement ? els.relatoField.value : '');
        const observacoes = normalizeText(els.observacoesField instanceof HTMLTextAreaElement ? els.observacoesField.value : '');
        const previsao = normalizeText(els.previsaoField instanceof HTMLInputElement ? els.previsaoField.value : '');
        const photoCount = state.existingPhotosCount + state.photoEntries.length;
        const isReady = client.id > 0 && equipment.id > 0 && relato.length >= 5;

        if (!config.lockStatus) {
            setText(els.summaryStatus, isReady ? 'Pronto para abrir' : 'Triagem em andamento');
        }

        setText(els.summaryClient, client.name);
        setRowTitle(els.summaryClient, [client.phone, client.email].filter(Boolean).join(' · '));
        setSummaryIcon(els.summaryClientIcon, client.id > 0);
        setText(els.summaryEquipment, equipment.name);
        setRowTitle(els.summaryEquipment, equipment.serial !== '' ? `S/N ${equipment.serial}` : '');
        setSummaryIcon(els.summaryEquipmentIcon, equipment.id > 0);
        setText(els.summaryTechnician, technician.name);
        setRowTitle(els.summaryTechnician, technician.email);
        setSummaryIcon(els.summaryTechnicianIcon, technician.id > 0);
        setText(els.summaryPriority, priority);
        setText(els.summaryPrevisao, formatDate(previsao));
        setSummaryIcon(els.summaryPrevisaoIcon, previsao !== '');
        setText(els.summaryRelato, relato !== '' ? (relato.length > 28 ? `${relato.slice(0, 28)}...` : relato) : 'Vazio');
        setRowTitle(els.summaryRelato, relato);
        setSummaryIcon(els.summaryRelatoIcon, relato !== '');
        setText(els.summaryPhotos, String(photoCount));
        setSummaryIcon(els.summaryPhotosIcon, photoCount > 0);
        updateEquipmentEditAction(equipment);
        updateClientEditAction(client);

        if (observacoes !== '' && els.summaryStatus instanceof HTMLElement) {
            els.summaryStatus.title = observacoes;
        }
    };

    const setActiveTab = (name) => {
        els.tabs.forEach((button) => {
            const active = button.dataset.orderTab === name;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        els.panels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.orderTabPanel === name);
        });
    };

    const initTabs = () => {
        if (!hasWizardForm) {
            return;
        }

        if (els.tabs.length === 0 || els.panels.length === 0) {
            return;
        }

        els.tabs.forEach((button) => {
            button.addEventListener('click', () => {
                setActiveTab(button.dataset.orderTab || '');
            });
        });

        const activeTab = els.tabs.find((button) => button.classList.contains('is-active'))?.dataset.orderTab
            || els.tabs[0]?.dataset.orderTab
            || '';

        if (activeTab !== '') {
            setActiveTab(activeTab);
        }
    };

    const handleClientChange = () => {
        clearEquipmentSelection();
    };

    const syncClientSelectionFromSelect2 = (event) => {
        const payload = event?.params?.data || null;
        if (!payload || payload.loading) {
            return;
        }

        rememberClientRecord({
            id: payload.id,
            nome_razao: payload.name || payload.text || '',
            telefone1: payload.phone || '',
            email: payload.email || '',
            nome_contato: payload.contact || '',
            cidade: payload.city || '',
            uf: payload.uf || '',
        }, true);

        updateSummary();
    };

    const handleEquipmentChange = () => {
        if (!(els.equipmentSelect instanceof HTMLSelectElement)) {
            return;
        }

        const currentEquipment = getEquipmentData();
        if (currentEquipment.id > 0) {
            rememberEquipmentRecord(currentEquipment, true);
        }

        if (currentEquipment.id > 0 && currentEquipment.clientId > 0 && els.clientSelect instanceof HTMLSelectElement) {
            const currentClientId = Number(els.clientSelect.value || 0) || 0;
            if (currentClientId !== currentEquipment.clientId) {
                syncClientOptions({
                    id: currentEquipment.clientId,
                    nome_razao: currentEquipment.clientName || `Cliente #${currentEquipment.clientId}`,
                });
                setClientSelectValue(currentEquipment.clientId);
            }
        }

        setMainPhoto(currentEquipment.photoUrl || '', currentEquipment.name || 'Foto do equipamento selecionado');
        updateSummary();
        loadDefectSuggestions(currentEquipment.tipoId || 0);
    };

    const groupDefectsByCategory = (defects) => {
        const categories = new Map();

        defects
            .slice()
            .sort((a, b) => (a.ordem_exibicao || 0) - (b.ordem_exibicao || 0))
            .forEach((defect) => {
                const texto = normalizeText(defect?.texto_relato || '');
                if (texto === '') {
                    return;
                }

                const categoriaNome = normalizeText(defect?.categoria || '') || 'Outros';
                const subcategoriaNome = normalizeText(defect?.subcategoria || '') || 'Geral';
                const icone = normalizeText(defect?.icone || '') || 'bi-card-text';

                if (!categories.has(categoriaNome)) {
                    categories.set(categoriaNome, { icone, subcategorias: new Map() });
                }

                const categoria = categories.get(categoriaNome);
                if (!categoria.subcategorias.has(subcategoriaNome)) {
                    categoria.subcategorias.set(subcategoriaNome, []);
                }

                categoria.subcategorias.get(subcategoriaNome).push(texto);
            });

        return categories;
    };

    const renderDefectSuggestionsEmpty = (message) => {
        if (!(els.defectSuggestions instanceof HTMLElement)) {
            return;
        }

        els.defectSuggestions.innerHTML = `<span class="text-secondary small">${escapeHtml(message)}</span>`;
    };

    const renderDefectSuggestions = (defects) => {
        if (!(els.defectSuggestions instanceof HTMLElement)) {
            return;
        }

        if (!Array.isArray(defects) || defects.length === 0) {
            renderDefectSuggestionsEmpty('Nenhum defeito cadastrado para este tipo.');
            return;
        }

        const categories = groupDefectsByCategory(defects);
        if (categories.size === 0) {
            renderDefectSuggestionsEmpty('Nenhum defeito cadastrado para este tipo.');
            return;
        }

        const html = Array.from(categories.entries()).map(([categoriaNome, categoria]) => {
            const menuHtml = Array.from(categoria.subcategorias.entries()).map(([subcategoriaNome, itens]) => {
                const itensHtml = itens.map((texto) => `
                    <li>
                        <button type="button" class="dropdown-item" data-defect-text="${escapeHtml(texto)}">
                            ${escapeHtml(texto)}
                        </button>
                    </li>
                `).join('');

                return `
                    <li><h6 class="dropdown-header text-uppercase small text-secondary mb-0">${escapeHtml(subcategoriaNome)}</h6></li>
                    ${itensHtml}
                `;
            }).join('');

            return `
                <div class="dropdown">
                    <button type="button" class="btn btn-soft btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi ${escapeHtml(categoria.icone)} me-1"></i>${escapeHtml(categoriaNome)}
                    </button>
                    <ul class="dropdown-menu">
                        ${menuHtml}
                    </ul>
                </div>
            `;
        }).join('');

        els.defectSuggestions.innerHTML = html;
    };

    const loadDefectSuggestions = (tipoId) => {
        if (!(els.defectSuggestions instanceof HTMLElement)) {
            return;
        }

        const id = Number(tipoId || 0) || 0;
        if (id <= 0) {
            renderDefectSuggestionsEmpty('Selecione o equipamento para ver sugestões de defeitos.');
            return;
        }

        if (reportedDefectsSearchUrl === '') {
            return;
        }

        renderDefectSuggestionsEmpty('Carregando sugestões...');

        fetch(`${reportedDefectsSearchUrl}?tipo_equipamento_id=${id}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response)))
            .then((data) => {
                renderDefectSuggestions(Array.isArray(data?.defects) ? data.defects : []);
            })
            .catch(() => {
                renderDefectSuggestionsEmpty('Nao foi possivel carregar as sugestoes de defeitos.');
            });
    };

    const handleEmbeddedEquipmentCreated = (event) => {
        if (!els.quickEquipmentModal || !(els.quickEquipmentFrame instanceof HTMLIFrameElement)) {
            return;
        }

        if (event.origin !== window.location.origin) {
            return;
        }

        if (event.source !== els.quickEquipmentFrame.contentWindow) {
            return;
        }

        const payload = event.data || {};
        if (payload.type !== 'equipment-created') {
            return;
        }

        const equipment = payload.equipment || {};
        const equipmentId = Number(equipment.id || 0) || 0;
        if (equipmentId <= 0) {
            return;
        }

        rememberEquipmentRecord(equipment, true);
        setEquipmentSelectValue(equipmentId);
        handleEquipmentChange();
        getModal(els.quickEquipmentModal)?.hide();

        const equipmentLabel = normalizeText(buildEquipmentLabel({
            ...equipment,
            text: equipment?.label || equipment?.summary || equipment?.brandName || equipment?.modelName || '',
        }));
        const toastMessage = equipmentLabel !== ''
            ? `Equipamento cadastrado e selecionado: ${equipmentLabel}`
            : (payload.message || 'Equipamento cadastrado e selecionado.');

        showToast('success', toastMessage);
    };

    const clearEquipmentSelection = () => {
        if (!(els.equipmentSelect instanceof HTMLSelectElement)) {
            return;
        }

        const hasSelect2 = typeof window.jQuery !== 'undefined'
            && window.jQuery.fn
            && typeof window.jQuery.fn.select2 === 'function'
            && Boolean(window.jQuery(els.equipmentSelect).data('select2'));

        els.equipmentSelect.value = '';

        if (hasSelect2) {
            window.jQuery(els.equipmentSelect).val(null).trigger('change.select2');
        }

        setMainPhoto('', 'Foto do equipamento selecionado');
        updateSummary();
    };

    const renderEquipmentTemplate = (equipment) => {
        if (!equipment || equipment.loading) {
            return escapeHtml(equipment?.text || equipment?.label || '');
        }

        const title = escapeHtml(buildEquipmentLabel(equipment) || `Equipamento #${equipment.id}`);
        const meta = buildEquipmentMeta(equipment);
        const photoUrl = normalizeText(equipment.photoUrl || '');
        const photoAlt = buildEquipmentLabel(equipment) || 'Equipamento';

        return `
            <div class="order-create-equipment-result">
                <span class="equipment-list-photo-link order-create-equipment-result-thumb" aria-hidden="true">
                    ${photoUrl !== ''
                        ? `<img src="${escapeHtml(photoUrl)}" alt="${escapeHtml(photoAlt)}" class="equipment-list-photo">`
                        : '<span class="equipment-list-photo-placeholder"><i class="bi bi-image"></i></span>'
                    }
                </span>
                <div class="order-create-equipment-result-content">
                    <strong class="order-create-equipment-result-title">${title}</strong>
                    ${meta.length > 0 ? `<small class="order-create-equipment-result-meta">${escapeHtml(meta.join(' / '))}</small>` : ''}
                </div>
            </div>
        `;
    };

    const initEquipmentSelect = () => {
        if (!(els.equipmentSelect instanceof HTMLSelectElement) || equipmentSearchUrl === '') {
            return;
        }

        if (typeof window.jQuery === 'undefined' || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') {
            return;
        }

        const $ = window.jQuery;

        if ($(els.equipmentSelect).data('select2')) {
            els.equipmentSelect.dataset.select2Ready = '1';
            return;
        }

        const placeholder = els.equipmentSelect.dataset.select2Placeholder || 'Selecione o equipamento';

        $(els.equipmentSelect).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder,
            allowClear: true,
            dropdownParent: getSelect2DropdownParent(els.equipmentSelect, $),
            minimumInputLength: 0,
            language: select2Language,
            escapeMarkup: (markup) => markup,
            templateResult: renderEquipmentTemplate,
            templateSelection: (equipment) => {
                if (!equipment || equipment.loading) {
                    return escapeHtml(equipment?.text || placeholder);
                }

                return escapeHtml(buildEquipmentLabel(equipment) || placeholder);
            },
            ajax: {
                url: equipmentSearchUrl,
                dataType: 'json',
                delay: 250,
                cache: true,
                data: (params) => ({
                    q: params.term || '',
                    page: params.page || 1,
                    per_page: 10,
                    client_id: Number(els.clientSelect instanceof HTMLSelectElement ? els.clientSelect.value : 0) || 0,
                }),
                processResults: (data, params) => {
                    const page = params.page || 1;
                    const equipments = Array.isArray(data?.equipments) ? data.equipments : [];
                    const results = equipments.map((item) => {
                        const record = rememberEquipmentRecord({
                            id: item?.id,
                            label: item?.label || item?.summary || '',
                            summary: item?.summary || '',
                            brandName: item?.brand_name || '',
                            modelName: item?.model_name || '',
                            serial: item?.serial || '',
                            photoUrl: item?.photo_url || '',
                            clientId: item?.client_id || 0,
                            clientName: item?.client_name || '',
                            tipoId: item?.tipo_id || 0,
                            tipoName: item?.tipo_name || '',
                        }) || null;

                        return {
                            id: String(item?.id || record?.id || ''),
                            text: normalizeText(item?.label || record?.label || ''),
                            label: normalizeText(item?.label || record?.label || ''),
                            summary: normalizeText(item?.summary || record?.summary || ''),
                            brandName: normalizeText(item?.brand_name || record?.brandName || ''),
                            modelName: normalizeText(item?.model_name || record?.modelName || ''),
                            serial: normalizeText(item?.serial || record?.serial || ''),
                            photoUrl: normalizeText(item?.photo_url || record?.photoUrl || ''),
                            clientId: Number(item?.client_id || record?.clientId || 0) || 0,
                            clientName: normalizeText(item?.client_name || record?.clientName || ''),
                            tipoId: Number(item?.tipo_id || record?.tipoId || 0) || 0,
                            tipoName: normalizeText(item?.tipo_name || record?.tipoName || ''),
                        };
                    });

                    return {
                        results,
                        pagination: {
                            more: Number(data?.pagination?.current_page || page) < Number(data?.pagination?.last_page || page),
                        },
                    };
                },
            },
        });

        els.equipmentSelect.dataset.select2Ready = '1';

        const selectedRecord = getEquipmentRecordFromOption(getSelectedOption(els.equipmentSelect));
        if (selectedRecord) {
            rememberEquipmentRecord(selectedRecord, true);
        }
    };

    const fileListToArray = (fileList) => Array.from(fileList || []).filter((file) => file instanceof File);

    const syncPhotoInput = () => {
        if (!(els.photosInput instanceof HTMLInputElement)) {
            return;
        }

        if (typeof DataTransfer === 'undefined') {
            return;
        }

        const transfer = new DataTransfer();
        state.photoEntries.forEach((entry) => {
            if (entry?.file instanceof File) {
                transfer.items.add(entry.file);
            }
        });

        els.photosInput.files = transfer.files;
    };

    const revokePhotoUrls = () => {
        state.photoEntries.forEach((entry) => {
            if (entry?.url) {
                URL.revokeObjectURL(entry.url);
            }
        });
    };

    const renderPhotoPreview = () => {
        if (!(els.photosPreview instanceof HTMLElement)) {
            return;
        }

        if (state.photoEntries.length === 0) {
            els.photosPreview.innerHTML = `
                <div class="order-create-hint">
                    <strong>Nenhuma foto selecionada ainda.</strong>
                    <span>Use o botao de selecao para anexar ate ${maxPhotos} imagens da entrada.</span>
                </div>
            `;
            return;
        }

        els.photosPreview.innerHTML = state.photoEntries.map((entry, index) => `
            <article class="order-create-photo-preview-item" data-order-photo-index="${index}">
                <div class="order-create-photo-preview-thumb">
                    <img src="${escapeHtml(entry.url)}" alt="${escapeHtml(entry.file.name)}">
                </div>
                <div class="order-create-photo-preview-meta">
                    <strong title="${escapeHtml(entry.file.name)}">${escapeHtml(entry.file.name)}</strong>
                    <small>${escapeHtml(Math.round(entry.file.size / 1024))} KB</small>
                </div>
                <button type="button" class="order-create-photo-preview-remove" data-order-photo-remove="${index}" aria-label="Remover ${escapeHtml(entry.file.name)}">
                    <i class="bi bi-x-lg"></i>
                </button>
            </article>
        `).join('');
    };

    const applyPhotoFiles = (incomingFiles) => {
        const files = fileListToArray(incomingFiles);
        if (files.length === 0) {
            return;
        }

        const nextFiles = [...state.photoEntries.map((entry) => entry.file), ...files];
        if (nextFiles.length > maxPhotos) {
            showToast('warning', `Limite de ${maxPhotos} fotos atingido.`);
        }

        const sliced = nextFiles.slice(0, maxPhotos);
        revokePhotoUrls();
        state.photoEntries = sliced.map((file) => ({
            file,
            url: URL.createObjectURL(file),
        }));

        syncPhotoInput();
        renderPhotoPreview();
        updateSummary();
    };

    const clearPhotos = () => {
        revokePhotoUrls();
        state.photoEntries = [];
        syncPhotoInput();
        renderPhotoPreview();
        updateSummary();
    };

    const initPhotos = () => {
        if (!hasWizardForm) {
            return;
        }

        if (!(els.photosInput instanceof HTMLInputElement)) {
            return;
        }

        els.photosInput.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            applyPhotoFiles(target.files);
            target.value = '';
        });

        els.photosPickButton?.addEventListener('click', () => els.photosInput?.click());
        els.photosClearButton?.addEventListener('click', clearPhotos);

        els.photosPreview?.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const button = target.closest('[data-order-photo-remove]');
            if (!(button instanceof HTMLElement)) {
                return;
            }

            const index = Number(button.dataset.orderPhotoRemove || -1);
            if (!Number.isInteger(index) || index < 0 || index >= state.photoEntries.length) {
                return;
            }

            const [removed] = state.photoEntries.splice(index, 1);
            if (removed?.url) {
                URL.revokeObjectURL(removed.url);
            }

            syncPhotoInput();
            renderPhotoPreview();
            updateSummary();
        });

        renderPhotoPreview();
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

    const applyClientSelection = (client) => {
        syncClientOptions(client);

        if (!(els.clientSelect instanceof HTMLSelectElement)) {
            return;
        }

        const clientId = Number(client?.id || 0) || 0;
        if (clientId <= 0) {
            return;
        }

        setClientSelectValue(clientId);
        if (hasWizardForm) {
            clearEquipmentSelection();
        }

        if (hasWizardForm && els.equipmentSelect instanceof HTMLSelectElement) {
            els.equipmentSelect.focus();
        }

        updateSummary();
        showToast('success', 'Cliente cadastrado e selecionado.');
    };

    const initQuickClient = () => {
        const quickClientForm = els.quickClientForm;

        els.quickClientButton?.addEventListener('click', () => {
            const modal = getModal(els.quickClientModal);
            modal?.show();
        });

        if (!(quickClientForm instanceof HTMLFormElement) || !config.quickClientStoreUrl) {
            return;
        }

        const submitQuickClientForm = async (event) => {
            event.preventDefault();

            clearQuickClientErrors();

            if (!quickClientForm.reportValidity()) {
                renderQuickClientErrors([], 'Informe nome/razao social e telefone principal antes de salvar.');
                return;
            }

            setQuickClientSubmitState(true);

            try {
                const payload = Object.fromEntries(new FormData(quickClientForm).entries());
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
            if (typeof quickClientForm.requestSubmit === 'function') {
                quickClientForm.requestSubmit();
                return;
            }

            quickClientForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });
        quickClientForm.addEventListener('submit', submitQuickClientForm);
        els.quickClientModal?.addEventListener('hidden.bs.modal', () => {
            quickClientForm.reset();
            clearQuickClientErrors();
            setQuickClientSubmitState(false);
        });
    };

    const initQuickEquipment = () => {
        if (!(els.quickEquipmentModal instanceof HTMLElement)) {
            return;
        }

        els.quickEquipmentModal.addEventListener('show.bs.modal', syncEquipmentCreateFrame);
        els.quickEquipmentModal.addEventListener('hidden.bs.modal', resetEquipmentCreateFrame);
        window.addEventListener('message', handleEmbeddedEquipmentCreated);
    };

    const initSelectors = () => {
        if (!hasWizardForm || (!(els.clientSelect instanceof HTMLSelectElement) && !(els.equipmentSelect instanceof HTMLSelectElement))) {
            return;
        }

        initClientSelect();
        initEquipmentSelect();

        onSelectEvent(els.clientSelect, 'change', handleClientChange);
        onSelectEvent(els.clientSelect, 'select2:select', syncClientSelectionFromSelect2);
        onSelectEvent(els.clientSelect, 'select2:clear', updateSummary);
        onSelectEvent(els.equipmentSelect, 'change', handleEquipmentChange);

        if (els.prioritySelect instanceof HTMLSelectElement) {
            onSelectEvent(els.prioritySelect, 'change', updateSummary);
        }

        if (els.relatoField instanceof HTMLTextAreaElement) {
            els.relatoField.addEventListener('input', updateSummary);
        }

        if (els.defectSuggestions instanceof HTMLElement && els.relatoField instanceof HTMLTextAreaElement) {
            els.defectSuggestions.addEventListener('click', (event) => {
                const button = event.target.closest('[data-defect-text]');
                if (!button) {
                    return;
                }

                const texto = normalizeText(button.dataset.defectText || '');
                if (texto === '') {
                    return;
                }

                const linha = /[.!?]$/.test(texto) ? texto : `${texto}.`;
                const atual = normalizeText(els.relatoField.value);
                els.relatoField.value = atual !== '' ? `${atual}\n${linha}` : linha;
                updateSummary();
            });
        }

        if (els.observacoesField instanceof HTMLTextAreaElement) {
            els.observacoesField.addEventListener('input', updateSummary);
        }

        if (els.previsaoField instanceof HTMLInputElement) {
            els.previsaoField.addEventListener('change', updateSummary);
        }

        if (els.prazoEntregaSelect instanceof HTMLSelectElement) {
            onSelectEvent(els.prazoEntregaSelect, 'change', handlePrazoEntregaChange);
        }

        if (els.technicianSelect instanceof HTMLSelectElement) {
            onSelectEvent(els.technicianSelect, 'change', updateSummary);
        }

        updateSummary();

        if (els.equipmentSelect instanceof HTMLSelectElement && els.equipmentSelect.value !== '') {
            handleEquipmentChange();
        }
    };

    initTabs();
    initSelectors();
    initPhotos();
    initQuickClient();
    initQuickEquipment();
    updateSummary();
})();
