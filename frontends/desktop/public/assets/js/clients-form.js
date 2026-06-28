(function () {
    const onlyDigits = (value) => String(value || '').replace(/\D/g, '');

    const notify = (icon, title) => {
        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({
            toast: true,
            position: 'top-end',
            timer: 4200,
            timerProgressBar: true,
            showConfirmButton: false,
            icon,
            title,
            customClass: { popup: 'swal-desktop-toast' },
        });
    };

    const setLoading = (input, loading) => {
        if (input instanceof HTMLElement) {
            input.classList.toggle('is-loading', loading);
        }
    };

    const fill = (input, value) => {
        if (input instanceof HTMLInputElement && value !== undefined && value !== null && String(value).trim() !== '') {
            input.value = String(value).trim();
        }
    };

    const wireClientAutofill = (fields) => {
        const { cep, endereco, numero, bairro, cidade, uf, tipoPessoa, cpfCnpj, nomeRazao, telefone1 } = fields;

        if (!(cep instanceof HTMLInputElement) && !(cpfCnpj instanceof HTMLInputElement)) {
            return;
        }

        const lookupCep = async () => {
            if (!(cep instanceof HTMLInputElement)) {
                return;
            }

            const code = onlyDigits(cep.value);
            if (code.length !== 8) {
                return;
            }

            setLoading(cep, true);
            try {
                const response = await fetch(`https://viacep.com.br/ws/${code}/json/`);
                const data = await response.json();

                if (!response.ok || data.erro) {
                    notify('warning', 'CEP não encontrado. Preencha o endereço manualmente.');
                    return;
                }

                fill(endereco, data.logradouro);
                fill(bairro, data.bairro);
                fill(cidade, data.localidade);
                fill(uf, data.uf);
            } catch (error) {
                console.error('[clients-form] Falha ao consultar CEP', error);
                notify('error', 'Falha ao consultar o CEP. Preencha o endereço manualmente.');
            } finally {
                setLoading(cep, false);
            }
        };

        const lookupCnpj = async () => {
            if (!(cpfCnpj instanceof HTMLInputElement)) {
                return;
            }

            if (tipoPessoa instanceof HTMLSelectElement && tipoPessoa.value !== 'juridica') {
                return;
            }

            const document_ = onlyDigits(cpfCnpj.value);
            if (document_.length !== 14) {
                return;
            }

            setLoading(cpfCnpj, true);
            try {
                const response = await fetch(`https://brasilapi.com.br/api/cnpj/v1/${document_}`);
                if (!response.ok) {
                    notify('warning', 'CNPJ não encontrado. Preencha os dados manualmente.');
                    return;
                }

                const data = await response.json();

                fill(nomeRazao, data.razao_social);
                fill(telefone1, data.ddd_telefone_1);
                fill(cep, data.cep);
                fill(endereco, data.logradouro);
                fill(numero, data.numero);
                fill(bairro, data.bairro);
                fill(cidade, data.municipio);
                fill(uf, data.uf);
            } catch (error) {
                console.error('[clients-form] Falha ao consultar CNPJ', error);
                notify('error', 'Falha ao consultar o CNPJ. Preencha os dados manualmente.');
            } finally {
                setLoading(cpfCnpj, false);
            }
        };

        let cepTimer = null;
        cep?.addEventListener('input', () => {
            clearTimeout(cepTimer);
            cepTimer = setTimeout(lookupCep, 600);
        });
        cep?.addEventListener('blur', lookupCep);

        let cnpjTimer = null;
        cpfCnpj?.addEventListener('input', () => {
            clearTimeout(cnpjTimer);
            cnpjTimer = setTimeout(lookupCnpj, 600);
        });
        cpfCnpj?.addEventListener('blur', lookupCnpj);
        tipoPessoa?.addEventListener('change', lookupCnpj);
    };

    wireClientAutofill({
        cep: document.getElementById('cep'),
        endereco: document.getElementById('endereco'),
        numero: document.getElementById('numero'),
        bairro: document.getElementById('bairro'),
        cidade: document.getElementById('cidade'),
        uf: document.getElementById('uf'),
        tipoPessoa: document.getElementById('tipoPessoa'),
        cpfCnpj: document.getElementById('cpfCnpj'),
        nomeRazao: document.getElementById('nomeRazao'),
        telefone1: document.getElementById('telefone1'),
    });

    wireClientAutofill({
        cep: document.getElementById('quickClientCep'),
        endereco: document.getElementById('quickClientEndereco'),
        numero: document.getElementById('quickClientNumero'),
        bairro: document.getElementById('quickClientBairro'),
        cidade: document.getElementById('quickClientCidade'),
        uf: document.getElementById('quickClientUf'),
        tipoPessoa: null,
        cpfCnpj: document.getElementById('quickClientCpfCnpj'),
        nomeRazao: document.getElementById('quickClientNomeRazao'),
        telefone1: document.getElementById('quickClientTelefone1'),
    });
})();
