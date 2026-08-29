/**
 * Preço sugerido e cadeia de custo no cadastro de serviço — specs/037, Fase 3.
 *
 * Até esta entrega `valor`, `tempo_padrao_horas` e `custo_direto_padrao` eram
 * três inputs soltos que nunca conversavam: o formulário não tinha uma linha de
 * JavaScript, e `tempo_padrao_horas` era praticamente morto — existia e nenhum
 * cálculo real o lia.
 *
 * A cadeia renderizada aqui é literalmente a saída que buildServiceQuote() já
 * produzia. Nada novo é calculado no cliente.
 */
(function () {
    'use strict';

    const config = window.__DESKTOP_SERVICO_FORM;
    if (!config) return;

    const valorInput = document.getElementById('valor');
    const tempoInput = document.getElementById('tempo_padrao_horas');
    const custoInput = document.getElementById('custo_direto_padrao');
    const tipoInput = document.getElementById('tipo_equipamento');
    const dica = document.getElementById('precoSugestao');
    const cadeia = document.getElementById('cadeiaCusto');

    if (!valorInput || !dica || !cadeia) return;

    const money = (v) => 'R$ ' + Number(v || 0).toFixed(2).replace('.', ',');
    const num = (v) => Number(v || 0).toFixed(2).replace('.', ',');

    // Mesma regra do cadastro de peça: nasce sujo em edição e quando o campo já
    // chega preenchido (inclusive por `old()` após erro de validação, onde
    // sobrescrever apagaria o que o operador acabou de digitar).
    let sujo = Boolean(config.edicao) || Number(valorInput.value) > 0;
    valorInput.addEventListener('input', () => { sujo = true; });

    const esconder = () => {
        dica.classList.add('d-none');
        dica.textContent = '';
        cadeia.classList.add('d-none');
        cadeia.textContent = '';
    };

    const linha = (rotulo, valor, forte) => {
        const div = document.createElement('div');
        div.className = 'd-flex justify-content-between' + (forte ? ' fw-semibold border-top pt-1 mt-1' : '');
        const a = document.createElement('span');
        a.className = 'text-secondary';
        a.textContent = rotulo;
        const b = document.createElement('span');
        b.textContent = valor;
        div.appendChild(a);
        div.appendChild(b);
        return div;
    };

    const renderCadeia = (s) => {
        cadeia.textContent = '';
        cadeia.classList.remove('d-none');

        const titulo = document.createElement('p');
        titulo.className = 'surface-subtitle mb-2';
        titulo.textContent = 'Como o piso é formado';
        cadeia.appendChild(titulo);

        // Sem permissão financeira o backend redige a composição: sobra o piso,
        // que é o que o operador precisa para não vender no prejuízo.
        if (s.visibilidade_custo !== 'completo') {
            cadeia.appendChild(linha('Piso do serviço', money(s.preco_minimo), true));
            return;
        }

        const tempo = Number(s.tempo_padrao_horas || 0);
        const custoHora = Number(s.custo_hora_produtiva || 0);

        cadeia.appendChild(linha(
            `Mão de obra (${num(tempo)} h × ${money(custoHora)})`,
            money(s.custo_mao_obra)
        ));
        cadeia.appendChild(linha('Materiais por execução', money(s.custo_direto_total)));

        if (Number(s.valor_risco) > 0) {
            cadeia.appendChild(linha(`Risco ${num(s.risco_percentual)}%`, money(s.valor_risco)));
        }

        cadeia.appendChild(linha('Custo total', money(s.custo_total), true));
        cadeia.appendChild(linha(
            `Piso (÷ 1 − margem ${num(s.margem_percentual)}% − taxa ${num(s.taxa_recebimento_percentual)}% − imposto ${num(s.imposto_percentual)}%)`,
            money(s.preco_minimo),
            true
        ));

        // Procedência do custo-hora: sem isso o operador vê um número e não
        // sabe se ele veio dos custos fixos reais ou de um default esquecido.
        const rodape = document.createElement('p');
        rodape.className = 'form-text mb-0 mt-2';

        if (s.custo_hora_origem === 'calculado' && s.custo_hora_confiavel) {
            rodape.textContent = 'Custo-hora calculado dos seus custos fixos lançados.';
        } else if (s.custo_hora_motivo === 'SEM_CUSTO_FIXO_LANCADO') {
            rodape.className += ' text-warning';
            rodape.textContent = 'Sem custos fixos lançados no período: usando o custo-hora manual. '
                + 'Lance aluguel, energia e folha como despesa fixa para o cálculo valer.';
        } else if (s.custo_hora_motivo === 'CAPACIDADE_NAO_CONFIGURADA') {
            rodape.className += ' text-warning';
            rodape.textContent = 'Capacidade da bancada não configurada: usando o custo-hora manual.';
        } else if (s.custo_hora_motivo === 'FORA_DA_FAIXA_ESPERADA') {
            rodape.className += ' text-warning';
            rodape.textContent = 'O custo-hora calculado ficou muito distante do manual — confira a capacidade e os custos fixos.';
        }

        if (rodape.textContent) cadeia.appendChild(rodape);
    };

    const render = (s) => {
        const recomendado = Number(s.valor_recomendado || 0);

        if (recomendado <= 0) {
            esconder();
            return;
        }

        renderCadeia(s);

        if (!sujo) {
            valorInput.value = recomendado.toFixed(2);
            dica.classList.add('d-none');
            return;
        }

        dica.classList.remove('d-none');
        dica.textContent = '';

        const texto = document.createElement('span');
        texto.textContent = `Sugerido ${money(recomendado)} `;

        const botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'btn btn-sm btn-outline-primary py-0 px-2 ms-1';
        botao.textContent = 'Aplicar';
        botao.addEventListener('click', () => {
            valorInput.value = recomendado.toFixed(2);
            sujo = true;
            dica.classList.add('d-none');
        });

        dica.appendChild(texto);
        dica.appendChild(botao);
    };

    let pendente = null;

    const consultar = () => {
        const tempo = Number(tempoInput ? tempoInput.value : 0);
        const custo = Number(custoInput ? custoInput.value : 0);

        // Sem tempo nem material não há o que compor.
        if (!(tempo > 0) && !(custo > 0)) {
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
                tempo_padrao_horas: tempo,
                custo_direto_padrao: custo,
                valor_cadastro: Number(valorInput.value) || 0,
                tipo_equipamento: tipoInput ? tipoInput.value : '',
            }),
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((d) => { if (d && d.simulation) render(d.simulation); })
            .catch(esconder);
    };

    // `change`/`blur`, nunca `input`: em `input` a consulta dispararia a cada
    // tecla e calcularia a partir do valor parcial de quem ainda está digitando.
    const agendar = () => {
        window.clearTimeout(pendente);
        pendente = window.setTimeout(consultar, 400);
    };

    [tempoInput, custoInput, tipoInput].forEach((el) => {
        if (!el) return;
        el.addEventListener('change', agendar);
        el.addEventListener('blur', agendar);
    });

    if (Number(tempoInput ? tempoInput.value : 0) > 0 || Number(custoInput ? custoInput.value : 0) > 0) {
        consultar();
    }
})();
