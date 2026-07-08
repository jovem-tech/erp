(function () {
    const config = window.__DESKTOP_CANCEL_CLOSURE_MODAL || {};
    const cancelUrlTemplate = String(config.cancelUrlTemplate || '');
    const csrfToken = String(config.csrfToken || '');

    const buildCancelUrl = (orderId) => cancelUrlTemplate.replaceAll('__ORDER__', String(orderId));

    const modalEl = document.getElementById('cancelClosureModal');
    if (!modalEl) return;

    const form = document.getElementById('cancelClosureForm');
    const errorBox = document.getElementById('cancelClosureError');
    const submitBtn = document.getElementById('cancelClosureSubmit');
    const emailInput = document.getElementById('cancelClosureAdminEmail');
    const passwordInput = document.getElementById('cancelClosureAdminPassword');

    let currentOrderId = null;

    const setText = (id, text) => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    };

    const showError = (message) => {
        if (errorBox instanceof HTMLElement) {
            errorBox.textContent = message;
            errorBox.classList.remove('d-none');
        }
    };

    const clearError = () => {
        if (errorBox instanceof HTMLElement) {
            errorBox.classList.add('d-none');
            errorBox.textContent = '';
        }
    };

    // Abre o modal: le o order-id/numero de quem disparou (botao do cabecalho
    // da OS ou item do dropdown "Acoes" da listagem) e limpa o estado anterior.
    modalEl.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        currentOrderId = trigger?.dataset.orderId || '';
        setText('cancelClosureNumero', trigger?.dataset.orderNumero || '-');
        clearError();
        form?.reset();
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        currentOrderId = null;
        clearError();
        form?.reset();
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!currentOrderId) return;

        clearError();
        if (submitBtn instanceof HTMLButtonElement) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verificando...';
        }

        try {
            const res = await fetch(buildCancelUrl(currentOrderId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    admin_email: emailInput?.value || '',
                    admin_password: passwordInput?.value || '',
                }),
            });

            const result = await res.json().catch(() => ({}));

            if (!res.ok || result.error) {
                throw new Error(result.error || 'Não foi possível cancelar a baixa desta OS.');
            }

            // Recarrega a pagina para refletir o status revertido.
            window.location.reload();
        } catch (err) {
            showError(err.message || 'Não foi possível cancelar a baixa. Tente novamente.');

            if (submitBtn instanceof HTMLButtonElement) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i>Confirmar cancelamento da baixa';
            }
        }
    });
})();
