/**
 * PDV de balcão — specs/027-vendas-balcao-pdv.
 *
 * O carrinho vive só no navegador: a venda é um único POST atômico. Não há
 * rascunho no servidor, então nada aqui precisa sincronizar estado remoto.
 */
(function () {
    'use strict';

    const parseNumber = (value) => {
        if (value === null || value === undefined || value === '') return 0;
        const normalized = String(value).replace(/[^\d,.-]/g, '');
        if (normalized.includes(',')) {
            return Number(normalized.replace(/\./g, '').replace(',', '.')) || 0;
        }
        return Number(normalized) || 0;
    };

    const money = (value) => 'R$ ' + (Number(value) || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const setText = (el, text) => {
        if (el) el.textContent = text;
    };

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('pdvForm');
        if (!form) return;

        const $ = window.jQuery;
        const cartoes = JSON.parse(form.dataset.cartoes || '{}');
        const formas = JSON.parse(form.dataset.formas || '[]');
        const contasPadrao = JSON.parse(form.dataset.contasPadrao || '{}');
        const taxas = Array.isArray(cartoes.taxas) ? cartoes.taxas : [];
        const operadoras = Array.isArray(cartoes.operadoras) ? cartoes.operadoras : [];
        const bandeiras = Array.isArray(cartoes.bandeiras) ? cartoes.bandeiras : [];

        const buscaInput = document.getElementById('pdvBusca');
        const resultadosBox = document.getElementById('pdvResultados');
        const itensBody = document.getElementById('pdvItens');
        const semItens = document.getElementById('pdvSemItens');
        const pagamentosBox = document.getElementById('pdvPagamentos');
        // "Abrir pagamento" só decide SE dá pra prosseguir (carrinho não vazio)
        // e dispara o modal via data-bs-toggle; quem manda a venda de verdade
        // é o botão dentro do modal.
        const abrirPagamentoBtn = document.getElementById('pdvAbrirPagamento');
        const confirmarBtn = document.getElementById('pdvConfirmarFinalizar');
        const pagamentoModalEl = document.getElementById('pdvPagamentoModal');
        const modeloItem = document.getElementById('pdvModeloItem');
        const modeloPagamento = document.getElementById('pdvModeloPagamento');

        const descontoValor = document.getElementById('pdvDescontoValor');
        const descontoPercentual = document.getElementById('pdvDescontoPercentual');
        const descontoTipo = document.getElementById('pdvDescontoTipo');
        const descontoToggle = document.getElementById('pdvDescontoToggle');

        const clienteSelect = document.getElementById('pdvCliente');
        const clienteNomeInput = document.getElementById('pdvClienteNome');
        const clienteDocumentoInput = document.getElementById('pdvClienteDocumento');
        const observacoesInput = document.getElementById('pdvObservacoes');
        const cancelarVendaBtn = document.getElementById('pdvCancelarVenda');

        // Saldo conhecido por peça, alimentado pela busca. Serve só para o aviso
        // na tela — a verdade é conferida pelo backend dentro da transação.
        const saldoPorPeca = new Map();
        let ultimosResultados = [];
        let submitLiberado = false;

        /* ------------------------------------------------------------------ */
        /* Itens                                                               */
        /* ------------------------------------------------------------------ */

        const reindexarItens = () => {
            itensBody.querySelectorAll('.pdv-item').forEach((linha, indice) => {
                linha.querySelectorAll('[data-campo]').forEach((campo) => {
                    campo.name = `itens[${indice}][${campo.dataset.campo}]`;
                });
            });
        };

        const totalDaLinha = (linha) => {
            const quantidade = parseNumber(linha.querySelector('[data-campo="quantidade"]').value);
            const unitario = parseNumber(linha.querySelector('[data-campo="valor_unitario"]').value);
            const desconto = parseNumber(linha.querySelector('[data-campo="desconto"]').value);

            return Math.max(0, Math.round((quantidade * unitario - desconto) * 100) / 100);
        };

        const adicionarItem = (item) => {
            const fragmento = modeloItem.content.cloneNode(true);
            const linha = fragmento.querySelector('.pdv-item');

            linha.querySelector('[data-campo="tipo_item"]').value = item.tipo_item || 'avulso';
            linha.querySelector('[data-campo="referencia_id"]').value = item.referencia_id || '';
            linha.querySelector('[data-campo="baixa_estoque"]').value = item.controla_estoque ? '1' : '0';
            linha.querySelector('[data-campo="valor_unitario"]').value =
                (Number(item.valor_unitario) || 0).toFixed(2).replace('.', ',');

            const descricaoInput = linha.querySelector('[data-campo="descricao"]');
            descricaoInput.value = item.descricao || '';

            const rotulo = linha.querySelector('.pdv-item-descricao');
            const meta = linha.querySelector('.pdv-item-meta');

            if (item.tipo_item === 'avulso') {
                // Item sem cadastro: a descrição é digitada na hora.
                rotulo.classList.add('d-none');
                descricaoInput.classList.remove('d-none');
                setText(meta, 'Item avulso');
            } else {
                setText(rotulo, item.descricao || '');
                const partes = [];
                if (item.codigo) partes.push(item.codigo);
                if (item.controla_estoque) partes.push(`Saldo: ${item.saldo}`);
                setText(meta, partes.join(' · '));
            }

            if (item.referencia_id && item.controla_estoque) {
                saldoPorPeca.set(Number(item.referencia_id), Number(item.saldo) || 0);
            }

            // Cotação vinda do backend, já redigida pela permissão de quem
            // buscou: `custo_unitario` só existe para quem pode ver número.
            // Guardada no dataset para o recálculo ser local — `recalcular()`
            // roda a cada tecla e não pode fazer fetch.
            linha.dataset.custoUnitario = item.custo_unitario == null ? '' : String(item.custo_unitario);
            linha.dataset.precoMinimo = item.preco_minimo == null ? '' : String(item.preco_minimo);
            linha.dataset.faixa = item.faixa || 'indefinido';

            itensBody.appendChild(fragmento);
            reindexarItens();
            recalcular();

            const adicionada = itensBody.lastElementChild;
            const alvo = item.tipo_item === 'avulso'
                ? adicionada.querySelector('[data-campo="descricao"]')
                : adicionada.querySelector('[data-campo="quantidade"]');
            if (alvo) alvo.focus();
        };

        /* ------------------------------------------------------------------ */
        /* Pagamentos                                                          */
        /* ------------------------------------------------------------------ */

        const preencherSelect = (select, opcoes, placeholder) => {
            select.innerHTML = '';
            if (placeholder) {
                const vazio = document.createElement('option');
                vazio.value = '';
                vazio.textContent = placeholder;
                select.appendChild(vazio);
            }
            opcoes.forEach((opcao) => {
                const option = document.createElement('option');
                option.value = opcao.value;
                option.textContent = opcao.label;
                select.appendChild(option);
            });
        };

        const formaEhCartao = (codigo) => {
            const forma = formas.find((item) => item.value === codigo);
            return Boolean(forma && forma.is_cartao);
        };

        const atualizarBlocoPagamento = (bloco) => {
            const forma = bloco.querySelector('[data-campo="forma_pagamento"]').value;
            const blocoDinheiro = bloco.querySelector('.pdv-bloco-dinheiro');
            const blocoCartao = bloco.querySelector('.pdv-bloco-cartao');

            blocoDinheiro.classList.toggle('d-none', forma !== 'dinheiro');
            blocoCartao.classList.toggle('d-none', !formaEhCartao(forma));

            // Pré-seleciona a conta configurada em Financeiro > Contas para esta
            // forma. Sem isso o operador só descobriria que precisa escolher ao
            // receber "Selecione a conta financeira..." do backend, já com o
            // carrinho montado. Forma sem padrão fica em branco e o `required`
            // do select cobra a escolha antes do envio.
            const contaSelect = bloco.querySelector('[data-campo="conta_financeira_id"]');
            const padrao = contasPadrao[forma];
            if (padrao && !contaSelect.value) {
                contaSelect.value = String(padrao);
                if ($) $(contaSelect).trigger('change.select2');
            }

            if (formaEhCartao(forma)) {
                atualizarParcelas(bloco);
            }
        };

        const atualizarParcelas = (bloco) => {
            const operadoraId = Number(bloco.querySelector('[data-campo="operadora_id"]').value) || null;
            const bandeiraId = Number(bloco.querySelector('[data-campo="bandeira_id"]').value) || null;
            const modalidade = bloco.querySelector('[data-campo="modalidade"]').value;
            const parcelasSelect = bloco.querySelector('[data-campo="parcelas"]');
            const valor = parseNumber(bloco.querySelector('[data-campo="valor"]').value);

            const faixa = window.PagamentosCartao.getParcelasRange(taxas, operadoraId, modalidade, bandeiraId);
            const max = modalidade === 'debito' ? 1 : (faixa ? faixa.max : 1);
            const min = modalidade === 'debito' ? 1 : (faixa ? faixa.min : 1);
            const anterior = Number(parcelasSelect.value) || 1;

            const opcoes = [];
            for (let i = min; i <= max; i += 1) {
                opcoes.push({ value: String(i), label: `${i}x` });
            }
            preencherSelect(parcelasSelect, opcoes, null);
            parcelasSelect.value = String(Math.min(Math.max(anterior, min), max));

            const estimativa = window.PagamentosCartao.estimateFee(
                taxas, operadoraId, modalidade, Number(parcelasSelect.value) || 1, bandeiraId, valor
            );
            setText(
                bloco.querySelector('.pdv-taxa-estimada'),
                estimativa
                    ? `Taxa estimada ${money(estimativa.taxa)} · líquido ${money(estimativa.liquido)}`
                    : 'Sem taxa cadastrada para esta combinação.'
            );

            if (window.DesktopUi) window.DesktopUi.refreshSelect2(bloco);
        };

        const reindexarPagamentos = () => {
            pagamentosBox.querySelectorAll('.pdv-pagamento').forEach((bloco, indice) => {
                bloco.querySelectorAll('[data-campo]').forEach((campo) => {
                    campo.name = `pagamentos[${indice}][${campo.dataset.campo}]`;
                });
            });
        };

        const adicionarPagamento = (valorSugerido) => {
            const fragmento = modeloPagamento.content.cloneNode(true);
            const bloco = fragmento.querySelector('.pdv-pagamento');

            preencherSelect(
                bloco.querySelector('[data-campo="forma_pagamento"]'),
                formas.map((forma) => ({ value: forma.value, label: forma.label })),
                null
            );
            preencherSelect(
                bloco.querySelector('[data-campo="operadora_id"]'),
                operadoras.map((op) => ({ value: String(op.id), label: op.nome })),
                'Operadora'
            );
            preencherSelect(
                bloco.querySelector('[data-campo="bandeira_id"]'),
                bandeiras.map((b) => ({ value: String(b.id), label: b.nome })),
                'Bandeira'
            );

            bloco.querySelector('[data-campo="valor"]').value =
                (Number(valorSugerido) || 0).toFixed(2).replace('.', ',');

            pagamentosBox.appendChild(fragmento);
            reindexarPagamentos();

            const adicionado = pagamentosBox.lastElementChild;
            atualizarBlocoPagamento(adicionado);
            if (window.DesktopUi) window.DesktopUi.refreshSelect2(adicionado);

            // Select2 dispara `change` só via jQuery: sem este binding paralelo
            // o handler nativo nunca roda quando o usuário usa o dropdown.
            if ($) {
                $(adicionado).find('select.form-select').on('change', () => {
                    atualizarBlocoPagamento(adicionado);
                    recalcular();
                });
            }

            recalcular();
        };

        // Sugere o que falta para fechar o total — usado tanto pelo botão
        // "+ Forma" quanto pela primeira linha que o modal insere sozinho.
        const adicionarPagamentoSugerido = () => {
            const total = parseNumber(document.getElementById('pdvTotal').textContent);
            let recebido = 0;
            pagamentosBox.querySelectorAll('[data-campo="valor"]').forEach((campo) => {
                recebido += parseNumber(campo.value);
            });
            adicionarPagamento(Math.max(0, total - recebido));
        };

        /* ------------------------------------------------------------------ */
        /* Totais                                                              */
        /* ------------------------------------------------------------------ */

        /* ------------------------------------------------------------------ */
        /* Margem e semáforo (specs/037)                                       */
        /* ------------------------------------------------------------------ */

        // Limites vindos do servidor, nunca hard-coded: semáforo que discorda
        // entre tela e banco é pior que semáforo nenhum.
        // Defaults espelham os do backend; o servidor sobrescreve na primeira
        // busca (`adotarConfigPreco`). Nunca inventar limite no cliente:
        // semáforo que discorda entre tela e banco é pior que semáforo nenhum.
        let limiteVerde = 30;
        let limiteAmarelo = 15;
        // `completo` só quando o backend mandou custo. Se ele redigiu o
        // payload, o número não existe aqui — e não há o que exibir.
        let mostraCusto = false;

        const adotarConfigPreco = (dados) => {
            if (dados && dados.limites_semaforo) {
                limiteVerde = Number(dados.limites_semaforo.verde ?? limiteVerde);
                limiteAmarelo = Number(dados.limites_semaforo.amarelo ?? limiteAmarelo);
            }
            if (dados && typeof dados.visibilidade_custo === 'string') {
                mostraCusto = dados.visibilidade_custo === 'completo';
            }
        };

        // Segunda linha da sugestão de busca. Para quem não vê número, sai só
        // a bolinha — que já é a informação que decide se vale vender.
        const linhaDeMargemDaSugestao = (item) => {
            const faixa = item.faixa || 'indefinido';
            const cor = { verde: 'success', amarelo: 'warning', vermelho: 'danger' }[faixa] || 'secondary';
            const bolinha = `<span class="text-${cor}">●</span>`;

            if (!mostraCusto || item.custo_unitario == null) {
                return `<small class="d-block fw-normal">${bolinha}</small>`;
            }

            const percentual = Number(item.margem_percentual ?? 0);
            return `<small class="d-block fw-normal text-secondary">${bolinha} `
                + `custo ${money(item.custo_unitario)} · ${percentual.toFixed(1).replace('.', ',')}%</small>`;
        };

        const corDaFaixa = (percentual, abaixoDoPiso) => {
            if (abaixoDoPiso || percentual < 0) return 'danger';
            if (percentual >= limiteVerde) return 'success';
            return percentual >= limiteAmarelo ? 'warning' : 'danger';
        };

        const atualizarResumoMargem = (temCusto, custoTotal, total, abaixoDoPiso) => {
            const custoLabel = document.getElementById('pdvCustoLabel');
            const custoValor = document.getElementById('pdvCustoTotal');
            const margemLabel = document.getElementById('pdvMargemLabel');
            const margemValor = document.getElementById('pdvMargemTotal');
            const aviso = document.getElementById('pdvAvisoPiso');

            const exibirResumo = mostraCusto && temCusto;

            [custoLabel, custoValor, margemLabel, margemValor].forEach((el) => {
                if (el) el.classList.toggle('d-none', !exibirResumo);
            });

            if (exibirResumo) {
                const margem = Math.round((total - custoTotal) * 100) / 100;
                const percentual = total > 0 ? (margem / total) * 100 : 0;

                setText(custoValor, money(custoTotal));
                setText(margemValor, `${money(margem)} · ${percentual.toFixed(1).replace('.', ',')}%`);
                margemValor.className = `col-6 text-end mb-0 text-${corDaFaixa(percentual, margem < 0)}`;
            }

            if (!aviso) return;

            // O aviso de piso vale para TODOS, inclusive quem não vê o número:
            // é a única forma de o técnico saber que passou do limite.
            if (abaixoDoPiso > 0) {
                aviso.classList.remove('d-none');
                aviso.textContent = abaixoDoPiso === 1
                    ? '1 item está abaixo do custo com este desconto.'
                    : `${abaixoDoPiso} itens estão abaixo do custo com este desconto.`;
            } else {
                aviso.classList.add('d-none');
                aviso.textContent = '';
            }
        };

        // "Tem o que cancelar?" vai além do carrinho: cliente escolhido, nome
        // ou CPF digitados, desconto e observações também são desta venda e
        // vazariam para a próxima. O vendedor fica de fora — é quem está no
        // balcão, não um dado da venda.
        const vendaEmAndamento = () => {
            if (itensBody.querySelector('.pdv-item')) return true;
            if (clienteSelect && clienteSelect.value) return true;

            const textos = [clienteNomeInput, clienteDocumentoInput, observacoesInput];
            if (textos.some((campo) => campo && campo.value.trim() !== '')) return true;

            return parseNumber(descontoValor.value) !== 0 || parseNumber(descontoPercentual.value) !== 0;
        };

        const atualizarBotaoCancelar = () => {
            if (cancelarVendaBtn) cancelarVendaBtn.disabled = !vendaEmAndamento();
        };

        const recalcular = () => {
            let subtotal = 0;
            let custoTotal = 0;
            let temCusto = false;
            const linhasComCusto = [];

            itensBody.querySelectorAll('.pdv-item').forEach((linha) => {
                const total = totalDaLinha(linha);
                subtotal += total;
                setText(linha.querySelector('.pdv-item-total'), money(total));

                const quantidade = parseNumber(linha.querySelector('[data-campo="quantidade"]').value);
                const custoUnit = linha.dataset.custoUnitario === '' ? null : Number(linha.dataset.custoUnitario);

                if (custoUnit !== null && !Number.isNaN(custoUnit)) {
                    temCusto = true;
                    custoTotal += custoUnit * quantidade;
                }

                linhasComCusto.push({ linha, total, quantidade, custoUnit });
            });

            const modo = descontoTipo.value;
            const desconto = modo === 'percentual'
                ? Math.round(subtotal * (parseNumber(descontoPercentual.value) / 100) * 100) / 100
                : parseNumber(descontoValor.value);

            const total = Math.max(0, Math.round((subtotal - desconto) * 100) / 100);

            // O desconto de cabeçalho é rateado por linha para saber quais
            // caíram abaixo do custo. Sem ratear, um desconto de 30% no total
            // apareceria como se nenhuma linha tivesse mudado de faixa.
            const fatorDesconto = subtotal > 0 ? total / subtotal : 1;
            let abaixoDoPiso = 0;

            linhasComCusto.forEach(({ linha, total: totalLinha, quantidade, custoUnit }) => {
                const alvo = linha.querySelector('.pdv-item-margem');
                if (!alvo) return;

                if (custoUnit === null || Number.isNaN(custoUnit) || totalLinha <= 0) {
                    alvo.className = 'd-block fw-normal pdv-item-margem text-secondary';
                    alvo.textContent = mostraCusto ? 'sem custo' : '';
                    return;
                }

                const liquido = Math.round(totalLinha * fatorDesconto * 100) / 100;
                const custoLinha = Math.round(custoUnit * quantidade * 100) / 100;
                const margem = liquido - custoLinha;
                const percentual = liquido > 0 ? (margem / liquido) * 100 : 0;
                const furou = liquido < custoLinha;

                if (furou) abaixoDoPiso += 1;

                alvo.className = `d-block fw-normal pdv-item-margem text-${corDaFaixa(percentual, furou)}`;
                alvo.textContent = mostraCusto
                    ? `${money(margem)} · ${percentual.toFixed(1).replace('.', ',')}%`
                    : (furou ? 'abaixo do custo' : '');
            });

            atualizarResumoMargem(temCusto, custoTotal, total, abaixoDoPiso);

            setText(document.getElementById('pdvSubtotal'), money(subtotal));
            setText(document.getElementById('pdvDesconto'), money(desconto));
            setText(document.getElementById('pdvTotal'), money(total));
            // Espelha no modal: é lá que o operador confere o total antes de
            // escolher a forma de pagamento.
            setText(document.getElementById('pdvPagamentoTotal'), money(total));

            let recebido = 0;
            let troco = 0;

            pagamentosBox.querySelectorAll('.pdv-pagamento').forEach((bloco) => {
                const valor = parseNumber(bloco.querySelector('[data-campo="valor"]').value);
                recebido += valor;

                const forma = bloco.querySelector('[data-campo="forma_pagamento"]').value;
                if (forma === 'dinheiro') {
                    const entregue = parseNumber(bloco.querySelector('[data-campo="valor_recebido"]').value);
                    if (entregue > valor) troco += entregue - valor;
                }

                if (formaEhCartao(forma)) atualizarParcelas(bloco);
            });

            const saldo = Math.round((total - recebido) * 100) / 100;

            setText(document.getElementById('pdvRecebido'), money(recebido));
            setText(document.getElementById('pdvSaldo'), money(Math.abs(saldo)));
            setText(document.getElementById('pdvSaldoRotulo'), saldo >= 0 ? 'Falta' : 'Excedente');

            const trocoBox = document.getElementById('pdvTrocoBox');
            trocoBox.classList.toggle('d-none', troco <= 0);
            setText(document.getElementById('pdvTroco'), money(troco));

            document.getElementById('pdvAvisoFiado').classList.toggle('d-none', saldo <= 0);

            const temItens = itensBody.querySelectorAll('.pdv-item').length > 0;
            semItens.classList.toggle('d-none', temItens);
            abrirPagamentoBtn.disabled = !temItens;
            atualizarBotaoCancelar();
        };

        /* ------------------------------------------------------------------ */
        /* Busca                                                               */
        /* ------------------------------------------------------------------ */

        // position: fixed é imune ao overflow das colunas laterais (ver CSS),
        // mas por isso mesmo precisa da posição calculada em JS: sem ancestral
        // relativo relevante, não há como o CSS sozinho grudar o dropdown no
        // campo de busca. Também decide para que lado abrir: se o campo está
        // perto do rodapé da janela (coluna esquerda comprida, tela baixa), o
        // espaço abaixo não chega a caber nem 3 linhas — a caixa nascia
        // espremida contra o rodapé, com scroll interno de poucos pixels.
        // Nesse caso ela abre para CIMA, cobrindo o cartão de cliente (que já
        // é um overlay transitório) em vez de se espremer embaixo.
        const posicionarResultados = () => {
            const rect = buscaInput.getBoundingClientRect();
            const margem = 8;
            const espacoAbaixo = window.innerHeight - rect.bottom - margem;
            const espacoAcima = rect.top - margem;
            const abrirParaCima = espacoAbaixo < 220 && espacoAcima > espacoAbaixo;

            resultadosBox.style.left = `${rect.left}px`;

            if (abrirParaCima) {
                resultadosBox.style.top = 'auto';
                resultadosBox.style.bottom = `${window.innerHeight - rect.top + 4}px`;
                resultadosBox.style.maxHeight = `${Math.max(160, espacoAcima)}px`;
            } else {
                resultadosBox.style.bottom = 'auto';
                resultadosBox.style.top = `${rect.bottom + 4}px`;
                resultadosBox.style.maxHeight = `${Math.max(160, espacoAbaixo)}px`;
            }
            // Largura fica por conta do CSS (min(34rem, 52vw) — mais larga que
            // o campo, de propósito, para caber nome/código/saldo/preço).
        };

        const renderResultados = (itens) => {
            ultimosResultados = itens;
            resultadosBox.innerHTML = '';

            if (itens.length === 0) {
                resultadosBox.classList.add('d-none');
                return;
            }

            posicionarResultados();

            itens.forEach((item, indice) => {
                const botao = document.createElement('button');
                botao.type = 'button';
                // align-items-start (não center): nome longo que quebra em
                // duas linhas empurrava o preço para o meio vertical da linha,
                // como se estivesse flutuando solto.
                botao.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-start gap-2';
                botao.dataset.indice = String(indice);

                const esquerda = document.createElement('span');
                esquerda.innerHTML = `<strong>${item.descricao}</strong>`
                    + (item.codigo ? `<small class="text-secondary ms-2">${item.codigo}</small>` : '')
                    + (item.controla_estoque ? `<small class="text-secondary ms-2">Saldo: ${item.saldo}</small>` : '');

                const direita = document.createElement('span');
                // flex-shrink-0: o preço nunca é espremido pelo nome longo, e
                // text-nowrap mantém "R$ 1.167,12" numa linha só.
                direita.className = 'fw-semibold text-nowrap flex-shrink-0 text-end';
                direita.innerHTML = money(item.valor_unitario)
                    + linhaDeMargemDaSugestao(item);

                botao.appendChild(esquerda);
                botao.appendChild(direita);
                resultadosBox.appendChild(botao);
            });

            resultadosBox.classList.remove('d-none');
        };

        // O dropdown é position: fixed (não acompanha o layout sozinho), então
        // se a coluna esquerda rolar (válvula de segurança em janela baixa) ou
        // a janela mudar de tamanho, ele precisa ser realinhado ou fechado.
        // Captura em fase de captura para pegar o scroll de QUALQUER
        // contêiner aninhado, não só o da janela.
        window.addEventListener('scroll', () => {
            if (resultadosBox.classList.contains('d-none')) return;
            posicionarResultados();
        }, true);

        window.addEventListener('resize', () => {
            if (resultadosBox.classList.contains('d-none')) return;
            posicionarResultados();
        });

        // Clicar fora fecha a lista — esperado num dropdown flutuante, e mais
        // importante ainda agora que ele paira sobre o carrinho inteiro.
        document.addEventListener('click', (evento) => {
            if (resultadosBox.classList.contains('d-none')) return;
            if (evento.target === buscaInput || resultadosBox.contains(evento.target)) return;
            renderResultados([]);
        });

        const buscar = (termo) => {
            if (termo.trim().length < 2) {
                renderResultados([]);
                return;
            }

            const url = new URL(form.dataset.searchUrl, window.location.origin);
            url.searchParams.set('search', termo);

            fetch(url, { headers: { Accept: 'application/json' } })
                .then((resposta) => resposta.json())
                .then((dados) => {
                    adotarConfigPreco(dados);
                    renderResultados(Array.isArray(dados.itens) ? dados.itens : []);
                })
                .catch(() => renderResultados([]));
        };

        let debounce = null;
        buscaInput.addEventListener('input', (evento) => {
            clearTimeout(debounce);
            const termo = evento.target.value;
            debounce = setTimeout(() => buscar(termo), 250);
        });

        buscaInput.addEventListener('keydown', (evento) => {
            if (evento.key !== 'Enter') return;
            evento.preventDefault();

            // Leitor de código de barras digita e manda Enter: o primeiro
            // resultado é o casamento exato, então entra direto no carrinho.
            if (ultimosResultados.length > 0) {
                adicionarItem(ultimosResultados[0]);
                buscaInput.value = '';
                renderResultados([]);
            }
        });

        resultadosBox.addEventListener('click', (evento) => {
            const botao = evento.target.closest('[data-indice]');
            if (!botao) return;

            adicionarItem(ultimosResultados[Number(botao.dataset.indice)]);
            buscaInput.value = '';
            renderResultados([]);
            buscaInput.focus();
        });

        /* ------------------------------------------------------------------ */
        /* Eventos gerais                                                      */
        /* ------------------------------------------------------------------ */

        form.addEventListener('input', (evento) => {
            if (evento.target.matches('[data-campo]') || evento.target === descontoValor || evento.target === descontoPercentual) {
                recalcular();
            }

            // Nome, CPF e observações não mexem em total, mas contam como
            // venda em andamento para o botão "Cancelar venda".
            atualizarBotaoCancelar();
        });

        itensBody.addEventListener('click', (evento) => {
            if (!evento.target.closest('.pdv-remover-item')) return;
            evento.target.closest('.pdv-item').remove();
            reindexarItens();
            recalcular();
        });

        pagamentosBox.addEventListener('click', (evento) => {
            if (!evento.target.closest('.pdv-remover-pagamento')) return;
            evento.target.closest('.pdv-pagamento').remove();
            reindexarPagamentos();
            recalcular();
        });

        document.getElementById('pdvAdicionarPagamento').addEventListener('click', adicionarPagamentoSugerido);

        // O modal nasce vazio; ao abrir, se ainda não há nenhuma forma
        // lançada, insere a primeira sozinho — é o que a frase "clicar em
        // Finalizar insere as formas de pagamento" pede. O operador troca a
        // forma e ajusta o valor a partir daí, em vez de partir do zero.
        if (pagamentoModalEl) {
            pagamentoModalEl.addEventListener('show.bs.modal', () => {
                if (pagamentosBox.querySelectorAll('.pdv-pagamento').length === 0) {
                    adicionarPagamentoSugerido();
                }
            });

            pagamentoModalEl.addEventListener('shown.bs.modal', () => {
                const primeiroValor = pagamentosBox.querySelector('.pdv-pagamento [data-campo="valor"]');
                if (primeiroValor) primeiroValor.focus();
            });
        }

        document.getElementById('pdvAdicionarAvulso').addEventListener('click', () => {
            adicionarItem({ tipo_item: 'avulso', descricao: '', valor_unitario: 0, controla_estoque: false });
        });

        const definirModoDesconto = (percentual) => {
            descontoToggle.dataset.modo = percentual ? 'percentual' : 'valor';
            descontoToggle.textContent = percentual ? '%' : 'R$';
            descontoTipo.value = percentual ? 'percentual' : 'valor';
            descontoValor.classList.toggle('d-none', percentual);
            descontoPercentual.classList.toggle('d-none', !percentual);
        };

        descontoToggle.addEventListener('click', () => {
            definirModoDesconto(descontoToggle.dataset.modo !== 'percentual');
            recalcular();
        });

        document.getElementById('pdvConsumidorFinal').addEventListener('click', () => {
            if ($) $('#pdvCliente').val(null).trigger('change');
            document.getElementById('pdvBlocoAvulso').classList.remove('d-none');
        });

        // Select2 remoto de cliente.
        if ($ && $.fn.select2) {
            $('#pdvCliente').select2({
                theme: 'bootstrap-5',
                placeholder: 'Consumidor final',
                allowClear: true,
                minimumInputLength: 2,
                ajax: {
                    url: form.dataset.clientsUrl,
                    dataType: 'json',
                    delay: 250,
                    data: (params) => ({ q: params.term, page: params.page || 1 }),
                    processResults: (data, params) => ({
                        results: data.results || [],
                        pagination: { more: Boolean(data.pagination && data.pagination.more) },
                    }),
                },
            });

            $('#pdvCliente').on('change', () => {
                const selecionado = $('#pdvCliente').val();
                document.getElementById('pdvBlocoAvulso').classList.toggle('d-none', Boolean(selecionado));
                document.getElementById('pdvEditarCliente')?.classList.toggle('d-none', !selecionado);
                atualizarBotaoCancelar();
            });
        }

        /* ------------------------------------------------------------------ */
        /* Cadastro rápido de cliente (novo / editar) — mesmo padrão AJAX do   */
        /* modal reaproveitado de clients/quick-modal.blade.php no financeiro. */
        /* ------------------------------------------------------------------ */
        (() => {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            const requestJson = async (url, { method = 'GET', body = null } = {}) => {
                const options = {
                    method,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                };

                if (method !== 'GET' && body !== null) {
                    options.headers['Content-Type'] = 'application/json';
                    options.headers['X-CSRF-TOKEN'] = csrfToken;
                    options.body = JSON.stringify(body);
                }

                const response = await fetch(url, options);
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload.success === false) {
                    const error = new Error(payload.message || 'Falha ao processar a solicitação.');
                    error.status = response.status;
                    error.details = payload.errors || null;
                    throw error;
                }

                return payload;
            };

            const getModal = (element) => {
                if (!(element instanceof HTMLElement) || typeof window.bootstrap === 'undefined') return null;
                return window.bootstrap.Modal.getOrCreateInstance(element);
            };

            const flattenErrors = (error) => (Array.isArray(error?.details)
                ? error.details
                : error?.details && typeof error.details === 'object'
                    ? Object.values(error.details).flat().filter(Boolean)
                    : []);

            const renderFormErrors = (boxId, messages, fallback = '') => {
                const box = document.getElementById(boxId);
                if (!box) return;
                const items = (Array.isArray(messages) ? messages : []).filter(Boolean);
                if (items.length === 0 && fallback) items.push(fallback);
                if (items.length === 0) {
                    box.classList.add('d-none');
                    box.innerHTML = '';
                    return;
                }
                box.innerHTML = items.map((mensagem) => `<div>${mensagem}</div>`).join('');
                box.classList.remove('d-none');
            };

            const clearFormErrors = (boxId) => {
                const box = document.getElementById(boxId);
                if (!box) return;
                box.classList.add('d-none');
                box.innerHTML = '';
            };

            // Injeta (se novo) e seleciona o cliente no select2 do PDV.
            const selecionarCliente = (clienteId, nome) => {
                const select = document.getElementById('pdvCliente');
                const value = String(clienteId || '');
                if (!(select instanceof HTMLSelectElement) || value === '') return;

                let option = Array.from(select.options).find((o) => o.value === value) || null;
                if (!(option instanceof HTMLOptionElement)) {
                    option = document.createElement('option');
                    option.value = value;
                    select.appendChild(option);
                }
                option.textContent = nome || `Cliente #${value}`;

                if ($ && $.fn.select2) {
                    $('#pdvCliente').val(value).trigger('change');
                } else {
                    select.value = value;
                }
            };

            // --- Novo cliente ---------------------------------------------
            const novoClienteBtn = document.getElementById('pdvNovoCliente');
            const quickClientModalEl = document.getElementById('quickClientModal');
            const quickClientForm = document.getElementById('quickClientForm');
            const quickClientSubmitBtn = document.getElementById('quickClientSubmit');

            if (novoClienteBtn && quickClientModalEl && quickClientForm) {
                novoClienteBtn.addEventListener('click', () => {
                    quickClientForm.reset();
                    clearFormErrors('quickClientErrors');
                    getModal(quickClientModalEl)?.show();
                });

                const submeterNovoCliente = async (evento) => {
                    evento.preventDefault();
                    clearFormErrors('quickClientErrors');

                    if (!quickClientForm.reportValidity()) {
                        renderFormErrors('quickClientErrors', [], 'Informe nome/razão social e telefone principal antes de salvar.');
                        return;
                    }

                    if (quickClientSubmitBtn) {
                        quickClientSubmitBtn.disabled = true;
                        quickClientSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Salvando...';
                    }

                    try {
                        const payload = Object.fromEntries(new FormData(quickClientForm).entries());
                        const response = await requestJson(form.dataset.quickClientStoreUrl, { method: 'POST', body: payload });
                        const cliente = response.client || {};
                        selecionarCliente(cliente.id, cliente.nome_razao || cliente.name || '');
                        getModal(quickClientModalEl)?.hide();
                    } catch (error) {
                        renderFormErrors('quickClientErrors', flattenErrors(error), error.message);
                    } finally {
                        if (quickClientSubmitBtn) {
                            quickClientSubmitBtn.disabled = false;
                            quickClientSubmitBtn.innerHTML = '<i class="bi bi-person-plus me-2"></i>Cadastrar cliente';
                        }
                    }
                };

                quickClientSubmitBtn?.addEventListener('click', () => {
                    if (typeof quickClientForm.requestSubmit === 'function') {
                        quickClientForm.requestSubmit();
                        return;
                    }
                    quickClientForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                });

                quickClientForm.addEventListener('submit', submeterNovoCliente);

                quickClientModalEl.addEventListener('hidden.bs.modal', () => {
                    quickClientForm.reset();
                    clearFormErrors('quickClientErrors');
                });
            }

            // --- Editar cliente ---------------------------------------------
            const editarClienteBtn = document.getElementById('pdvEditarCliente');
            const quickEditModalEl = document.getElementById('quickEditClientModal');
            const quickEditForm = document.getElementById('quickEditClientForm');
            const quickEditSubmitBtn = document.getElementById('quickEditClientSubmit');
            const quickEditFullLink = document.getElementById('quickEditClientFullLink');

            if (editarClienteBtn && quickEditModalEl && quickEditForm) {
                editarClienteBtn.addEventListener('click', async () => {
                    const clienteId = $ ? $('#pdvCliente').val() : document.getElementById('pdvCliente').value;
                    if (!clienteId) return;

                    clearFormErrors('quickEditClientErrors');

                    const showUrl = (form.dataset.quickClientShowUrlTemplate || '').replace('__CLIENT_ID__', clienteId);
                    const updateUrl = (form.dataset.quickClientUpdateUrlTemplate || '').replace('__CLIENT_ID__', clienteId);
                    const fullEditUrl = (form.dataset.clientFullEditUrlTemplate || '').replace('__CLIENT_ID__', clienteId);

                    quickEditForm.dataset.updateUrl = updateUrl;
                    quickEditForm.dataset.clientId = String(clienteId);
                    if (quickEditFullLink) quickEditFullLink.href = fullEditUrl || '#';

                    try {
                        const response = await requestJson(showUrl);
                        const cliente = response.client || {};
                        const campos = {
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
                        };
                        Object.entries(campos).forEach(([id, campo]) => {
                            const input = document.getElementById(id);
                            if (input) input.value = cliente[campo] || '';
                        });

                        getModal(quickEditModalEl)?.show();
                    } catch (error) {
                        if (typeof window.Swal !== 'undefined') {
                            window.Swal.fire({ icon: 'error', title: 'Não foi possível carregar o cliente', text: error.message });
                        }
                    }
                });

                const submeterEditarCliente = async (evento) => {
                    evento.preventDefault();
                    clearFormErrors('quickEditClientErrors');

                    if (!quickEditForm.reportValidity()) {
                        renderFormErrors('quickEditClientErrors', [], 'Informe nome/razão social e telefone principal antes de salvar.');
                        return;
                    }

                    const updateUrl = quickEditForm.dataset.updateUrl;
                    if (!updateUrl) return;

                    if (quickEditSubmitBtn) {
                        quickEditSubmitBtn.disabled = true;
                        quickEditSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Salvando...';
                    }

                    try {
                        const payload = Object.fromEntries(new FormData(quickEditForm).entries());
                        const response = await requestJson(updateUrl, { method: 'PUT', body: payload });
                        const cliente = response.client || {};
                        selecionarCliente(quickEditForm.dataset.clientId, cliente.nome_razao || '');
                        getModal(quickEditModalEl)?.hide();
                    } catch (error) {
                        renderFormErrors('quickEditClientErrors', flattenErrors(error), error.message);
                    } finally {
                        if (quickEditSubmitBtn) {
                            quickEditSubmitBtn.disabled = false;
                            quickEditSubmitBtn.innerHTML = '<i class="bi bi-check2-circle me-2"></i>Salvar cliente';
                        }
                    }
                };

                quickEditSubmitBtn?.addEventListener('click', () => {
                    if (typeof quickEditForm.requestSubmit === 'function') {
                        quickEditForm.requestSubmit();
                        return;
                    }
                    quickEditForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                });

                quickEditForm.addEventListener('submit', submeterEditarCliente);

                quickEditModalEl.addEventListener('hidden.bs.modal', () => {
                    clearFormErrors('quickEditClientErrors');
                });
            }
        })();

        /* ------------------------------------------------------------------ */
        /* Atalhos                                                             */
        /* ------------------------------------------------------------------ */

        // Modo terminal: tela cheia de verdade (sem barra do navegador), como
        // num caixa de supermercado. A classe no <body> é o que faz o CSS
        // esconder topbar e rodapé e esticar a grade — ela é a fonte da
        // verdade sobre "estamos em tela cheia?", não document.fullscreenElement,
        // porque a Fullscreen API real só entra com um gesto do usuário e o
        // PDV precisa abrir (e reabrir após F5) em tela cheia sozinho.
        const emTelaCheia = () => document.body.classList.contains('pdv-modo-terminal');

        // comGesto diferencia a chamada automática (recarregar a página) da
        // chamada por tecla/clique: a Fullscreen API real SÓ pode ser pedida
        // dentro de um gesto do usuário — fora disso o navegador rejeita, e
        // em alguns navegadores essa rejeição é um throw síncrono (não uma
        // Promise), que escaparia do .catch() e apareceria como erro no
        // console. Por isso ela nem é tentada fora de um gesto: o modo
        // terminal (classe CSS) sozinho já garante a aparência de tela cheia
        // depois de um F5.
        const entrarTelaCheia = (comGesto) => {
            if (!emTelaCheia()) {
                document.body.classList.add('pdv-modo-terminal');
                window.dispatchEvent(new Event('resize'));
            }

            if (comGesto && !document.fullscreenElement) {
                try {
                    const alvo = document.documentElement;
                    (alvo.requestFullscreen?.() || Promise.reject()).catch(() => {});
                } catch (erro) {
                    // Navegador recusou de forma síncrona: sem problema, o
                    // modo terminal via CSS já cobre a aparência.
                }
            }
        };

        const sairTelaCheia = () => {
            document.body.classList.remove('pdv-modo-terminal');
            window.dispatchEvent(new Event('resize'));

            if (document.fullscreenElement) {
                document.exitFullscreen?.();
            }
        };

        const alternarTelaCheia = () => {
            if (emTelaCheia()) {
                sairTelaCheia();
            } else {
                entrarTelaCheia(true);
            }
        };

        document.addEventListener('fullscreenchange', () => {
            document.body.classList.toggle('pdv-modo-terminal', Boolean(document.fullscreenElement));
            buscaInput.focus();
        });

        const botaoTelaCheia = document.getElementById('pdvTelaCheia');
        if (botaoTelaCheia) botaoTelaCheia.addEventListener('click', alternarTelaCheia);

        // O PDV sempre abre em tela cheia — inclusive ao recarregar a página
        // (F5), quando a Fullscreen API real é sempre perdida pelo navegador.
        // Sem gesto aqui: é carregamento de página, não clique/tecla.
        entrarTelaCheia(false);

        // Calendário do mês + relógio digital, visíveis só em modo terminal
        // (rodapé da coluna esquerda). Gerados em JS, não no Blade: um
        // terminal deixado aberto virando a meia-noite não pode ficar com o
        // dia errado destacado.
        const calendarioTabela = document.getElementById('pdvTerminalCalendario');
        const relogioEl = document.getElementById('pdvTerminalRelogio');
        const mesEl = document.getElementById('pdvTerminalMes');
        const dataEl = document.getElementById('pdvTerminalData');
        const agendaCartao = document.querySelector('.pdv-terminal-agenda');
        const agendaConteudo = agendaCartao ? agendaCartao.querySelector('.pdv-agenda-conteudo') : null;
        let diaRenderizado = null;

        const doisDigitos = (numero) => String(numero).padStart(2, '0');

        // "setembro de 2026" -> "Setembro de 2026". text-transform: capitalize
        // pegava toda palavra e escrevia "Setembro De 2026".
        const inicialMaiuscula = (texto) => texto.charAt(0).toUpperCase() + texto.slice(1);

        // O cartão do calendário recebe da coluna esquerda a altura que
        // sobrar (flex: 1, min-height: 0, overflow: hidden) — a coluna em si
        // nunca rola. Aqui ele escolhe o que melhor ENCHE essa altura, do mais
        // generoso para o mais enxuto:
        //   1. empilhado (relógio em cima) e ampliado até ESCALA_MAXIMA;
        //   2. mês e relógio lado a lado, tamanho normal;
        //   3. só a semana de hoje;
        //   4. só data + relógio;
        //   5. nada visível.
        // A altura do cartão vem da coluna, não do conteúdo, então trocar de
        // modo ou de escala não realimenta o ResizeObserver abaixo.
        const ESCALA_MAXIMA = 1.8;
        const RESPIRO_MAXIMO = 12;
        const MODOS_AGENDA = ['is-empilhada', 'is-so-semana', 'is-so-relogio', 'is-sem-espaco'];

        const ajustarAgenda = () => {
            if (!agendaCartao || !agendaConteudo) return;

            const definirEscala = (valor) => agendaCartao.style.setProperty('--agenda-escala', String(valor));
            const definirRespiro = (px) => agendaCartao.style.setProperty('--agenda-respiro', `${px}px`);

            agendaCartao.classList.remove(...MODOS_AGENDA);
            definirEscala(1);
            definirRespiro(0);
            if (agendaCartao.offsetParent === null) return;

            const estilo = window.getComputedStyle(agendaCartao);
            const espaco = agendaCartao.clientHeight
                - parseFloat(estilo.paddingTop)
                - parseFloat(estilo.paddingBottom);
            // Largura também conta: ampliado demais, o círculo do dia ou o
            // relógio passariam da largura da coluna.
            const cabe = () => agendaConteudo.getBoundingClientRect().height <= espaco
                && agendaConteudo.scrollWidth <= agendaConteudo.clientWidth + 1;

            // Tudo no cartão é rem x escala, então a altura cresce na mesma
            // proporção: a primeira estimativa já costuma servir, e o laço só
            // corrige arredondamento (passos de 0,05, em inteiros para não
            // acumular erro de ponto flutuante).
            agendaCartao.classList.add('is-empilhada');
            const alturaBase = agendaConteudo.getBoundingClientRect().height;
            const maiorPasso = Math.floor(Math.min(ESCALA_MAXIMA, espaco / alturaBase) * 20);
            for (let passo = maiorPasso; passo >= 20; passo -= 1) {
                definirEscala(passo / 20);
                if (!cabe()) continue;

                // Na escala máxima ainda pode sobrar altura (cliente escolhido
                // esconde Nome/CPF; a largura da coluna não deixa os círculos
                // crescerem mais). Em vez de faixas vazias em cima e embaixo,
                // a sobra vira espaço entre as semanas, até RESPIRO_MAXIMO.
                const sobra = espaco - agendaConteudo.getBoundingClientRect().height;
                const linhas = calendarioTabela ? calendarioTabela.rows.length : 0;
                if (sobra > 0 && linhas > 0) {
                    for (let px = Math.min(RESPIRO_MAXIMO, Math.floor(sobra / (linhas + 1) / 2)); px > 0; px -= 1) {
                        definirRespiro(px);
                        if (cabe()) return;
                    }
                    definirRespiro(0);
                }
                return;
            }
            agendaCartao.classList.remove('is-empilhada');
            definirEscala(1);

            if (cabe()) return;
            agendaCartao.classList.add('is-so-semana');
            if (cabe()) return;
            agendaCartao.classList.add('is-so-relogio');
            if (cabe()) return;
            agendaCartao.classList.add('is-sem-espaco');
        };

        const renderizarCalendario = (agora) => {
            if (!calendarioTabela) return;

            const ano = agora.getFullYear();
            const mes = agora.getMonth();
            const hoje = agora.getDate();

            setText(mesEl, inicialMaiuscula(agora.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })));
            setText(dataEl, inicialMaiuscula(agora.toLocaleDateString('pt-BR', { weekday: 'short', day: 'numeric', month: 'long' })));

            const corpo = calendarioTabela.querySelector('tbody');
            if (!corpo) return;
            corpo.innerHTML = '';

            const primeiroDiaSemana = new Date(ano, mes, 1).getDay();
            const totalDias = new Date(ano, mes + 1, 0).getDate();

            let linha = document.createElement('tr');
            for (let vazio = 0; vazio < primeiroDiaSemana; vazio += 1) {
                linha.appendChild(document.createElement('td'));
            }

            for (let dia = 1; dia <= totalDias; dia += 1) {
                const celula = document.createElement('td');
                if (dia === hoje) {
                    celula.classList.add('pdv-dia-atual');
                    // Marcada aqui (e não via :has() no CSS) para o modo
                    // "só a semana de hoje" do ajustarAgenda.
                    linha.classList.add('pdv-semana-atual');
                }

                const marcador = document.createElement('span');
                marcador.textContent = String(dia);
                celula.appendChild(marcador);

                linha.appendChild(celula);

                if ((primeiroDiaSemana + dia) % 7 === 0 || dia === totalDias) {
                    corpo.appendChild(linha);
                    linha = document.createElement('tr');
                }
            }

            diaRenderizado = hoje;
        };

        const atualizarAgendaTerminal = () => {
            const agora = new Date();

            if (relogioEl) {
                relogioEl.textContent = `${doisDigitos(agora.getHours())}:${doisDigitos(agora.getMinutes())}:${doisDigitos(agora.getSeconds())}`;
            }

            if (agora.getDate() !== diaRenderizado) {
                renderizarCalendario(agora);
                // Virar o mês pode trocar 5 semanas por 6 (uma linha a mais).
                ajustarAgenda();
            }
        };

        if (calendarioTabela || relogioEl) {
            atualizarAgendaTerminal();
            setInterval(atualizarAgendaTerminal, 1000);
        }

        // A altura livre muda com a janela, com a entrada/saída da tela cheia
        // e quando o bloco Nome/CPF aparece ou some (cliente escolhido).
        // Observar o próprio cartão cobre todos esses casos de uma vez.
        if (agendaCartao) {
            if (typeof window.ResizeObserver === 'function') {
                new window.ResizeObserver(() => window.requestAnimationFrame(ajustarAgenda)).observe(agendaCartao);
            } else {
                window.addEventListener('resize', ajustarAgenda);
            }
            ajustarAgenda();
        }

        // Volta a tela ao estado de "Nova venda" sem recarregar a página:
        // recarregar derrubaria a tela cheia real (a Fullscreen API só volta
        // com gesto) e custaria uma ida ao servidor no meio do balcão. Como a
        // venda só existe no navegador até o POST, não há nada a desfazer no
        // backend. O vendedor fica como está (ver vendaEmAndamento).
        const iniciarNovaVenda = () => {
            itensBody.innerHTML = '';
            pagamentosBox.innerHTML = '';
            saldoPorPeca.clear();

            buscaInput.value = '';
            renderResultados([]);

            if ($ && $.fn.select2) {
                // Mesmo caminho do "Consumidor final": o handler de change
                // reexibe nome/CPF e esconde o lápis de editar cliente.
                $('#pdvCliente').val(null).trigger('change');
            } else if (clienteSelect) {
                clienteSelect.value = '';
            }
            document.getElementById('pdvBlocoAvulso').classList.remove('d-none');
            if (clienteNomeInput) clienteNomeInput.value = '';
            if (clienteDocumentoInput) clienteDocumentoInput.value = '';
            if (observacoesInput) observacoesInput.value = '';

            definirModoDesconto(false);
            descontoValor.value = '0,00';
            descontoPercentual.value = '0';

            document.getElementById('pdvConfirmarEstoque').value = '0';

            recalcular();
            buscaInput.focus();
        };

        // Pergunta antes: Esc é a mesma tecla que o reflexo do operador usa
        // para "cancelar o que estou fazendo", e um toque errado apagava uma
        // venda de quinze itens sem nenhuma forma de voltar. O foco vai para o
        // botão de confirmar, então quem quis cancelar mesmo resolve com
        // Esc + Enter.
        const cancelarVendaComConfirmacao = () => {
            if (!vendaEmAndamento()) {
                return;
            }

            const quantidade = itensBody.querySelectorAll('.pdv-item').length;
            const resumo = quantidade === 0
                ? ''
                : (quantidade === 1 ? 'O carrinho tem 1 item. ' : `O carrinho tem ${quantidade} itens. `);
            const texto = `${resumo}Itens, cliente, desconto e observações serão descartados `
                + 'e o PDV fica pronto para uma nova venda.';

            if (!window.Swal) {
                if (window.confirm(`Cancelar esta venda?\n\n${texto}`)) {
                    iniciarNovaVenda();
                }

                return;
            }

            window.Swal.fire({
                icon: 'warning',
                title: 'Cancelar esta venda?',
                text: texto,
                showCancelButton: true,
                focusConfirm: true,
                confirmButtonText: 'Sim, cancelar venda',
                cancelButtonText: 'Voltar à venda',
            }).then((resultado) => {
                if (resultado.isConfirmed) {
                    iniciarNovaVenda();
                }
            });
        };

        if (cancelarVendaBtn) cancelarVendaBtn.addEventListener('click', cancelarVendaComConfirmacao);

        document.addEventListener('keydown', (evento) => {
            if (evento.key === 'F2') {
                evento.preventDefault();

                // Modal já aberto: F2 confirma a venda. Fechado: F2 abre o
                // passo de pagamento (só se houver item no carrinho).
                if (pagamentoModalEl && pagamentoModalEl.classList.contains('show')) {
                    if (!confirmarBtn.disabled) confirmarBtn.click();
                } else if (!abrirPagamentoBtn.disabled) {
                    abrirPagamentoBtn.click();
                }
                return;
            }

            if (evento.key === 'F3') {
                // O navegador usa F3 para "localizar próximo"; sem isto a busca
                // nativa abre por cima do PDV.
                evento.preventDefault();
                alternarTelaCheia();
                return;
            }

            if (evento.key === 'F4') {
                evento.preventDefault();
                if ($) $('#pdvCliente').select2('open');
                return;
            }

            if (evento.key === 'Escape') {
                // Esc já tem dono nesta ordem de prioridade: sair da tela cheia
                // (navegador), fechar um modal (pagamento, cadastro rápido de
                // cliente), fechar o próprio diálogo de confirmação e fechar a
                // lista aberta do select2 (que marca o evento com
                // preventDefault) — em nenhum desses casos a venda pode ser
                // cancelada junto.
                if (evento.defaultPrevented) return;
                if (document.fullscreenElement) return;
                if (pagamentoModalEl && pagamentoModalEl.classList.contains('show')) return;
                if (evento.target instanceof Element && evento.target.closest('.modal, .swal2-container')) return;

                if (vendaEmAndamento()) {
                    evento.preventDefault();
                    cancelarVendaComConfirmacao();
                }
            }
        });

        /* ------------------------------------------------------------------ */
        /* Envio                                                               */
        /* ------------------------------------------------------------------ */

        const itensSemSaldo = () => {
            const demanda = new Map();

            itensBody.querySelectorAll('.pdv-item').forEach((linha) => {
                if (linha.querySelector('[data-campo="baixa_estoque"]').value !== '1') return;

                const pecaId = Number(linha.querySelector('[data-campo="referencia_id"]').value);
                if (!pecaId) return;

                const quantidade = parseNumber(linha.querySelector('[data-campo="quantidade"]').value);
                // Duas linhas da mesma peça somam: conferir uma a uma deixaria
                // vender o dobro do disponível sem nenhum aviso.
                demanda.set(pecaId, (demanda.get(pecaId) || 0) + quantidade);
            });

            const faltantes = [];
            demanda.forEach((quantidade, pecaId) => {
                const saldo = saldoPorPeca.get(pecaId);
                if (saldo !== undefined && saldo < quantidade) {
                    faltantes.push({ pecaId, saldo, quantidade });
                }
            });

            return faltantes;
        };

        // Venda em andamento e' trabalho nao salvo: sair da pagina descartava o
        // carrinho em silencio, por qualquer caminho (sidebar, inicio, "+ Novo",
        // F5, fechar a aba). O shell pergunta antes; aqui so' respondemos se ha
        // o que perder. submitLiberado exclui a saida legitima — enviar a venda.
        window.erpRegisterUnsavedWork?.(
            () => !submitLiberado && itensBody.querySelectorAll('.pdv-item').length > 0
        );

        form.addEventListener('submit', (evento) => {
            if (submitLiberado) return;

            const faltantes = itensSemSaldo();
            if (faltantes.length === 0) {
                submitLiberado = true;
                confirmarBtn.disabled = true;
                return;
            }

            evento.preventDefault();

            const lista = faltantes
                .map((item) => `• disponível ${item.saldo}, pedido ${item.quantidade}`)
                .join('<br>');

            const confirmar = () => {
                document.getElementById('pdvConfirmarEstoque').value = '1';
                submitLiberado = true;
                confirmarBtn.disabled = true;
                form.submit();
            };

            if (window.Swal) {
                window.Swal.fire({
                    icon: 'warning',
                    title: 'Estoque insuficiente',
                    html: `${lista}<br><br>O saldo ficará negativo e a venda será marcada para acerto de inventário.`,
                    showCancelButton: true,
                    confirmButtonText: 'Vender assim mesmo',
                    cancelButtonText: 'Revisar',
                }).then((resultado) => {
                    if (resultado.isConfirmed) confirmar();
                });
            } else if (window.confirm('Estoque insuficiente. Vender assim mesmo?')) {
                confirmar();
            }
        });

        recalcular();
    });
})();
