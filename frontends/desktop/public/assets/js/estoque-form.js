/**
 * Preço sugerido no cadastro de peça — specs/037-precificacao-integrada-ao-fluxo.
 *
 * Até esta entrega o formulário não tinha uma linha de JavaScript, e o campo
 * `preco_venda` era digitação livre: o operador fazia custo × margem de cabeça.
 * Ironia registrada na spec — o campo *código* já tinha sugestão automática; o
 * preço, que é o que dá lucro, não.
 */
(function () {
    'use strict';

    const config = window.__DESKTOP_ESTOQUE_FORM;
    if (!config) return;

    const custoInput = document.getElementById('preco_custo');
    const vendaInput = document.getElementById('preco_venda');
    // Select de Subcategoria (taxonomia de estoque) — substitui o antigo
    // campo de texto livre "Categoria". A sugestão de preço continua
    // funcionando com o mesmo contrato (nome em string), só troca de onde lê.
    const categoriaInput = document.getElementById('estoque_subcategoria_id');
    const dica = document.getElementById('precoSugestao');

    if (!custoInput || !vendaInput || !dica) return;

    // Taxonomia de estoque em cascata: Categoria só mostra as do Grupo
    // escolhido, Subcategoria só as da Categoria escolhida.
    if (window.DesktopUi && typeof window.DesktopUi.bindOptionCascade === 'function') {
        window.DesktopUi.bindOptionCascade(
            document.getElementById('tipo_equipamento_id'),
            document.getElementById('estoque_categoria_id')
        );
        window.DesktopUi.bindOptionCascade(
            document.getElementById('estoque_categoria_id'),
            categoriaInput
        );
    }

    const money = (valor) => 'R$ ' + Number(valor || 0).toFixed(2).replace('.', ',');

    /**
     * A regra do "sujo".
     *
     * Nasce sujo em edição (a peça já tem preço decidido) ou quando o campo já
     * chega preenchido — inclusive por `old()` depois de um erro de validação,
     * onde sobrescrever apagaria o que o operador acabou de digitar.
     *
     * Vira sujo de forma permanente na primeira tecla. A partir daí a sugestão
     * só aparece como dica com botão; nunca escreve no campo.
     */
    let sujo = Boolean(config.edicao) || Number(vendaInput.value) > 0;

    vendaInput.addEventListener('input', () => {
        sujo = true;
    });

    const esconder = () => {
        dica.classList.add('d-none');
        dica.textContent = '';
    };

    const aplicar = (valor) => {
        vendaInput.value = Number(valor).toFixed(2);
        sujo = true;
        esconder();
        vendaInput.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const render = (simulacao) => {
        const recomendado = Number(simulacao.valor_recomendado || 0);
        const calculado = Number(simulacao.valor_calculado ?? recomendado);

        if (recomendado <= 0) {
            esconder();
            return;
        }

        // Campo vazio em peça nova: preenche e sai de cena. É o "pré-preenche"
        // que o dono pediu, sem nunca destruir digitação.
        if (!sujo) {
            vendaInput.value = recomendado.toFixed(2);
            esconder();
            return;
        }

        dica.classList.remove('d-none');
        dica.textContent = '';

        const texto = document.createElement('span');

        // `respeitar_preco_venda` faz o recomendado virar o próprio preço de
        // tabela quando este é maior que o calculado. Sem mostrar os dois, a
        // dica sugeriria exatamente o número que já está no campo e leria como
        // quebrada.
        if (calculado > 0 && Math.abs(calculado - recomendado) > 0.005) {
            texto.textContent = `Calculado ${money(calculado)} · sugerido ${money(recomendado)} `
                + '(mantém seu preço de tabela) ';
        } else {
            texto.textContent = `Sugerido ${money(recomendado)}`;

            if (simulacao.visibilidade_custo === 'completo'
                && simulacao.percentual_encargos != null
                && simulacao.percentual_margem != null) {
                texto.textContent += ` · custo ${money(simulacao.preco_custo_referencia)}`
                    + ` + encargos ${simulacao.percentual_encargos}%`
                    + ` + margem ${simulacao.percentual_margem}% `;
            } else {
                texto.textContent += ' ';
            }
        }

        const botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'btn btn-sm btn-outline-primary py-0 px-2 ms-1';
        botao.textContent = 'Aplicar';
        botao.addEventListener('click', () => aplicar(recomendado));

        dica.appendChild(texto);
        dica.appendChild(botao);
    };

    let pendente = null;

    const consultar = () => {
        const custo = Number(custoInput.value);

        if (!(custo > 0)) {
            esconder();
            return;
        }

        fetch(config.sugerirPrecoUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': config.csrf,
            },
            body: JSON.stringify({
                preco_custo: custo,
                preco_venda: Number(vendaInput.value) || 0,
                // Nome da Subcategoria escolhida, não o id: o motor de preço
                // (PrecificacaoService) casa por nome de categoria em string,
                // igual antes do campo virar select.
                categoria: categoriaInput ? (categoriaInput.selectedOptions[0]?.text ?? '') : '',
            }),
        })
            .then((resposta) => (resposta.ok ? resposta.json() : Promise.reject(resposta)))
            .then((dados) => {
                if (dados && dados.simulation) render(dados.simulation);
            })
            // Sugestão é conveniência, não requisito: falha nunca pode
            // atrapalhar o cadastro da peça.
            .catch(esconder);
    };

    // `change`/`blur`, nunca `input`: em `input` a consulta dispararia a cada
    // tecla e calcularia a sugestão a partir do "1" parcial de quem ainda está
    // digitando "129,90".
    const agendar = () => {
        window.clearTimeout(pendente);
        pendente = window.setTimeout(consultar, 400);
    };

    custoInput.addEventListener('change', agendar);
    custoInput.addEventListener('blur', agendar);
    if (categoriaInput) categoriaInput.addEventListener('change', agendar);

    // Peça nova que já chega com custo (duplicação, `old()` após erro): calcula
    // de saída, para a dica não depender de o operador tocar no campo.
    if (Number(custoInput.value) > 0) consultar();
})();
