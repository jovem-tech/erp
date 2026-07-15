(function () {
    // Modal de confirmação de admin para excluir um lançamento — 1 tela só
    // (e-mail + senha), reaproveitado por qualquer linha/página que tenha o
    // botão "Excluir". Ver financeiro/_delete_admin_modal.blade.php para o
    // HTML e o contrato de data-target-form (seletor do <form> real daquela
    // linha, que já tem os 2 hidden inputs admin_email/admin_password).
    const modalEl = document.getElementById('financeiroDeleteAdminModal');
    if (!modalEl) return;

    const getModal = (element) => {
        if (!(element instanceof HTMLElement) || typeof window.bootstrap === 'undefined' || !window.bootstrap?.Modal) {
            return null;
        }

        return window.bootstrap.Modal.getOrCreateInstance(element);
    };

    const submitBtn = document.getElementById('financeiroDeleteAdminSubmit');
    const adminError = document.getElementById('financeiroDeleteAdminError');
    const emailInput = document.getElementById('financeiroDeleteAdminEmail');
    const passwordInput = document.getElementById('financeiroDeleteAdminPassword');

    let targetFormSelector = null;

    const resetModal = () => {
        targetFormSelector = null;
        if (emailInput instanceof HTMLInputElement) emailInput.value = '';
        if (passwordInput instanceof HTMLInputElement) passwordInput.value = '';
        adminError?.classList.add('d-none');
    };

    modalEl.addEventListener('show.bs.modal', (event) => {
        resetModal();
        const trigger = event.relatedTarget;
        targetFormSelector = trigger?.dataset.targetForm || null;
    });

    modalEl.addEventListener('hidden.bs.modal', resetModal);

    submitBtn?.addEventListener('click', () => {
        const email = (emailInput?.value || '').trim();
        const password = passwordInput?.value || '';

        if (email === '' || password === '') {
            if (adminError instanceof HTMLElement) {
                adminError.textContent = 'Informe e-mail e senha do administrador.';
                adminError.classList.remove('d-none');
            }
            return;
        }

        if (!targetFormSelector) return;

        const form = document.querySelector(targetFormSelector);
        if (!(form instanceof HTMLFormElement)) return;

        const emailHidden = form.querySelector('[data-financeiro-delete-admin-email]');
        const passwordHidden = form.querySelector('[data-financeiro-delete-admin-password]');

        if (emailHidden instanceof HTMLInputElement) emailHidden.value = email;
        if (passwordHidden instanceof HTMLInputElement) passwordHidden.value = password;

        getModal(modalEl)?.hide();

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        form.submit();
    });
})();
