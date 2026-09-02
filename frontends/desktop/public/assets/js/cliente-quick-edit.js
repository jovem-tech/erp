(function () {
    'use strict';

    // Edição rápida do cliente a partir de qualquer tela.
    //
    // Existe porque o dado fiscal costuma faltar exatamente na hora de emitir a
    // nota — e mandar o operador sair da tela, achar o cliente, corrigir e
    // voltar é o tipo de caminho que na prática não se percorre.
    //
    // Reusa o modal `clients/quick-edit-modal` e as rotas `clients.quick.show` /
    // `clients.quick.update`, que já existiam para o PDV. O JS do PDV não deu
    // para reaproveitar: está amarrado aos helpers daquela tela.

    const CAMPOS = {
        quickEditClientNomeRazao: 'nome_razao',
        quickEditClientTelefone1: 'telefone1',
        quickEditClientEmail: 'email',
        quickEditClientCpfCnpj: 'cpf_cnpj',
        quickEditClientTelefoneContato: 'telefone_contato',
        quickEditClientNomeContato: 'nome_contato',
        quickEditClientCep: 'cep',
        quickEditClientNumero: 'numero',
        quickEditClientEndereco: 'endereco',
        quickEditClientBairro: 'bairro',
        quickEditClientCidade: 'cidade',
        quickEditClientUf: 'uf',
        quickEditClientCodigoIbge: 'codigo_ibge_municipio',
    };

    const token = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const modalDe = (elemento) => {
        if (typeof window.bootstrap?.Modal?.getOrCreateInstance === 'function') {
            return window.bootstrap.Modal.getOrCreateInstance(elemento);
        }
        return null;
    };

    const mostrarErros = (caixa, mensagem, erros) => {
        if (!(caixa instanceof HTMLElement)) { return; }

        const lista = Object.values(erros || {}).flat();
        caixa.innerHTML = lista.length > 0
            ? '<ul class="mb-0 ps-3">' + lista.map((e) => '<li>' + e + '</li>').join('') + '</ul>'
            : (mensagem || 'Não foi possível salvar.');
        caixa.classList.remove('d-none');
    };

    const iniciar = () => {
        const modalEl = document.getElementById('quickEditClientModal');
        const form = document.getElementById('quickEditClientForm');
        const botaoSalvar = document.getElementById('quickEditClientSubmit');
        const caixaErros = document.getElementById('quickEditClientErrors');
        const linkCompleto = document.getElementById('quickEditClientFullLink');

        if (!modalEl || !form) { return; }

        document.querySelectorAll('[data-editar-cliente]').forEach((botao) => {
            botao.addEventListener('click', async () => {
                caixaErros?.classList.add('d-none');

                form.dataset.updateUrl = botao.dataset.updateUrl || '';
                if (linkCompleto) { linkCompleto.href = botao.dataset.fullUrl || '#'; }

                try {
                    const resposta = await fetch(botao.dataset.showUrl, {
                        headers: { Accept: 'application/json' },
                    });
                    const dados = await resposta.json();

                    if (!resposta.ok || !dados.success) {
                        throw new Error(dados.message || 'Não foi possível carregar o cliente.');
                    }

                    const cliente = dados.client || {};
                    Object.entries(CAMPOS).forEach(([id, campo]) => {
                        const input = document.getElementById(id);
                        if (input) { input.value = cliente[campo] || ''; }
                    });

                    modalDe(modalEl)?.show();
                } catch (erro) {
                    mostrarErros(caixaErros, erro.message, {});
                    modalDe(modalEl)?.show();
                }
            });
        });

        botaoSalvar?.addEventListener('click', async () => {
            caixaErros?.classList.add('d-none');

            if (!form.reportValidity()) { return; }

            const url = form.dataset.updateUrl;
            if (!url) { return; }

            const original = botaoSalvar.innerHTML;
            botaoSalvar.disabled = true;
            botaoSalvar.textContent = 'Salvando...';

            try {
                const resposta = await fetch(url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token(),
                        // O Laravel aceita PUT por override; `fetch` com PUT e
                        // corpo JSON funcionaria, mas o override mantem o
                        // caminho identico ao dos formularios da aplicacao.
                        'X-HTTP-Method-Override': 'PUT',
                    },
                    body: JSON.stringify(Object.fromEntries(new FormData(form).entries())),
                });

                const dados = await resposta.json().catch(() => ({}));

                if (!resposta.ok || dados.success === false) {
                    mostrarErros(caixaErros, dados.message, dados.errors);
                    return;
                }

                // Recarrega: os dados fiscais desta tela vêm renderizados do
                // servidor (tomador, CPF, discriminação). Atualizar só o modal
                // deixaria a tela mostrando o dado velho.
                window.location.reload();
            } catch (erro) {
                mostrarErros(caixaErros, erro.message, {});
            } finally {
                botaoSalvar.disabled = false;
                botaoSalvar.innerHTML = original;
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
