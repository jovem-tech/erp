<script>
    (() => {
        const lowercaseConnectors = new Set(['da', 'das', 'de', 'di', 'do', 'dos', 'du', 'e']);

        const titleCaseToken = (token) => token
            .split('-')
            .map((part) => part === ''
                ? part
                : part.charAt(0).toLocaleUpperCase('pt-BR') + part.slice(1)
            )
            .join('-');

        const normalizePersonName = (value) => {
            const words = String(value || '')
                .trim()
                .replace(/\s+/g, ' ')
                .toLocaleLowerCase('pt-BR')
                .split(' ')
                .filter(Boolean);

            return words
                .map((word, index) => index > 0 && lowercaseConnectors.has(word) ? word : titleCaseToken(word))
                .join(' ');
        };

        const applyNameMask = (input) => {
            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            input.value = normalizePersonName(input.value);
        };

        const selectedGroupLabel = (select) => {
            if (!(select instanceof HTMLSelectElement) || select.value === '') {
                return '';
            }

            return select.options[select.selectedIndex]?.textContent?.trim() || '';
        };

        const syncProfileFromGroup = (select) => {
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            const target = select.dataset.userProfileTarget
                ? document.querySelector(select.dataset.userProfileTarget)
                : null;

            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            target.value = selectedGroupLabel(select);
        };

        const resetPasswordFields = (form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            form.querySelectorAll('[data-user-password-fields]').forEach((wrapper) => {
                wrapper.classList.add('d-none');
                wrapper.querySelectorAll('input').forEach((input) => {
                    input.value = '';
                    input.disabled = true;
                    input.required = false;
                });
            });

            const button = form.querySelector('[data-user-password-toggle]');
            if (button instanceof HTMLButtonElement) {
                button.innerHTML = '<i class="bi bi-key me-2"></i>Alterar senha';
                button.dataset.passwordVisible = '0';
            }
        };

        const togglePasswordFields = (button) => {
            const form = button.closest('form');
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const shouldShow = button.dataset.passwordVisible !== '1';
            form.querySelectorAll('[data-user-password-fields]').forEach((wrapper) => {
                wrapper.classList.toggle('d-none', !shouldShow);
                wrapper.querySelectorAll('input').forEach((input) => {
                    input.disabled = !shouldShow;
                    input.required = shouldShow;
                    if (!shouldShow) {
                        input.value = '';
                    }
                });
            });

            button.dataset.passwordVisible = shouldShow ? '1' : '0';
            button.innerHTML = shouldShow
                ? '<i class="bi bi-x-circle me-2"></i>Cancelar alteração de senha'
                : '<i class="bi bi-key me-2"></i>Alterar senha';

            if (shouldShow) {
                form.querySelector('input[name="password"]')?.focus();
            }
        };

        document.querySelectorAll('[data-person-name-input]').forEach((input) => {
            input.addEventListener('blur', () => applyNameMask(input));
        });

        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                form.querySelectorAll('[data-person-name-input]').forEach(applyNameMask);
            });
        });

        document.querySelectorAll('[data-user-group-select]').forEach((select) => {
            syncProfileFromGroup(select);
            select.addEventListener('change', () => syncProfileFromGroup(select));

            if (window.jQuery) {
                window.jQuery(select).on('change select2:select select2:clear', () => syncProfileFromGroup(select));
            }
        });

        document.querySelectorAll('[data-user-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => togglePasswordFields(button));
        });

        document.querySelectorAll('.modal').forEach((modal) => {
            modal.addEventListener('shown.bs.modal', () => {
                modal.querySelectorAll('[data-user-group-select]').forEach(syncProfileFromGroup);
            });

            modal.addEventListener('hidden.bs.modal', () => {
                modal.querySelectorAll('form').forEach(resetPasswordFields);
            });
        });
    })();
</script>
