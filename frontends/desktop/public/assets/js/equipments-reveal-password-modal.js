(function () {
    const config = window.__DESKTOP_REVEAL_PASSWORD_MODAL || {};
    const revealUrl = String(config.revealUrl || '');
    const csrfToken = String(config.csrfToken || '');

    const modalEl = document.getElementById('revealPasswordModal');
    if (!modalEl || revealUrl === '') return;

    const form = document.getElementById('revealPasswordForm');
    const errorBox = document.getElementById('revealPasswordError');
    const resultBox = document.getElementById('revealPasswordResult');
    const valueEl = document.getElementById('revealPasswordValue');
    const submitBtn = document.getElementById('revealPasswordSubmit');
    const emailInput = document.getElementById('revealPasswordAdminEmail');
    const passwordInput = document.getElementById('revealPasswordAdminPassword');

    const showError = (message) => {
        if (errorBox instanceof HTMLElement) {
            errorBox.textContent = message;
            errorBox.classList.remove('d-none');
        }
    };

    const resetState = () => {
        errorBox?.classList.add('d-none');
        if (errorBox) errorBox.textContent = '';
        resultBox?.classList.add('d-none');
        // Nunca deixar a senha revelada residente no DOM depois de fechar.
        if (valueEl) valueEl.textContent = '';
        form?.reset();
        if (submitBtn instanceof HTMLButtonElement) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-eye me-1"></i>Revelar senha';
        }
    };

    modalEl.addEventListener('show.bs.modal', resetState);
    modalEl.addEventListener('hidden.bs.modal', resetState);

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();

        errorBox?.classList.add('d-none');
        if (submitBtn instanceof HTMLButtonElement) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verificando...';
        }

        try {
            const res = await fetch(revealUrl, {
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
                throw new Error(result.error || 'Não foi possível revelar a senha do equipamento.');
            }

            const senha = String(result.senha_acesso || '');
            if (valueEl) valueEl.textContent = senha !== '' ? senha : '(sem senha cadastrada)';
            resultBox?.classList.remove('d-none');
        } catch (err) {
            showError(err.message || 'Não foi possível revelar a senha. Tente novamente.');
        } finally {
            if (submitBtn instanceof HTMLButtonElement) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-eye me-1"></i>Revelar senha';
            }
        }
    });
})();
