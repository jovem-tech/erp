(function () {
    const config = window.__DESKTOP_ORDER_CREATE || {};
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    document.addEventListener('DOMContentLoaded', () => {
        const clientSelect = document.getElementById(config.clientSelectId || 'clienteId');
        const equipmentSelect = document.getElementById(config.equipmentSelectId || 'equipamentoId');
        const modalElement = document.getElementById('quickClientModal');
        const form = document.getElementById('quickClientForm');
        const submitButton = document.getElementById('quickClientSubmit');
        const triggerButton = document.getElementById('btnNovoClienteRapido');
        const errorBox = document.getElementById('quickClientErrors');

        let modalInstance = null;

        const clearErrors = () => {
            if (!errorBox) {
                return;
            }

            errorBox.classList.add('d-none');
            errorBox.innerHTML = '';
        };

        const renderErrors = (messages, fallbackMessage) => {
            if (!errorBox) {
                return;
            }

            const items = Array.isArray(messages) ? messages.filter(Boolean) : [];
            const htmlItems = items.length > 0
                ? `<ul class="mb-0 ps-3">${items.map((message) => `<li>${escapeHtml(message)}</li>`).join('')}</ul>`
                : escapeHtml(fallbackMessage || 'Não foi possível cadastrar o cliente.');

            errorBox.innerHTML = htmlItems;
            errorBox.classList.remove('d-none');
        };

        const setSubmitState = (loading) => {
            if (!(submitButton instanceof HTMLElement)) {
                return;
            }

            submitButton.disabled = loading;
            submitButton.innerHTML = loading
                ? '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Salvando...'
                : '<i class="bi bi-person-plus me-2"></i>Cadastrar cliente';
        };

        const openModal = () => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            form.reset();
            clearErrors();

            if (modalInstance) {
                modalInstance.show();
            }

            window.setTimeout(() => {
                const firstField = document.getElementById('quickClientNomeRazao');
                if (firstField instanceof HTMLElement) {
                    firstField.focus({ preventScroll: true });
                }
            }, 150);
        };

        const closeModal = () => {
            if (modalInstance) {
                modalInstance.hide();
            }
        };

        const syncClientSelect = (client) => {
            if (!(clientSelect instanceof HTMLSelectElement) || !client?.id) {
                return;
            }

            const clientId = String(client.id);
            const clientName = String(client.nome_razao || client.nome || 'Cliente').trim();
            const existingOption = clientSelect.querySelector(`option[value="${clientId}"]`);

            if (existingOption instanceof HTMLOptionElement) {
                existingOption.text = clientName;
                existingOption.selected = true;
            } else {
                clientSelect.add(new Option(clientName, clientId, true, true));
            }

            clientSelect.value = clientId;
            clientSelect.dispatchEvent(new Event('change', { bubbles: true }));

            if (equipmentSelect instanceof HTMLSelectElement) {
                equipmentSelect.value = '';
                equipmentSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        const submitForm = async (event) => {
            event.preventDefault();

            if (!(form instanceof HTMLFormElement) || !config.quickClientStoreUrl) {
                return;
            }

            clearErrors();
            setSubmitState(true);

            try {
                const response = await fetch(config.quickClientStoreUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });

                const payload = await response.json().catch(() => ({}));

                if (response.status === 401) {
                    window.location.href = '/login';
                    return;
                }

                if (!response.ok) {
                    const validationMessages = payload?.errors
                        ? Object.values(payload.errors).flat().filter(Boolean)
                        : [];
                    renderErrors(validationMessages, payload?.message || 'Não foi possível cadastrar o cliente.');
                    return;
                }

                const client = payload.client || payload.data?.client || null;
                if (!client) {
                    renderErrors([], 'O backend não retornou os dados do cliente cadastrado.');
                    return;
                }

                syncClientSelect(client);
                closeModal();

                if (typeof window.Swal !== 'undefined') {
                    window.Swal.fire({
                        icon: 'success',
                        title: 'Cliente cadastrado',
                        text: payload.message || 'O cliente foi adicionado à OS com sucesso.',
                        timer: 1800,
                        showConfirmButton: false,
                    });
                }
            } catch (error) {
                if (window.DesktopUi && typeof window.DesktopUi.logError === 'function') {
                    window.DesktopUi.logError('orders-create.quickClient', error);
                } else {
                    console.error('[OrdersCreate] Falha ao cadastrar cliente rápido.', error);
                }
                renderErrors([], 'Não foi possível cadastrar o cliente agora. Tente novamente.');
            } finally {
                setSubmitState(false);
            }
        };

        if (modalElement instanceof HTMLElement && typeof bootstrap !== 'undefined') {
            modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
        }

        triggerButton?.addEventListener('click', openModal);
        submitButton?.addEventListener('click', () => {
            if (form instanceof HTMLFormElement) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }

                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }
        });
        form?.addEventListener('submit', submitForm);

        modalElement?.addEventListener('hidden.bs.modal', () => {
            clearErrors();
            form?.reset();
            setSubmitState(false);
        });
    });
})();
