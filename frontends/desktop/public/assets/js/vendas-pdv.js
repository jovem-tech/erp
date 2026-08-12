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
        const finalizarBtn = document.getElementById('pdvFinalizar');
        const modeloItem = document.getElementById('pdvModeloItem');
        const modeloPagamento = document.getElementById('pdvModeloPagamento');

        const descontoValor = document.getElementById('pdvDescontoValor');
        const descontoPercentual = document.getElementById('pdvDescontoPercentual');
        const descontoTipo = document.getElementById('pdvDescontoTipo');
        const descontoToggle = document.getElementById('pdvDescontoToggle');

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

        /* ------------------------------------------------------------------ */
        /* Totais                                                              */
        /* ------------------------------------------------------------------ */

        const recalcular = () => {
            let subtotal = 0;

            itensBody.querySelectorAll('.pdv-item').forEach((linha) => {
                const total = totalDaLinha(linha);
                subtotal += total;
                setText(linha.querySelector('.pdv-item-total'), money(total));
            });

            const modo = descontoTipo.value;
            const desconto = modo === 'percentual'
                ? Math.round(subtotal * (parseNumber(descontoPercentual.value) / 100) * 100) / 100
                : parseNumber(descontoValor.value);

            const total = Math.max(0, Math.round((subtotal - desconto) * 100) / 100);

            setText(document.getElementById('pdvSubtotal'), money(subtotal));
            setText(document.getElementById('pdvDesconto'), money(desconto));
            setText(document.getElementById('pdvTotal'), money(total));

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
            finalizarBtn.disabled = !temItens;
        };

        /* ------------------------------------------------------------------ */
        /* Busca                                                               */
        /* ------------------------------------------------------------------ */

        const renderResultados = (itens) => {
            ultimosResultados = itens;
            resultadosBox.innerHTML = '';

            if (itens.length === 0) {
                resultadosBox.classList.add('d-none');
                return;
            }

            itens.forEach((item, indice) => {
                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                botao.dataset.indice = String(indice);

                const esquerda = document.createElement('span');
                esquerda.innerHTML = `<strong>${item.descricao}</strong>`
                    + (item.codigo ? `<small class="text-secondary ms-2">${item.codigo}</small>` : '')
                    + (item.controla_estoque ? `<small class="text-secondary ms-2">Saldo: ${item.saldo}</small>` : '');

                const direita = document.createElement('span');
                direita.className = 'fw-semibold';
                direita.textContent = money(item.valor_unitario);

                botao.appendChild(esquerda);
                botao.appendChild(direita);
                resultadosBox.appendChild(botao);
            });

            resultadosBox.classList.remove('d-none');
        };

        const buscar = (termo) => {
            if (termo.trim().length < 2) {
                renderResultados([]);
                return;
            }

            const url = new URL(form.dataset.searchUrl, window.location.origin);
            url.searchParams.set('search', termo);

            fetch(url, { headers: { Accept: 'application/json' } })
                .then((resposta) => resposta.json())
                .then((dados) => renderResultados(Array.isArray(dados.itens) ? dados.itens : []))
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

        document.getElementById('pdvAdicionarPagamento').addEventListener('click', () => {
            const total = parseNumber(document.getElementById('pdvTotal').textContent);
            let recebido = 0;
            pagamentosBox.querySelectorAll('[data-campo="valor"]').forEach((campo) => {
                recebido += parseNumber(campo.value);
            });
            adicionarPagamento(Math.max(0, total - recebido));
        });

        document.getElementById('pdvAdicionarAvulso').addEventListener('click', () => {
            adicionarItem({ tipo_item: 'avulso', descricao: '', valor_unitario: 0, controla_estoque: false });
        });

        descontoToggle.addEventListener('click', () => {
            const percentual = descontoToggle.dataset.modo !== 'percentual';
            descontoToggle.dataset.modo = percentual ? 'percentual' : 'valor';
            descontoToggle.textContent = percentual ? '%' : 'R$';
            descontoTipo.value = percentual ? 'percentual' : 'valor';
            descontoValor.classList.toggle('d-none', percentual);
            descontoPercentual.classList.toggle('d-none', !percentual);
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
            });
        }

        /* ------------------------------------------------------------------ */
        /* Atalhos                                                             */
        /* ------------------------------------------------------------------ */

        // Modo terminal: tela cheia de verdade (sem barra do navegador), como
        // num caixa de supermercado. A classe no <body> é o que faz o CSS
        // esconder topbar e rodapé e esticar a grade.
        const alternarTelaCheia = () => {
            if (document.fullscreenElement) {
                document.exitFullscreen?.();
                return;
            }

            // Só funciona a partir de um gesto do usuário — a tecla conta.
            const alvo = document.documentElement;
            (alvo.requestFullscreen?.() || Promise.reject()).catch(() => {
                // Navegador recusou (permissão/iframe): ao menos entra no modo
                // enxuto, escondendo topbar e rodapé.
                document.body.classList.toggle('pdv-modo-terminal');
                window.dispatchEvent(new Event('resize'));
            });
        };

        document.addEventListener('fullscreenchange', () => {
            document.body.classList.toggle('pdv-modo-terminal', Boolean(document.fullscreenElement));
            buscaInput.focus();
        });

        const botaoTelaCheia = document.getElementById('pdvTelaCheia');
        if (botaoTelaCheia) botaoTelaCheia.addEventListener('click', alternarTelaCheia);

        document.addEventListener('keydown', (evento) => {
            if (evento.key === 'F2') {
                evento.preventDefault();
                if (!finalizarBtn.disabled) finalizarBtn.click();
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
                // Em tela cheia o Esc pertence ao navegador (é como se sai
                // dela). Limpar o carrinho junto seria uma perda silenciosa.
                if (document.fullscreenElement) return;

                if (itensBody.querySelectorAll('.pdv-item').length > 0) {
                    evento.preventDefault();
                    itensBody.innerHTML = '';
                    pagamentosBox.innerHTML = '';
                    recalcular();
                    buscaInput.focus();
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

        form.addEventListener('submit', (evento) => {
            if (submitLiberado) return;

            const faltantes = itensSemSaldo();
            if (faltantes.length === 0) {
                submitLiberado = true;
                finalizarBtn.disabled = true;
                return;
            }

            evento.preventDefault();

            const lista = faltantes
                .map((item) => `• disponível ${item.saldo}, pedido ${item.quantidade}`)
                .join('<br>');

            const confirmar = () => {
                document.getElementById('pdvConfirmarEstoque').value = '1';
                submitLiberado = true;
                finalizarBtn.disabled = true;
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
