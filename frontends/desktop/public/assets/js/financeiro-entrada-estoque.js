/**
 * Entrada de estoque no lançamento financeiro — specs/039.
 *
 * Arquivo próprio e não dentro de financeiro-form.js: aquele já passa de 1200
 * linhas e é carregado em create, edit e show; esta seção só existe em create.
 * Mesmo precedente de orders-aplicar-pecas.js.
 *
 * Responsabilidades: ligar/desligar a seção, linhas repetíveis, busca de peça,
 * cadastro rápido, sugestão de preço de venda e o confronto entre a soma dos
 * itens e o valor do lançamento.
 */
(function () {
    'use strict';

    const config = window.__DESKTOP_FINANCEIRO_ENTRADA_ESTOQUE;
    if (!config) return;

    const secao = document.getElementById('financeiroEntradaEstoqueSection');
    const corpo = document.getElementById('financeiroEntradaEstoqueCorpo');
    const linhas = document.getElementById('financeiroEntradaEstoqueLinhas');
    const template = document.getElementById('financeiroEntradaEstoqueTemplate');
    const chk = document.getElementById('financeiroEntradaEstoque');
    const btnAdicionar = document.getElementById('financeiroEntradaEstoqueAdicionar');
    const btnNovaPeca = document.getElementById('financeiroEntradaEstoqueNovaPeca');
    const resumo = document.getElementById('financeiroEntradaEstoqueResumo');

    if (!secao || !corpo || !linhas || !template || !chk) return;

    const $ = window.jQuery;
    const temSelect2 = typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function';
    const mask = window.desktopFinanceiroMask || {};

    const money = (v) => 'R$ ' + Number(v || 0).toFixed(2).replace('.', ',');
    const num = (v) => {
        const n = Number(String(v ?? '').replace(',', '.'));
        return Number.isFinite(n) ? n : 0;
    };

    // Índice sempre crescente. Reaproveitar o índice de uma linha removida faria
    // duas linhas dividirem o mesmo nome de campo, e o PHP ficaria com a última.
    let proximoIndice = linhas.querySelectorAll('[data-linha]').length;

    // ---------------------------------------------------------------- seção

    const tipoSelect = document.getElementById('financeiroTipo');
    const categoriaSelect = document.getElementById('financeiroCategoria');

    // O automatismo de marcar/desmarcar pela categoria cala assim que o operador
    // toca no switch. Nunca desmarcar um switch que ele marcou à mão.
    let switchTocado = false;
    chk.addEventListener('change', () => {
        switchTocado = true;
        sincronizarCorpo();
    });

    const ehCompraDePeca = () => {
        const nome = (categoriaSelect && categoriaSelect.value) || '';
        const grupo = (config.gruposPorCategoria || {})[nome] || '';
        return grupo === 'Custo Direto (OS)';
    };

    const sincronizarSecao = () => {
        const ehPagar = !tipoSelect || tipoSelect.value === 'pagar';
        // A seção acompanha a CATEGORIA, não só o tipo: imposto, aluguel e
        // folha são "a pagar" e nunca dão entrada em estoque. Antes ela seguia
        // toda despesa e poluía o caminho mais comum.
        const cabe = ehPagar && ehCompraDePeca();

        secao.classList.toggle('d-none', !cabe);

        if (!cabe) {
            // Desmarca ao sair: a seção escondida com o switch ligado mandaria
            // `entrada_estoque=1` sem que ninguém visse as peças na tela.
            chk.checked = false;
            // Volta a obedecer a categoria — o "não mexer no que o operador
            // marcou" só vale enquanto a seção está à vista.
            switchTocado = false;
        } else if (!switchTocado) {
            // Categoria de peça é a definição de "esta compra dá entrada".
            chk.checked = true;
        }

        sincronizarCorpo();
    };

    const sincronizarCorpo = () => {
        corpo.classList.toggle('d-none', !chk.checked);

        // Uma linha em branco de cortesia: seção ligada e tabela vazia é um
        // convite a clicar em "Adicionar peça" sem motivo.
        if (chk.checked && linhas.querySelectorAll('[data-linha]').length === 0) {
            adicionarLinha();
        }

        recalcular();
    };

    // Select2 dispara `change` só via jQuery — um addEventListener puro passaria
    // no teste e nunca dispararia no navegador (ver financeiro-form.js).
    const bindChange = (el, handler) => {
        if (!el) return;
        el.addEventListener('change', handler);
        if (temSelect2) $(el).on('change', handler);
    };

    bindChange(tipoSelect, sincronizarSecao);
    bindChange(categoriaSelect, sincronizarSecao);

    // ---------------------------------------------------------------- linhas

    function adicionarLinha() {
        const html = template.innerHTML.replace(/__INDEX__/g, String(proximoIndice));
        proximoIndice += 1;

        const wrapper = document.createElement('tbody');
        wrapper.innerHTML = html.trim();
        const linha = wrapper.querySelector('[data-linha]');
        if (!linha) return null;

        linhas.appendChild(linha);
        prepararLinha(linha);
        recalcular();

        return linha;
    }

    if (btnAdicionar) btnAdicionar.addEventListener('click', () => adicionarLinha());

    linhas.addEventListener('click', (e) => {
        const botao = e.target.closest('[data-acao="remover"]');
        if (!botao) return;

        const linha = botao.closest('[data-linha]');
        if (!linha) return;

        if (temSelect2) {
            const sel = linha.querySelector('[data-campo="peca"]');
            if (sel) $(sel).select2('destroy');
        }

        linha.remove();
        recalcular();
    });

    function prepararLinha(linha) {
        const peca = linha.querySelector('[data-campo="peca"]');
        const qtd = linha.querySelector('[data-campo="quantidade"]');
        const custoDisplay = linha.querySelector('[data-campo="custo-display"]');
        const custoHidden = linha.querySelector('[data-campo="custo"]');

        if (custoDisplay && custoHidden) {
            if (custoHidden.value !== '' && mask.rawToDisplay) {
                custoDisplay.value = mask.rawToDisplay(custoHidden.value);
            }

            custoDisplay.addEventListener('input', () => {
                if (mask.applyMaskFromDigits) mask.applyMaskFromDigits(custoDisplay, custoHidden);
                recalcular();
            });

            custoDisplay.addEventListener('blur', () => {
                agendarSugestao(linha);
                recalcular();
            });
        }

        if (qtd) qtd.addEventListener('input', recalcular);

        if (peca) initBuscaPeca(peca, linha);
    }

    function initBuscaPeca(select, linha) {
        if (!temSelect2) return;

        $(select).select2({
            width: '100%',
            placeholder: select.dataset.placeholder || 'Busque a peça',
            allowClear: true,
            ajax: {
                url: config.partSearchUrl,
                dataType: 'json',
                delay: 250,
                data: (params) => ({ q: params.term || '', page: params.page || 1 }),
                processResults: (data, params) => ({
                    results: (data.parts || []).map((p) => ({ ...p, id: p.id, text: p.text })),
                    pagination: { more: Boolean(data.pagination && data.pagination.has_more) },
                }),
                cache: true,
            },
        });

        $(select).on('select2:select', (e) => {
            const peca = e.params.data || {};
            aplicarPecaNaLinha(linha, peca);
        });

        $(select).on('select2:clear', () => {
            limparInfoDaLinha(linha);
            recalcular();
        });
    }

    /**
     * Preenche a linha com o que já se sabe da peça. Menos digitação é o que
     * decide a adoção — é a lição que a 038 registrou.
     */
    function aplicarPecaNaLinha(linha, peca) {
        linha.dataset.saldoAtual = String(peca.saldo || 0);
        linha.dataset.custoAtual = String(peca.preco_custo || 0);
        // categoria_efetiva: nome da Subcategoria da árvore nova, com o texto
        // legado como fallback para peças nunca reclassificadas. Sem isto,
        // toda peça classificada só pela taxonomia nova ficaria com a
        // sugestão de preço sem nenhuma categoria para casar.
        linha.dataset.categoria = String(peca.categoria_efetiva || peca.categoria || '');
        // Peça que já existe no cadastro já teve seu preço decidido por alguém:
        // nasce "suja" e a sugestão nunca escreve sozinha nela.
        linha.dataset.vendaSuja = Number(peca.preco_venda) > 0 ? '1' : '';

        const custoDisplay = linha.querySelector('[data-campo="custo-display"]');
        const custoHidden = linha.querySelector('[data-campo="custo"]');

        if (custoDisplay && custoHidden && custoHidden.value === '' && Number(peca.preco_custo) > 0) {
            custoHidden.value = Number(peca.preco_custo).toFixed(2);
            if (mask.rawToDisplay) custoDisplay.value = mask.rawToDisplay(custoHidden.value);
        }

        atualizarInfoDaLinha(linha);
        agendarSugestao(linha);
        recalcular();
    }

    function limparInfoDaLinha(linha) {
        delete linha.dataset.saldoAtual;
        delete linha.dataset.custoAtual;
        delete linha.dataset.vendaSuja;

        ['saldo', 'custo-atual', 'sugestao'].forEach((campo) => {
            const el = linha.querySelector(`[data-campo="${campo}"]`);
            if (el) el.textContent = '';
        });
    }

    function atualizarInfoDaLinha(linha) {
        const saldoEl = linha.querySelector('[data-campo="saldo"]');
        const custoAtualEl = linha.querySelector('[data-campo="custo-atual"]');

        const saldo = num(linha.dataset.saldoAtual);
        const qtd = num((linha.querySelector('[data-campo="quantidade"]') || {}).value);

        if (saldoEl && linha.dataset.saldoAtual !== undefined) {
            saldoEl.textContent = qtd > 0
                ? `${saldo} em estoque → ${saldo + qtd}`
                : `${saldo} em estoque`;
        }

        // A divergência de custo tem de ser visível ANTES de salvar: o cadastro
        // vai ser sobrescrito por este número.
        if (custoAtualEl) {
            const custoAtual = num(linha.dataset.custoAtual);
            const custoNovo = num((linha.querySelector('[data-campo="custo"]') || {}).value);

            if (custoAtual > 0 && custoNovo > 0 && Math.abs(custoAtual - custoNovo) > 0.005) {
                custoAtualEl.textContent = `custo atual ${money(custoAtual)} → ${money(custoNovo)}`;
                custoAtualEl.classList.add('text-warning');
            } else {
                custoAtualEl.textContent = '';
                custoAtualEl.classList.remove('text-warning');
            }
        }
    }

    // ------------------------------------------------------- sugestão de preço

    const pendentes = new WeakMap();

    /**
     * `change`/`blur` com atraso, nunca `input`: em `input` a consulta calcularia
     * a sugestão a partir do "1" parcial de quem ainda está digitando "129,90".
     * Mesma regra de estoque-form.js.
     */
    function agendarSugestao(linha) {
        window.clearTimeout(pendentes.get(linha));
        pendentes.set(linha, window.setTimeout(() => consultarSugestao(linha), 400));
    }

    function consultarSugestao(linha) {
        const dica = linha.querySelector('[data-campo="sugestao"]');
        const pecaId = num((linha.querySelector('[data-campo="peca"]') || {}).value);
        const custo = num((linha.querySelector('[data-campo="custo"]') || {}).value);

        if (!dica || !(custo > 0)) {
            if (dica) dica.textContent = '';
            return;
        }

        fetch(config.suggestPriceUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': config.csrf,
            },
            body: JSON.stringify({
                peca_id: pecaId || null,
                preco_custo: custo,
                categoria: linha.dataset.categoria || '',
            }),
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((d) => {
                if (d && d.simulation) renderSugestao(linha, d.simulation);
            })
            // Sugestão é conveniência, não requisito: falha nunca pode atrapalhar
            // o lançamento.
            .catch(() => { dica.textContent = ''; });
    }

    function renderSugestao(linha, simulacao) {
        const dica = linha.querySelector('[data-campo="sugestao"]');
        const hidden = linha.querySelector('[data-campo="preco-venda"]');
        const recomendado = Number(simulacao.valor_recomendado || 0);

        if (!dica || recomendado <= 0) {
            if (dica) dica.textContent = '';
            return;
        }

        dica.textContent = '';

        // Peça nova, sem preço decidido: pré-preenche e sai de cena. É o
        // "pré-preenche sem nunca destruir digitação" da 037.
        if (!linha.dataset.vendaSuja) {
            if (hidden) hidden.value = recomendado.toFixed(2);
            dica.textContent = `venda ${money(recomendado)}`;
            dica.className = 'd-block mt-1 text-success';
            return;
        }

        dica.className = 'd-block mt-1';

        const texto = document.createElement('span');
        texto.className = 'text-muted';
        texto.textContent = `sugerido ${money(recomendado)} `;

        const botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'btn btn-sm btn-outline-primary py-0 px-1';
        botao.textContent = 'Aplicar';
        botao.addEventListener('click', () => {
            if (hidden) hidden.value = recomendado.toFixed(2);
            dica.textContent = `venda ${money(recomendado)}`;
            dica.className = 'd-block mt-1 text-success';
        });

        dica.appendChild(texto);
        dica.appendChild(botao);
    }

    // ---------------------------------------------------------------- resumo

    const valorHidden = document.getElementById('financeiroValorHidden');
    const valorDisplay = document.getElementById('financeiroValorDisplay');

    // Enquanto o operador não digitar no valor do lançamento, a soma das peças o
    // preenche. Na primeira digitação manual, para de mexer.
    let valorSujo = Boolean(valorHidden && num(valorHidden.value) > 0);
    if (valorDisplay) valorDisplay.addEventListener('input', () => { valorSujo = true; });

    function recalcular() {
        let soma = 0;

        linhas.querySelectorAll('[data-linha]').forEach((linha) => {
            const qtd = num((linha.querySelector('[data-campo="quantidade"]') || {}).value);
            const custo = num((linha.querySelector('[data-campo="custo"]') || {}).value);
            const total = qtd * custo;
            soma += total;

            const totalEl = linha.querySelector('[data-campo="total"]');
            if (totalEl) totalEl.textContent = money(total);

            atualizarInfoDaLinha(linha);
        });

        if (!valorSujo && chk.checked && soma > 0 && valorHidden) {
            valorHidden.value = soma.toFixed(2);
            if (valorDisplay && mask.rawToDisplay) valorDisplay.value = mask.rawToDisplay(valorHidden.value);
        }

        renderResumo(soma);
    }

    function renderResumo(soma) {
        if (!resumo) return;

        const valor = num(valorHidden && valorHidden.value);
        const itensEl = resumo.querySelector('[data-resumo="itens"]');
        const lancEl = resumo.querySelector('[data-resumo="lancamento"]');
        const difEl = resumo.querySelector('[data-resumo="diferenca"]');

        if (itensEl) itensEl.textContent = money(soma);
        if (lancEl) lancEl.textContent = money(valor);
        if (!difEl) return;

        const diferenca = Math.round((valor - soma) * 100) / 100;

        if (!chk.checked || soma <= 0 || valor <= 0 || Math.abs(diferenca) < 0.01) {
            difEl.textContent = '';
            difEl.className = 'ms-2';
            bloquearEnvio(false);
            return;
        }

        if (diferenca < 0) {
            // Comprou mais do que pagou: é digitação errada. O servidor também
            // recusa (UpsertFinanceiroRequest) — aqui é só o aviso adiantado.
            difEl.textContent = `As peças somam ${money(-diferenca)} a mais que o lançamento.`;
            difEl.className = 'ms-2 text-danger fw-semibold';
            bloquearEnvio(true);
            return;
        }

        // Diferença para menos é normal: frete, imposto, item que não é peça.
        difEl.textContent = `${money(diferenca)} do lançamento não é peça (frete, imposto?).`;
        difEl.className = 'ms-2 text-warning';
        bloquearEnvio(false);
    }

    function bloquearEnvio(bloquear) {
        const form = secao.closest('form');
        if (!form) return;

        const submit = form.querySelector('button[type="submit"]');
        if (submit) submit.disabled = bloquear;
    }

    // ------------------------------------------------------- cadastro rápido

    if (btnNovaPeca && config.quickStoreUrl) {
        btnNovaPeca.addEventListener('click', abrirModalPeca);
    }

    // Taxonomia de estoque em cascata (Grupo → Categoria → Subcategoria),
    // obrigatória no cadastro rápido de peça — mesmo helper usado no
    // formulário cheio de Estoque e no modal do orçamento.
    if (window.DesktopUi && typeof window.DesktopUi.bindOptionCascade === 'function') {
        window.DesktopUi.bindOptionCascade(
            document.getElementById('quickPecaGrupo'),
            document.getElementById('quickPecaCategoria')
        );
        window.DesktopUi.bindOptionCascade(
            document.getElementById('quickPecaCategoria'),
            document.getElementById('quickPecaSubcategoria')
        );
    }

    function abrirModalPeca() {
        const modalEl = document.getElementById('financeiroPecaQuickModal');
        if (!modalEl || typeof window.bootstrap === 'undefined') return;

        const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        const erro = modalEl.querySelector('[data-quick-peca="erro"]');
        if (erro) erro.classList.add('d-none');

        const salvar = modalEl.querySelector('[data-quick-peca="salvar"]');
        if (salvar) {
            salvar.onclick = () => salvarPecaRapida(modalEl, modal);
        }

        modal.show();
    }

    function salvarPecaRapida(modalEl, modal) {
        const campo = (nome) => modalEl.querySelector(`[data-quick-peca="${nome}"]`);
        const erro = campo('erro');
        const nome = (campo('nome') || {}).value || '';
        // Taxonomia de estoque obrigatória (decisão do cliente): sem ela nem
        // dispara a chamada — mesmo texto de erro que o backend devolveria,
        // só que sem round-trip.
        const subcategoriaId = (campo('estoque_subcategoria_id') || {}).value || '';

        if (nome.trim() === '') {
            if (erro) {
                erro.textContent = 'Informe o nome da peça.';
                erro.classList.remove('d-none');
            }
            return;
        }

        if (subcategoriaId === '') {
            if (erro) {
                erro.textContent = 'Selecione Grupo, Categoria e Subcategoria da peça.';
                erro.classList.remove('d-none');
            }
            return;
        }

        const custoTexto = (campo('preco_custo') || {}).value || '';
        const custo = mask.displayToRaw ? mask.displayToRaw(custoTexto) : '';

        fetch(config.quickStoreUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': config.csrf,
            },
            body: JSON.stringify({
                nome: nome.trim(),
                codigo: ((campo('codigo') || {}).value || '').trim(),
                estoque_subcategoria_id: Number(subcategoriaId),
                unidade: ((campo('unidade') || {}).value || 'UN').trim(),
                preco_custo: custo === '' ? null : Number(custo),
                // ⚠️ SEMPRE ZERO. A quantidade comprada entra pela movimentação
                // que o lançamento gera ao salvar. `estoque.quick.store` grava
                // `quantidade_atual` direto, sem movimentação — mandar a
                // quantidade aqui faria o saldo contar DUAS VEZES, em silêncio.
                quantidade_atual: 0,
            }),
        })
            .then((r) => r.json().then((d) => ({ ok: r.ok, d })))
            .then(({ ok, d }) => {
                if (!ok || !d.success) {
                    if (erro) {
                        erro.textContent = d.message || 'Não foi possível cadastrar a peça.';
                        erro.classList.remove('d-none');
                    }
                    return;
                }

                usarPecaRecemCadastrada(d.part || {});
                modal.hide();
                limparModal(modalEl);
            })
            .catch(() => {
                if (erro) {
                    erro.textContent = 'Não foi possível cadastrar a peça agora.';
                    erro.classList.remove('d-none');
                }
            });
    }

    function usarPecaRecemCadastrada(part) {
        // Reaproveita uma linha ainda sem peça, se houver — o operador clicou
        // "cadastrar peça nova" justamente porque a busca dela não achou nada.
        let alvo = Array.from(linhas.querySelectorAll('[data-linha]')).find((l) => {
            const sel = l.querySelector('[data-campo="peca"]');
            return sel && !sel.value;
        });

        if (!alvo) alvo = adicionarLinha();
        if (!alvo) return;

        const select = alvo.querySelector('[data-campo="peca"]');
        if (!select) return;

        const id = Number(part.id || 0);
        const codigo = String(part.codigo || '');
        const nome = String(part.nome || '');
        const label = codigo !== '' ? `${codigo} — ${nome}` : nome;

        const option = new Option(label, String(id), true, true);
        select.appendChild(option);
        if (temSelect2) $(select).trigger('change');

        aplicarPecaNaLinha(alvo, {
            id,
            saldo: 0,
            preco_custo: Number(part.preco_custo || 0),
            // Peça recém-criada sem preço de venda é "limpa": a sugestão pode
            // pré-preencher sem passar por cima de decisão de ninguém.
            preco_venda: Number(part.preco_venda || 0),
            categoria_efetiva: String(part.categoria_efetiva || part.categoria || ''),
        });
    }

    function limparModal(modalEl) {
        ['nome', 'codigo', 'tipo_equipamento_id', 'estoque_categoria_id', 'estoque_subcategoria_id', 'preco_custo'].forEach((nome) => {
            const el = modalEl.querySelector(`[data-quick-peca="${nome}"]`);
            if (!el) return;

            el.value = '';
            // Selects de taxonomia usam Select2: resetar `.value` sozinho não
            // atualiza o texto exibido no controle.
            if (el.tagName === 'SELECT' && temSelect2) $(el).trigger('change');
        });

        const unidade = modalEl.querySelector('[data-quick-peca="unidade"]');
        if (unidade) unidade.value = 'UN';
    }

    // ------------------------------------------------------------------ boot

    linhas.querySelectorAll('[data-linha]').forEach(prepararLinha);
    sincronizarSecao();
})();
