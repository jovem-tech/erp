(() => {
    'use strict';

    const form = document.querySelector('[data-profile-photo-form]');
    if (!form) return;

    const input = form.querySelector('[data-profile-photo-input]');
    const preview = document.querySelector('[data-profile-photo-preview]');

    input.addEventListener('change', () => {
        if (!input.files || input.files.length === 0) return;

        const file = input.files[0];
        const reader = new FileReader();
        reader.onload = () => {
            preview.innerHTML = '<img src="' + reader.result + '" alt="Sua foto de perfil">';
        };
        reader.readAsDataURL(file);

        // requestSubmit() (não submit()) dispara o evento nativo "submit" —
        // o guard de sessão do layout escuta esse evento para saber que a
        // saída da página é navegação legítima e não "navegador fechado".
        // submit() pula esse evento e derruba a sessão à toa.
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });
})();
