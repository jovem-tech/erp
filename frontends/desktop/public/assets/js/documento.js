(function () {
    'use strict';

    // Espelho em JS de App\Support\Documento (backend). NAO e' a autoridade:
    // quem recusa de verdade e' o backend, no 422. Isto existe para o operador
    // ver o erro sem esperar ida ao servidor, que e' onde a digitacao errada
    // costuma virar "deixa em branco".
    //
    // CNPJ alfanumerico (producao desde 06/07/2026): as 12 primeiras posicoes
    // aceitam A-Z, so' os 2 verificadores continuam numericos. Por isso a
    // limpeza NAO pode ser /\D/g — apagaria as letras.
    const limpar = (valor) => String(valor || '').toUpperCase().replace(/[^0-9A-Z]/g, '');

    const CPF_PESOS_1 = [10, 9, 8, 7, 6, 5, 4, 3, 2];
    const CPF_PESOS_2 = [11, 10, 9, 8, 7, 6, 5, 4, 3, 2];
    const CNPJ_PESOS_1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    const CNPJ_PESOS_2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    // Modulo 11 sobre (codigo ASCII - 48), a formula que o CNPJ alfanumerico
    // manteve: '0'..'9' seguem valendo 0..9 e 'A' vale 17.
    const digito = (base, pesos) => {
        let soma = 0;
        for (let i = 0; i < base.length; i++) {
            soma += (base.charCodeAt(i) - 48) * pesos[i];
        }
        const resto = soma % 11;
        return resto < 2 ? 0 : 11 - resto;
    };

    const repetido = (documento) => /^(.)\1*$/.test(documento);

    const ehCpf = (documento) => {
        if (!/^\d{11}$/.test(documento) || repetido(documento)) {
            return false;
        }
        const base = documento.slice(0, 9);
        const d1 = digito(base, CPF_PESOS_1);
        const d2 = digito(base + d1, CPF_PESOS_2);
        return documento === base + d1 + d2;
    };

    const ehCnpj = (documento) => {
        if (!/^[0-9A-Z]{12}\d{2}$/.test(documento) || repetido(documento)) {
            return false;
        }
        const base = documento.slice(0, 12);
        const d1 = digito(base, CNPJ_PESOS_1);
        const d2 = digito(base + d1, CNPJ_PESOS_2);
        return documento === base + d1 + d2;
    };

    const valido = (documento) => ehCpf(documento) || ehCnpj(documento);

    // Decide a mascara pelo que foi digitado: so' digitos e ate' 11 le como CPF;
    // qualquer letra, ou mais de 11 caracteres, e' CNPJ.
    const mascarar = (documento) => {
        if (documento === '') {
            return '';
        }

        const pareceCpf = documento.length <= 11 && /^\d*$/.test(documento);

        if (pareceCpf) {
            return documento
                .replace(/^(\d{3})(\d)/, '$1.$2')
                .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
                .replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
        }

        const cnpj = documento.slice(0, 14);
        return cnpj
            .replace(/^([0-9A-Z]{2})([0-9A-Z])/, '$1.$2')
            .replace(/^([0-9A-Z]{2})\.([0-9A-Z]{3})([0-9A-Z])/, '$1.$2.$3')
            .replace(/^([0-9A-Z]{2})\.([0-9A-Z]{3})\.([0-9A-Z]{3})([0-9A-Z])/, '$1.$2.$3/$4')
            .replace(/^([0-9A-Z]{2})\.([0-9A-Z]{3})\.([0-9A-Z]{3})\/([0-9A-Z]{4})([0-9A-Z])/, '$1.$2.$3/$4-$5');
    };

    const feedbackDe = (campo) => {
        let alvo = campo.nextElementSibling;
        if (alvo && alvo.classList.contains('invalid-feedback')) {
            return alvo;
        }
        alvo = document.createElement('div');
        alvo.className = 'invalid-feedback';
        alvo.setAttribute('data-documento-feedback', '');
        campo.insertAdjacentElement('afterend', alvo);
        return alvo;
    };

    const conferir = (campo) => {
        const documento = limpar(campo.value);
        const feedback = feedbackDe(campo);

        // Vazio nao e' erro: o CPF continua opcional de proposito. Exigir
        // empurraria o operador para fora do sistema.
        if (documento === '' || valido(documento)) {
            campo.classList.remove('is-invalid');
            feedback.textContent = '';
            return true;
        }

        campo.classList.add('is-invalid');
        feedback.textContent = documento.length <= 11
            ? 'CPF invalido — confira os digitos.'
            : 'CNPJ invalido — confira os digitos.';
        return false;
    };

    const ligar = (campo) => {
        if (campo.dataset.documentoLigado === '1') {
            return;
        }
        campo.dataset.documentoLigado = '1';
        campo.setAttribute('autocomplete', 'off');
        campo.setAttribute('inputmode', 'text');
        campo.setAttribute('maxlength', '18');

        campo.addEventListener('input', () => {
            campo.value = mascarar(limpar(campo.value));
            // Enquanto digita so' limpa o erro; nao acusa a cada tecla, que
            // marcaria de vermelho um CPF ainda incompleto.
            if (campo.classList.contains('is-invalid')) {
                conferir(campo);
            }
        });

        campo.addEventListener('blur', () => conferir(campo));

        campo.value = mascarar(limpar(campo.value));

        const formulario = campo.closest('form');
        if (formulario && formulario.dataset.documentoGuard !== '1') {
            formulario.dataset.documentoGuard = '1';
            formulario.addEventListener('submit', (evento) => {
                const campos = formulario.querySelectorAll('input[data-documento]');
                let ok = true;
                campos.forEach((item) => {
                    if (!conferir(item)) {
                        ok = false;
                    }
                });
                if (!ok) {
                    evento.preventDefault();
                    evento.stopPropagation();
                    const primeiro = formulario.querySelector('input[data-documento].is-invalid');
                    if (primeiro) {
                        primeiro.focus();
                    }
                }
            });
        }
    };

    const iniciar = () => {
        document.querySelectorAll('input[data-documento]').forEach(ligar);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    // Modal montado depois do load (PDV, wizard da OS) continua coberto.
    window.erpDocumento = { limpar, valido, mascarar, iniciar };
})();
