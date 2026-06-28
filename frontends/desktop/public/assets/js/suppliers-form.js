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

    const form = document.getElementById('supplierForm');
    const lookupUrl = form?.dataset?.supplierLookupUrl || '';
    const tipoPessoa = document.getElementById('supplierTipoPessoa');
    const cnpjCpf = document.getElementById('supplierCnpjCpf');
    const feedback = document.getElementById('supplierCnpjFeedback');

    const fields = {
        razaoSocial: document.getElementById('supplierRazaoSocial'),
        nomeFantasia: document.getElementById('supplierNomeFantasia'),
        ieRg: document.getElementById('supplierIeRg'),
        email: document.getElementById('supplierEmail'),
        telefone1: document.getElementById('supplierTelefone1'),
        telefone2: document.getElementById('supplierTelefone2'),
        cep: document.getElementById('supplierCep'),
        endereco: document.getElementById('supplierEndereco'),
        numero: document.getElementById('supplierNumero'),
        complemento: document.getElementById('supplierComplemento'),
        bairro: document.getElementById('supplierBairro'),
        cidade: document.getElementById('supplierCidade'),
        uf: document.getElementById('supplierUf'),
    };

    const setFeedback = (message, tone = 'muted') => {
        if (!(feedback instanceof HTMLElement)) {
            return;
        }

        feedback.classList.remove('text-muted', 'text-success', 'text-warning', 'text-danger');
        feedback.classList.add(`text-${tone}`);
        feedback.textContent = message;
    };

    const clearFeedback = () => {
        setFeedback('Informe um CNPJ válido para tentar preencher os dados públicos automaticamente.', 'muted');
    };

    const applyLookup = (payload) => {
        fill(fields.razaoSocial, payload.razao_social);
        fill(fields.nomeFantasia, payload.nome_fantasia || payload.razao_social);
        fill(fields.ieRg, payload.ie_rg);
        fill(fields.email, payload.email);
        fill(fields.telefone1, payload.telefone1);
        fill(fields.telefone2, payload.telefone2);
        fill(fields.cep, payload.cep);
        fill(fields.endereco, payload.endereco);
        fill(fields.numero, payload.numero);
        fill(fields.complemento, payload.complemento);
        fill(fields.bairro, payload.bairro);
        fill(fields.cidade, payload.cidade);
        fill(fields.uf, payload.uf);
    };

    const lookupCnpj = async () => {
        if (!(cnpjCpf instanceof HTMLInputElement) || !(tipoPessoa instanceof HTMLSelectElement)) {
            return;
        }

        if (tipoPessoa.value !== 'juridica') {
            clearFeedback();
            return;
        }

        const digits = onlyDigits(cnpjCpf.value);
        if (digits.length !== 14 || !lookupUrl) {
            return;
        }

        setLoading(cnpjCpf, true);

        try {
            const response = await fetch(`${lookupUrl}?cnpj=${encodeURIComponent(digits)}`, {
                headers: {
                    Accept: 'application/json',
                },
            });
            const data = await response.json();
            const lookup = data?.lookup || {};

            if (!response.ok || data?.success !== true || lookup.success !== true || !lookup.data) {
                setFeedback(lookup.message || data?.message || 'Não foi possível consultar este CNPJ agora. Preencha manualmente se necessário.', 'warning');
                notify('warning', lookup.message || data?.message || 'Consulta de CNPJ indisponível.');
                return;
            }

            applyLookup(lookup.data);
            setFeedback('CNPJ localizado. Dados públicos preenchidos automaticamente.', 'success');
            notify('success', 'CNPJ localizado com sucesso.');
        } catch (error) {
            console.error('[suppliers-form] Falha ao consultar CNPJ', error);
            setFeedback('Falha ao consultar o CNPJ. Preencha manualmente se necessário.', 'danger');
            notify('error', 'Falha ao consultar o CNPJ.');
        } finally {
            setLoading(cnpjCpf, false);
        }
    };

    let timer = null;
    cnpjCpf?.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(lookupCnpj, 650);
    });

    cnpjCpf?.addEventListener('blur', lookupCnpj);
    tipoPessoa?.addEventListener('change', () => {
        if (tipoPessoa.value !== 'juridica') {
            clearFeedback();
            return;
        }

        lookupCnpj();
    });

    clearFeedback();
})();
