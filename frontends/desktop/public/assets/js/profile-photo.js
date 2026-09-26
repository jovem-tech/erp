(() => {
    'use strict';

    // A foto vem do campo padrão de imagem (specs/049): escolher não grava mais
    // na hora — o círculo mostra a prévia e o "Salvar foto" envia o form. Assim
    // dá para recortar antes, e um Ctrl+V por engano não troca a foto de ninguém.
    const form = document.querySelector('[data-profile-photo-form]');
    const preview = document.querySelector('[data-profile-photo-preview]');
    if (!form || !preview) return;

    const original = preview.innerHTML;
    let pendingUrl = '';

    form.addEventListener('image-picker:change', (event) => {
        const file = event.detail?.files?.[0];

        if (pendingUrl !== '') {
            URL.revokeObjectURL(pendingUrl);
            pendingUrl = '';
        }

        if (!(file instanceof File)) {
            preview.innerHTML = original;
            return;
        }

        pendingUrl = URL.createObjectURL(file);
        const image = document.createElement('img');
        image.src = pendingUrl;
        image.alt = 'Prévia da nova foto de perfil';
        preview.replaceChildren(image);
    });
})();
