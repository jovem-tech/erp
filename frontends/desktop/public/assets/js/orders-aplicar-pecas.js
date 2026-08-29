/**
 * Aplicação de peças na OS — specs/038.
 *
 * O botão substitui um alerta que era passivo: mandava "registre a movimentação"
 * e deixava o técnico procurar onde. Trocar texto passivo por ação é a diferença
 * entre o dado existir e não existir — e é o CMV que depende disso.
 */
(function () {
    'use strict';

    const config = window.__DESKTOP_OS_ESTOQUE;
    const botao = document.getElementById('osAplicarPecasBtn');
    const modalEl = document.getElementById('osAplicarPecasModal');

    if (!config || !botao || !modalEl) return;

    const corpo = document.getElementById('osAplicarPecasCorpo');
    const erro = document.getElementById('osAplicarPecasErro');
    const salvar = document.getElementById('osAplicarPecasSalvar');
    const confirmarBox = document.getElementById('osAplicarPecasConfirmarBox');
    const confirmar = document.getElementById('osAplicarPecasConfirmar');
    const modal = new bootstrap.Modal(modalEl);

    const osId = botao.dataset.osId;
    const url = (template) => template.replaceAll('__ORDER__', String(osId));
    const num = (v) => Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 4 });

    const mostrarErro = (mensagem) => {
        erro.classList.remove('d-none');
        erro.textContent = mensagem;
    };

    const limparErro = () => {
        erro.classList.add('d-none');
        erro.textContent = '';
        confirmarBox.classList.add('d-none');
        confirmar.checked = false;
    };

    const render = (itens) => {
        corpo.textContent = '';

        if (!itens.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 5;
            td.className = 'text-secondary';
            td.textContent = 'Nenhuma peça no orçamento aprovado desta OS.';
            tr.appendChild(td);
            corpo.appendChild(tr);
            salvar.disabled = true;
            return;
        }

        salvar.disabled = false;

        itens.forEach((item) => {
            const tr = document.createElement('tr');
            tr.dataset.pecaId = item.peca_id;

            const nome = document.createElement('td');
            nome.innerHTML = `<div class="fw-semibold">${item.nome}</div>`
                + (item.codigo ? `<small class="text-secondary">${item.codigo}</small>` : '');

            const orcada = document.createElement('td');
            orcada.className = 'text-end';
            orcada.textContent = `${num(item.quantidade_orcada)} ${item.unidade || ''}`.trim();

            const baixada = document.createElement('td');
            baixada.className = 'text-end';
            baixada.textContent = num(item.quantidade_baixada);

            const saldo = document.createElement('td');
            saldo.className = 'text-end' + (Number(item.saldo_estoque) < Number(item.quantidade_sugerida) ? ' text-danger fw-semibold' : '');
            saldo.textContent = num(item.saldo_estoque);

            const aplicar = document.createElement('td');
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'form-control form-control-sm text-end';
            input.min = '0';
            input.step = 'any';
            // Pré-preenchido com o que FALTA aplicar: zero digitação no caso comum.
            input.value = item.quantidade_sugerida;
            input.dataset.campo = 'quantidade';
            aplicar.appendChild(input);

            [nome, orcada, baixada, saldo, aplicar].forEach((td) => tr.appendChild(td));
            corpo.appendChild(tr);
        });
    };

    botao.addEventListener('click', () => {
        limparErro();
        corpo.innerHTML = '<tr><td colspan="5" class="text-secondary">Carregando…</td></tr>';
        modal.show();

        fetch(url(config.contextoUrlTemplate), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((dados) => render(Array.isArray(dados.itens) ? dados.itens : []))
            .catch(() => {
                corpo.textContent = '';
                mostrarErro('Não foi possível carregar as peças desta OS.');
                salvar.disabled = true;
            });
    });

    salvar.addEventListener('click', () => {
        const itens = [];

        corpo.querySelectorAll('tr[data-peca-id]').forEach((tr) => {
            const quantidade = Number(tr.querySelector('[data-campo="quantidade"]').value);
            if (quantidade > 0) {
                itens.push({ peca_id: Number(tr.dataset.pecaId), quantidade });
            }
        });

        if (!itens.length) {
            mostrarErro('Informe a quantidade de pelo menos uma peça.');
            return;
        }

        salvar.disabled = true;

        fetch(url(config.aplicarUrlTemplate), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
            },
            body: JSON.stringify({
                itens,
                confirmar_estoque_insuficiente: confirmar.checked,
            }),
        })
            .then(async (r) => {
                const dados = await r.json().catch(() => ({}));
                if (!r.ok) throw dados;
                return dados;
            })
            .then(() => window.location.reload())
            .catch((dados) => {
                salvar.disabled = false;

                const faltas = dados && dados.details && dados.details.itens;

                if (Array.isArray(faltas) && faltas.length) {
                    // Nomeia os ofensores: erro que não diz qual peça faltou
                    // obriga o técnico a caçar linha por linha.
                    mostrarErro(
                        'Sem saldo para: '
                        + faltas.map((f) => `${f.nome || f.codigo} (tem ${num(f.disponivel)}, pediu ${num(f.solicitado)})`).join('; ')
                    );
                    confirmarBox.classList.remove('d-none');
                    return;
                }

                mostrarErro((dados && dados.error) || 'Não foi possível aplicar as peças.');
            });
    });
})();
