(function () {
    const onlyDigits = (value) => String(value || '').replace(/\D/g, '');

    const NAME_CONNECTORS = ['de', 'da', 'do', 'das', 'dos', 'e'];

    // Title Case pt-BR: cada palavra com inicial maiuscula, conectores em minusculo
    // (exceto quando sao a primeira palavra). Colapsa espacos multiplos.
    const toTitleCaseName = (value) => {
        const collapsed = String(value || '').trim().replace(/\s+/g, ' ');
        if (collapsed === '') {
            return '';
        }

        return collapsed
            .split(' ')
            .map((word, index) => {
                const lower = word.toLocaleLowerCase('pt-BR');
                if (index > 0 && NAME_CONNECTORS.includes(lower)) {
                    return lower;
                }
                return lower.charAt(0).toLocaleUpperCase('pt-BR') + lower.slice(1);
            })
            .join(' ');
    };

    // Mascara de telefone brasileiro: celular (11) "(21) 98061-4757", fixo (10)
    // "(22) 2627-4120". Remove codigo de pais "55" excedente. Mascara progressiva
    // enquanto digita.
    const formatBrazilPhone = (value) => {
        let digits = onlyDigits(value);
        if ((digits.length === 12 || digits.length === 13) && digits.startsWith('55')) {
            digits = digits.slice(2);
        }
        digits = digits.slice(0, 11);

        if (digits.length === 0) {
            return '';
        }
        if (digits.length <= 2) {
            return '(' + digits;
        }
        if (digits.length <= 6) {
            return '(' + digits.slice(0, 2) + ') ' + digits.slice(2);
        }
        if (digits.length <= 10) {
            return '(' + digits.slice(0, 2) + ') ' + digits.slice(2, 6) + '-' + digits.slice(6);
        }
        return '(' + digits.slice(0, 2) + ') ' + digits.slice(2, 7) + '-' + digits.slice(7, 11);
    };

    // Mascara progressiva de CPF/CNPJ: ate 11 digitos aplica CPF "000.000.000-00";
    // acima disso aplica CNPJ "00.000.000/0000-00". Limita a 14 digitos.
    const formatCpfCnpj = (value) => {
        const digits = onlyDigits(value).slice(0, 14);

        if (digits.length <= 11) {
            if (digits.length > 9) {
                return digits.slice(0, 3) + '.' + digits.slice(3, 6) + '.' + digits.slice(6, 9) + '-' + digits.slice(9);
            }
            if (digits.length > 6) {
                return digits.slice(0, 3) + '.' + digits.slice(3, 6) + '.' + digits.slice(6);
            }
            if (digits.length > 3) {
                return digits.slice(0, 3) + '.' + digits.slice(3);
            }
            return digits;
        }

        let out = digits.slice(0, 2) + '.' + digits.slice(2, 5) + '.' + digits.slice(5, 8) + '/' + digits.slice(8, 12);
        if (digits.length > 12) {
            out += '-' + digits.slice(12);
        }
        return out;
    };

    // Aplica mascara de telefone (ao digitar e ao sair) e Title Case de nome (ao sair).
    // nomeRazao so recebe Title Case quando pessoa fisica (ou sem seletor = modal rapido).
    const wireClientFormatting = (fields) => {
        const { nomeRazao, nomeContato, tipoPessoa, phones } = fields;

        (phones || []).forEach((input) => {
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            const apply = () => {
                input.value = formatBrazilPhone(input.value);
            };
            input.addEventListener('input', apply);
            input.addEventListener('blur', apply);
        });

        if (nomeContato instanceof HTMLInputElement) {
            nomeContato.addEventListener('blur', () => {
                nomeContato.value = toTitleCaseName(nomeContato.value);
            });
        }

        if (nomeRazao instanceof HTMLInputElement) {
            nomeRazao.addEventListener('blur', () => {
                const isFisica = !(tipoPessoa instanceof HTMLSelectElement) || tipoPessoa.value === 'fisica';
                if (isFisica) {
                    nomeRazao.value = toTitleCaseName(nomeRazao.value);
                }
            });
        }
    };

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

        if (cpfCnpj instanceof HTMLInputElement) {
            cpfCnpj.setAttribute('inputmode', 'numeric');
            const applyCpfCnpjMask = () => {
                cpfCnpj.value = formatCpfCnpj(cpfCnpj.value);
            };
            cpfCnpj.addEventListener('input', applyCpfCnpjMask);
            cpfCnpj.addEventListener('blur', applyCpfCnpjMask);
            applyCpfCnpjMask();
        }

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

    // Formulario completo de cliente
    wireClientFormatting({
        nomeRazao: document.getElementById('nomeRazao'),
        nomeContato: document.getElementById('nomeContato'),
        tipoPessoa: document.getElementById('tipoPessoa'),
        phones: [
            document.getElementById('telefone1'),
            document.getElementById('telefone2'),
            document.getElementById('telefoneContato'),
        ],
    });

    // Modal de cadastro rapido (sempre pessoa fisica)
    wireClientFormatting({
        nomeRazao: document.getElementById('quickClientNomeRazao'),
        nomeContato: document.getElementById('quickClientNomeContato'),
        tipoPessoa: null,
        phones: [
            document.getElementById('quickClientTelefone1'),
            document.getElementById('quickClientTelefoneContato'),
        ],
    });
})();
