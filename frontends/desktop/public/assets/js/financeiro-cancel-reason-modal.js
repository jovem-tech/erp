(function () {
    // Modal de cancelamento de título de OS encerrada — 2 telas (motivo →
    // admin), reaproveitado por qualquer linha/página que tenha o botão
    // "Cancelar" de um lançamento vinculado a OS encerrada. Ver
    // financeiro/_cancel_reason_modal.blade.php para o HTML e o contrato de
    // data-target-form (seletor do <form> real daquela linha, que já tem os
    // 3 hidden inputs motivo/admin_email/admin_password).
    const modalEl = document.getElementById('financeiroCancelReasonModal');
    if (!modalEl) return;

    const getModal = (element) => {
        if (!(element instanceof HTMLElement) || typeof window.bootstrap === 'undefined' || !window.bootstrap?.Modal) {
            return null;
        }

        return window.bootstrap.Modal.getOrCreateInstance(element);
    };

    const stepMotivo = modalEl.querySelector('[data-cancel-reason-step="motivo"]');
    const stepAdmin = modalEl.querySelector('[data-cancel-reason-step="admin"]');
    const reasonError = document.getElementById('financeiroCancelReasonError');
    const adminError = document.getElementById('financeiroCancelAdminError');
    const nextBtn = document.getElementById('financeiroCancelReasonNext');
    const backBtn = document.getElementById('financeiroCancelReasonBack');
    const submitBtn = document.getElementById('financeiroCancelReasonSubmit');
    const emailInput = document.getElementById('financeiroCancelAdminEmail');
    const passwordInput = document.getElementById('financeiroCancelAdminPassword');

    let targetFormSelector = null;

    const showStep = (step) => {
        stepMotivo?.classList.toggle('d-none', step !== 'motivo');
        stepAdmin?.classList.toggle('d-none', step !== 'admin');
        nextBtn?.classList.toggle('d-none', step !== 'motivo');
        backBtn?.classList.toggle('d-none', step !== 'admin');
        submitBtn?.classList.toggle('d-none', step !== 'admin');
    };

    const resetModal = () => {
        targetFormSelector = null;
        showStep('motivo');
        modalEl.querySelectorAll('input[name="cancel_reason_motivo"]').forEach((input) => {
            if (input instanceof HTMLInputElement) input.checked = false;
        });
        if (emailInput instanceof HTMLInputElement) emailInput.value = '';
        if (passwordInput instanceof HTMLInputElement) passwordInput.value = '';
        reasonError?.classList.add('d-none');
        adminError?.classList.add('d-none');
    };

    modalEl.addEventListener('show.bs.modal', (event) => {
        resetModal();
        const trigger = event.relatedTarget;
        targetFormSelector = trigger?.dataset.targetForm || null;
    });

    modalEl.addEventListener('hidden.bs.modal', resetModal);

    nextBtn?.addEventListener('click', () => {
        const selected = modalEl.querySelector('input[name="cancel_reason_motivo"]:checked');

        if (!(selected instanceof HTMLInputElement)) {
            if (reasonError instanceof HTMLElement) {
                reasonError.textContent = 'Selecione o motivo do cancelamento.';
                reasonError.classList.remove('d-none');
            }
            return;
        }

        reasonError?.classList.add('d-none');
        showStep('admin');
    });

    backBtn?.addEventListener('click', () => {
        showStep('motivo');
    });

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

        const motivo = modalEl.querySelector('input[name="cancel_reason_motivo"]:checked');
        const motivoInput = form.querySelector('[data-financeiro-cancel-motivo]');
        const emailHidden = form.querySelector('[data-financeiro-cancel-admin-email]');
        const passwordHidden = form.querySelector('[data-financeiro-cancel-admin-password]');

        if (motivoInput instanceof HTMLInputElement) motivoInput.value = motivo instanceof HTMLInputElement ? motivo.value : '';
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
