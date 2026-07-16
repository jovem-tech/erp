(function () {
    const config = window.__DESKTOP_ORDER_CLOSURE || {};
    const cartaoTaxas = Array.isArray(config.cartao?.taxas) ? config.cartao.taxas : [];
    const noRepairStatuses = Array.isArray(config.noRepairStatuses) ? config.noRepairStatuses : [];
    const orcamentoPendenteAprovacao = Boolean(config.orcamentoPendenteAprovacao);

    const formatMoney = (value) => Number(value || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    // Converte valores no padrao brasileiro ("R$ 1.234,56", "1.234,56") ou
    // canonico ("1234.56") para number — mesmo parser da tela de orcamentos
    // (orcamentos-form.js), onde a mascara BRL de inputs foi introduzida.
    const parseMoney = (value) => {
        if (typeof value === 'number') {
            return Number.isFinite(value) ? value : 0;
        }

        let normalized = String(value ?? '').trim().replace(/[^\d,.-]/g, '');
        if (normalized === '' || normalized === '-' || normalized === '.' || normalized === ',') {
            return 0;
        }

        const lastComma = normalized.lastIndexOf(',');
        const lastDot = normalized.lastIndexOf('.');

        if (lastComma !== -1 && lastDot !== -1) {
            normalized = lastComma > lastDot
                ? normalized.replace(/\./g, '').replace(',', '.')
                : normalized.replace(/,/g, '');
        } else if (lastComma !== -1) {
            normalized = normalized.replace(/\./g, '').replace(',', '.');
        } else if (lastDot !== -1) {
            const parts = normalized.split('.');
            if (parts.length > 2 || (parts[parts.length - 1] || '').length === 3) {
                normalized = normalized.replace(/\./g, '');
            }
        }

        const parsed = Number.parseFloat(normalized);

        return Number.isFinite(parsed) ? parsed : 0;
    };

    // Mascara BRL no campo Valor do recebimento: exibe "R$ 50,00" ao sair do
    // campo e seleciona tudo ao focar (mesmo padrao data-budget-money dos
    // orcamentos). O valor canonico e restaurado no submit do form.
    const bindMoneyInput = (input) => {
        if (!(input instanceof HTMLInputElement) || input.dataset.moneyBound === '1') {
            return;
        }

        input.dataset.moneyBound = '1';
        input.type = 'text';
        input.inputMode = 'decimal';
        input.autocomplete = 'off';
        input.spellcheck = false;

        const sync = () => {
            input.value = `R$ ${formatMoney(parseMoney(input.value))}`;
        };

        input.addEventListener('focus', () => {
            window.requestAnimationFrame(() => input.select());
        });
        input.addEventListener('blur', sync);
        sync();
    };

    const setText = (id, text) => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    };

    const findApplicableRate = (operadoraId, modalidade, parcelas, bandeiraId) => {
        if (!operadoraId || !modalidade) return null;

        const candidates = cartaoTaxas.filter((taxa) => {
            if (Number(taxa.operadora_id) !== Number(operadoraId)) return false;
            if (taxa.modalidade !== modalidade) return false;

            const inicio = Math.max(1, Number(taxa.parcelas_inicial) || 1);
            const fim = Math.max(inicio, Number(taxa.parcelas_final) || inicio);
            if (parcelas < inicio || parcelas > fim) return false;

            if (taxa.bandeira_id === null || taxa.bandeira_id === undefined) return true;
            return bandeiraId !== null && Number(taxa.bandeira_id) === Number(bandeiraId);
        });

        if (candidates.length === 0) return null;

        candidates.sort((a, b) => {
            const aSpecific = bandeiraId !== null && a.bandeira_id !== null ? 1 : 0;
            const bSpecific = bandeiraId !== null && b.bandeira_id !== null ? 1 : 0;
            if (aSpecific !== bSpecific) return bSpecific - aSpecific;

            const aRange = (Number(a.parcelas_final) || 1) - (Number(a.parcelas_inicial) || 1);
            const bRange = (Number(b.parcelas_final) || 1) - (Number(b.parcelas_inicial) || 1);
            if (aRange !== bRange) return aRange - bRange;

            return Number(a.id) - Number(b.id);
        });

        return candidates[0];
    };

    // Faixa de parcelas realmente liberada para operadora+modalidade(+bandeira)
    // nas taxas cadastradas (Financeiro > Cartões > Taxas) — usada para limitar
    // o campo "Parcelas" do recebimento em vez de aceitar qualquer valor de 1 a 99.
    const getParcelasRange = (operadoraId, modalidade, bandeiraId) => {
        if (!operadoraId || !modalidade) return null;

        const candidates = cartaoTaxas.filter((taxa) => {
            if (Number(taxa.operadora_id) !== Number(operadoraId)) return false;
            if (taxa.modalidade !== modalidade) return false;
            if (taxa.bandeira_id === null || taxa.bandeira_id === undefined) return true;
            return bandeiraId !== null && Number(taxa.bandeira_id) === Number(bandeiraId);
        });

        if (candidates.length === 0) return null;

        const min = candidates.reduce((acc, taxa) => Math.min(acc, Math.max(1, Number(taxa.parcelas_inicial) || 1)), Infinity);
        const max = candidates.reduce((acc, taxa) => Math.max(acc, Math.max(1, Number(taxa.parcelas_final) || 1)), 1);

        return { min, max };
    };

    const estimateFee = (operadoraId, modalidade, parcelas, bandeiraId, valorBruto) => {
        const rate = findApplicableRate(operadoraId, modalidade, parcelas, bandeiraId);
        if (!rate || !valorBruto) return null;

        const percentual = Number(rate.taxa_percentual) || 0;
        const fixa = Number(rate.taxa_fixa) || 0;
        const taxa = Math.round((valorBruto * (percentual / 100) + fixa) * 100) / 100;
        const liquido = Math.round((valorBruto - taxa) * 100) / 100;

        return { taxa, liquido };
    };

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('closureForm');
        const classificacaoBaixaSelect = document.getElementById('classificacaoBaixa');
        const encerrarComoSelect = document.getElementById('encerrarComo');
        const dataEntregaInput = document.getElementById('dataEntrega');
        const dataEntregaWrapper = document.getElementById('dataEntregaWrapper');
        const closureBaixaFields = document.getElementById('closureBaixaFields');
        const closureAdvanceFields = document.getElementById('closureAdvanceFields');
        const equipamentoEntregueCheckbox = document.getElementById('equipamentoEntregue');
        const closureReturnCard = document.getElementById('closureReturnCard');
        const receiptsList = document.getElementById('closureReceiptsList');
        const receiptTemplate = document.getElementById('closureReceiptRowTemplate');
        const emptyHint = document.getElementById('closureReceiptsEmptyHint');
        const agendarRetornoCheckbox = document.getElementById('agendarRetorno');
        const notificarClienteCheckbox = document.getElementById('notificarCliente');
        const retornoDataWrapper = document.getElementById('closureRetornoDataWrapper');
        const confirmacaoCheckbox = document.getElementById('confirmacaoBaixa');
        const submitButton = document.getElementById('closureSubmitButton');
        const footerPrevBtn = document.getElementById('closureFooterPrevBtn');
        const footerNextBtn = document.getElementById('closureFooterNextBtn');
        const receiveAllBalanceBtn = document.querySelector('[data-action="receber-saldo-total"]');
        const currentStepInput = document.getElementById('closureCurrentStepInput');
        const stepErrorBox = document.getElementById('closureStepError');
        const financeiroTabIndicator = document.querySelector('[data-step-indicator="2"]');
        const confirmacaoTabIndicator = document.querySelector('[data-step-indicator="3"]');

        if (!(form instanceof HTMLFormElement) || !(receiptsList instanceof HTMLElement) || !(receiptTemplate instanceof HTMLTemplateElement)) {
            return;
        }

        let currentStep = Number(config.initialStep) > 0 ? Number(config.initialStep) : 1;
        let receiptIndex = 0;

        const showStepError = (messages) => {
            if (!(stepErrorBox instanceof HTMLElement)) return;
            const list = Array.isArray(messages) ? messages.filter(Boolean) : [messages].filter(Boolean);
            if (list.length === 0) {
                stepErrorBox.classList.add('d-none');
                stepErrorBox.innerHTML = '';
                return;
            }
            stepErrorBox.innerHTML = [...new Set(list)].map((msg) => `<div>${msg}</div>`).join('');
            stepErrorBox.classList.remove('d-none');
        };

        const clearStepError = () => showStepError([]);

        const isNoRepairClosure = () => noRepairStatuses.includes(encerrarComoSelect?.value || '');

        // "Entregue - Reparado e Pago" com orçamento vinculado ainda não
        // aprovado: bloqueia ANTES de avançar de etapa (não só no submit final)
        // — o backend também recusa (ver OrderClosureService::close()), mas
        // esperar até lá faria o técnico preencher tudo à toa.
        const isBlockedByUnapprovedBudget = () => orcamentoPendenteAprovacao
            && (encerrarComoSelect?.value || '') === (config.deliveredStatusCode || 'entregue_reparado_pago');

        // Classificação da baixa: 'baixa' (padrão) fecha a OS de verdade;
        // 'adiantamento'/'sinal' só registra o valor, sem fechar nada — ver
        // OrderClosureService::registerAdvance() no backend.
        const isAdvanceClosure = () => (classificacaoBaixaSelect?.value || 'baixa') !== 'baixa';

        // Devolvido sem reparo / descartado não geram cobrança pela OS: a aba
        // Financeiro fica desabilitada (não navegável) e o fluxo Continuar/Voltar
        // pula direto entre Encerramento e Confirmação. "Entregue - Reparado e
        // Pago" com orçamento vinculado não aprovado: as abas Financeiro E
        // Confirmação ficam desabilitadas, e o botão "Continuar" da etapa 1
        // fica desabilitado de verdade (não é só um clique interceptado) — o
        // técnico não avança de jeito nenhum enquanto o orçamento não for
        // resolvido.
        const updateFinanceiroTabAvailability = () => {
            const disableForNoRepair = isNoRepairClosure();
            const disableForBudget = isBlockedByUnapprovedBudget();
            const disableFinanceiro = disableForNoRepair || disableForBudget;
            const budgetTitle = 'Esta OS tem um orçamento vinculado ainda não aprovado.';

            if (financeiroTabIndicator instanceof HTMLButtonElement) {
                financeiroTabIndicator.disabled = disableFinanceiro;
                financeiroTabIndicator.classList.toggle('is-disabled', disableFinanceiro);
                financeiroTabIndicator.title = disableForBudget
                    ? budgetTitle
                    : (disableForNoRepair ? 'Não aplicável para devolução sem reparo ou descarte.' : '');
            }

            if (confirmacaoTabIndicator instanceof HTMLButtonElement) {
                confirmacaoTabIndicator.disabled = disableForBudget;
                confirmacaoTabIndicator.classList.toggle('is-disabled', disableForBudget);
                confirmacaoTabIndicator.title = disableForBudget ? budgetTitle : '';
            }

            if (footerNextBtn instanceof HTMLButtonElement) {
                footerNextBtn.disabled = currentStep < 3 && disableForBudget;
                footerNextBtn.title = footerNextBtn.disabled ? budgetTitle : '';
            }
        };

        const rows = () => Array.from(receiptsList.querySelectorAll('[data-receipt-row]'));

        const updateEmptyHint = () => {
            if (emptyHint instanceof HTMLElement) {
                emptyHint.classList.toggle('d-none', rows().length > 0);
            }
        };

        // Mostra/esconde "Data da entrega" dentro do modo Adiantamento/Sinal —
        // só faz sentido (e só é obrigatória) quando "Equipamento foi
        // entregue?" está marcado. No modo Baixa ela é sempre obrigatória.
        const updateDataEntregaVisibility = () => {
            if (!(dataEntregaWrapper instanceof HTMLElement)) return;

            if (!isAdvanceClosure()) {
                dataEntregaWrapper.classList.remove('d-none');
                if (dataEntregaInput instanceof HTMLInputElement) dataEntregaInput.required = true;
                return;
            }

            const entregue = Boolean(equipamentoEntregueCheckbox?.checked);
            dataEntregaWrapper.classList.toggle('d-none', !entregue);
            if (dataEntregaInput instanceof HTMLInputElement) dataEntregaInput.required = entregue;
        };

        // Troca entre o bloco "Encerrar como" + "Data da entrega" (modo Baixa)
        // e o bloco "Equipamento foi entregue?" (modo Adiantamento/Sinal) —
        // reaproveita o MESMO input de data (move o wrapper entre os dois
        // blocos) em vez de duplicar o campo com o mesmo name.
        const updateClosureModeVisibility = () => {
            const advance = isAdvanceClosure();

            closureBaixaFields?.classList.toggle('d-none', advance);
            closureAdvanceFields?.classList.toggle('d-none', !advance);

            if (encerrarComoSelect instanceof HTMLSelectElement) {
                encerrarComoSelect.required = !advance;
            }

            if (dataEntregaWrapper instanceof HTMLElement) {
                const targetParent = advance ? closureAdvanceFields : closureBaixaFields;
                if (targetParent instanceof HTMLElement && dataEntregaWrapper.parentElement !== targetParent) {
                    targetParent.appendChild(dataEntregaWrapper);
                }
            }

            updateDataEntregaVisibility();
        };

        // "Retorno pós-serviço" não se aplica quando não é uma Baixa de
        // verdade (o atendimento não terminou) — some da etapa Confirmação e
        // garante que não vai marcado por engano.
        const updateReturnCardVisibility = () => {
            const advance = isAdvanceClosure();
            closureReturnCard?.classList.toggle('d-none', advance);

            if (advance && agendarRetornoCheckbox instanceof HTMLInputElement && agendarRetornoCheckbox.checked) {
                agendarRetornoCheckbox.checked = false;
                agendarRetornoCheckbox.dispatchEvent(new Event('change'));
            }
        };

        // Devolvido sem reparo / descartado não geram lançamento financeiro:
        // esconde os botões de lançar recebimento e remove qualquer linha já
        // adicionada (inclusive a pré-preenchida automaticamente no load
        // quando a OS tinha saldo em aberto) — senão ela continuaria no
        // formulário, oculta pela aba desabilitada, mas ainda seria enviada
        // no submit.
        const updateReceiptEntryAvailability = () => {
            const hide = isNoRepairClosure();

            const addReceiptBtn = document.querySelector('[data-action="adicionar-recebimento"]');
            if (addReceiptBtn instanceof HTMLElement) addReceiptBtn.classList.toggle('d-none', hide);
            if (receiveAllBalanceBtn instanceof HTMLElement) receiveAllBalanceBtn.classList.toggle('d-none', hide);

            if (hide && rows().length > 0) {
                rows().forEach((row) => row.remove());
                updateEmptyHint();
                recalcTotals();
            }
        };

        const updateReceiveAllBalanceButtonState = (saldoRestante) => {
            if (!(receiveAllBalanceBtn instanceof HTMLButtonElement)) {
                return;
            }

            const hasOpenBalance = saldoRestante > 0.009;
            receiveAllBalanceBtn.disabled = !hasOpenBalance;
            receiveAllBalanceBtn.classList.toggle('disabled', !hasOpenBalance);
            receiveAllBalanceBtn.setAttribute('aria-disabled', String(!hasOpenBalance));
            receiveAllBalanceBtn.title = hasOpenBalance
                ? 'Preencher o saldo em aberto'
                : 'Nao ha saldo em aberto para receber';
        };

        const toggleCardFields = (row) => {
            const formaPagamento = row.querySelector('[data-field="forma_pagamento"]')?.value || '';
            const cardFields = row.querySelector('[data-card-fields]');
            const isCard = formaPagamento.startsWith('cartao');

            if (cardFields instanceof HTMLElement) cardFields.classList.toggle('d-none', !isCard);

            if (isCard) {
                const modalidadeSelect = row.querySelector('[data-field="modalidade"]');
                if (modalidadeSelect instanceof HTMLSelectElement) {
                    modalidadeSelect.value = formaPagamento === 'cartao_debito' ? 'debito' : 'credito';
                }
            }
        };

        // Ajusta min/max do campo "Parcelas" para a faixa realmente cadastrada
        // em Financeiro > Cartões > Taxas para a operadora/modalidade/bandeira
        // selecionadas — sem isso o campo aceitava qualquer valor de 1 a 99,
        // mesmo sem taxa configurada para parcelas fora da faixa liberada.
        const syncParcelasLimits = (row) => {
            const parcelasInput = row.querySelector('[data-field="parcelas"]');
            const hintEl = row.querySelector('[data-parcelas-hint]');
            if (!(parcelasInput instanceof HTMLInputElement)) return;

            const operadoraId = row.querySelector('[data-field="operadora_id"]')?.value || '';
            const bandeiraId = row.querySelector('[data-field="bandeira_id"]')?.value || '';
            const modalidade = row.querySelector('[data-field="modalidade"]')?.value || '';

            const range = getParcelasRange(operadoraId, modalidade, bandeiraId || null);

            if (!range) {
                parcelasInput.min = '1';
                parcelasInput.max = '99';
                if (hintEl instanceof HTMLElement) {
                    hintEl.textContent = operadoraId
                        ? 'Nenhuma faixa de parcelas cadastrada para esta operadora/modalidade.'
                        : '';
                }
                return;
            }

            parcelasInput.min = String(range.min);
            parcelasInput.max = String(range.max);

            const current = parseInt(parcelasInput.value || '1', 10) || 1;
            if (current < range.min) parcelasInput.value = String(range.min);
            if (current > range.max) parcelasInput.value = String(range.max);

            if (hintEl instanceof HTMLElement) {
                hintEl.textContent = range.min === range.max
                    ? `${range.max}x disponível para esta operadora.`
                    : `${range.min}x a ${range.max}x disponíveis para esta operadora.`;
            }
        };

        const recalcTotals = () => {
            let totalRecebido = 0;
            let totalTaxas = 0;

            rows().forEach((row) => {
                const valor = parseMoney(row.querySelector('[data-field="valor"]')?.value || '0');
                totalRecebido += valor;

                const formaPagamento = row.querySelector('[data-field="forma_pagamento"]')?.value || '';
                const previewEl = row.querySelector('[data-card-preview]');

                if (!formaPagamento.startsWith('cartao')) return;

                const operadoraId = row.querySelector('[data-field="operadora_id"]')?.value || '';
                const bandeiraId = row.querySelector('[data-field="bandeira_id"]')?.value || '';
                const modalidade = row.querySelector('[data-field="modalidade"]')?.value || '';
                const parcelas = parseInt(row.querySelector('[data-field="parcelas"]')?.value || '1', 10) || 1;

                const fee = operadoraId
                    ? estimateFee(operadoraId, modalidade, parcelas, bandeiraId || null, valor)
                    : null;

                if (previewEl instanceof HTMLElement) {
                    previewEl.textContent = fee
                        ? `Taxa estimada: R$ ${formatMoney(fee.taxa)} · Líquido estimado: R$ ${formatMoney(fee.liquido)}`
                        : 'Selecione operadora, modalidade e parcelas para estimar a taxa.';
                }

                if (fee) totalTaxas += fee.taxa;
            });

            const liquido = totalRecebido - totalTaxas;
            const lucro = (Number(config.valorFinal) || 0) - (Number(config.custoTotal) || 0) - totalTaxas;
            const saldoRestante = Math.max(0, (Number(config.valorAberto) || 0) - totalRecebido);

            // Painel financeiro esquerdo (etapa 2)
            setText('closureFinSummaryAction', `R$ ${formatMoney(totalRecebido)}`);
            setText('closureFinSummaryBalance', `R$ ${formatMoney(saldoRestante)}`);
            setText('closureFinSummaryFee', `R$ ${formatMoney(totalTaxas)}`);
            setText('closureFinSummaryNet', `R$ ${formatMoney(liquido)}`);
            setText('closureFinSummaryProfit', `R$ ${formatMoney(lucro)}`);

            // Cards de métrica (etapa 2 direita)
            setText('closureMetricValorAberto', `R$ ${formatMoney(saldoRestante)}`);
            setText('closureMetricValorBaixa', `R$ ${formatMoney(totalRecebido)}`);

            // Rodapé
            setText('closureFooterBalance', `R$ ${formatMoney(saldoRestante)}`);

            // Alerta de cobranças pendentes
            const collectionsAlert = document.getElementById('closureCollectionsSummary');
            if (collectionsAlert) {
                collectionsAlert.classList.toggle('d-none', saldoRestante <= 0 || rows().length === 0);
            }

            updateReceiveAllBalanceButtonState(saldoRestante);

            return { totalRecebido, totalTaxas, saldoRestante, liquido, lucro };
        };

        const validateReceiptRow = (row) => {
            const errors = [];
            const valor = parseMoney(row.querySelector('[data-field="valor"]')?.value || '0');
            const formaPagamento = row.querySelector('[data-field="forma_pagamento"]')?.value || '';
            const operadoraId = row.querySelector('[data-field="operadora_id"]')?.value || '';

            if (valor <= 0) {
                errors.push('Informe um valor maior que zero para todos os recebimentos lançados.');
            }

            if (formaPagamento === '') {
                errors.push('Selecione a forma de pagamento de todos os recebimentos lançados.');
            }

            if (formaPagamento.startsWith('cartao')) {
                if (!operadoraId) {
                    errors.push('Selecione a operadora para recebimentos em cartão.');
                } else {
                    const bandeiraId = row.querySelector('[data-field="bandeira_id"]')?.value || '';
                    const modalidade = row.querySelector('[data-field="modalidade"]')?.value || '';
                    const parcelas = parseInt(row.querySelector('[data-field="parcelas"]')?.value || '1', 10) || 1;
                    const fee = estimateFee(operadoraId, modalidade, parcelas, bandeiraId || null, valor);
                    if (!fee) {
                        errors.push('Não há taxa configurada para a operadora/bandeira/parcelas selecionadas em um dos recebimentos em cartão.');
                    }
                }
            }

            return errors;
        };

        const validateFinancialStep = () => {
            const errors = [];
            rows().forEach((row) => {
                errors.push(...validateReceiptRow(row));
            });

            // Adiantamento/Sinal só faz sentido com pelo menos um valor lançado
            // (é a própria razão da ação existir).
            if (isAdvanceClosure() && rows().length === 0) {
                errors.push('Adicione ao menos um recebimento para registrar o adiantamento/sinal.');
            }

            const { totalRecebido } = recalcTotals();
            const valorAberto = Number(config.valorAberto) || 0;
            if (totalRecebido - valorAberto > 0.009) {
                errors.push(`O total lançado (R$ ${formatMoney(totalRecebido)}) é maior que o saldo em aberto (R$ ${formatMoney(valorAberto)}).`);
            }

            // Encerrar como "Equipamento Entregue" exige algum valor recebido — de
            // baixas/adiantamentos anteriores (valorMovimentado) ou nesta ação.
            // Pagamento parcial é aceito (o saldo restante segue como pendência
            // financeira); só bloqueia a entrega com ZERO recebido. Não se aplica a
            // adiantamento/sinal, devolução sem reparo/descarte, nem aos demais
            // encerramentos.
            const deliveredCode = config.deliveredStatusCode || 'entregue_reparado_pago';
            if (!isAdvanceClosure() && !isNoRepairClosure() && (encerrarComoSelect?.value || '') === deliveredCode) {
                const recebidoAntes = Number(config.valorMovimentado) || 0;
                if (recebidoAntes + totalRecebido <= 0.009) {
                    errors.push('Para encerrar como "Equipamento Entregue" registre ao menos um recebimento com valor maior que zero. O pagamento parcial é aceito e o saldo restante fica como pendência financeira.');
                }
            }

            return { ok: errors.length === 0, errors };
        };

        const resolveStatusLabel = (saldoRestante) => {
            if (isAdvanceClosure()) {
                const tipoLabel = classificacaoBaixaSelect?.selectedOptions?.[0]?.text || 'Adiantamento';
                const entregue = Boolean(equipamentoEntregueCheckbox?.checked) && Boolean(dataEntregaInput?.value);
                const statusLabel = entregue
                    ? (config.statusPagamentoPendente?.nome || 'Entregue - Pendência Financeira')
                    : (config.statusAtualNome || 'Sem status');

                return { tipoLabel, statusLabel };
            }

            const tipoLabel = encerrarComoSelect?.selectedOptions?.[0]?.text || '—';
            if (!isNoRepairClosure() && saldoRestante > 0.009 && config.statusPagamentoPendente?.nome) {
                return { tipoLabel, statusLabel: config.statusPagamentoPendente.nome };
            }
            return { tipoLabel, statusLabel: tipoLabel };
        };

        const updateFinancialSummary = (saldoRestanteOverride) => {
            const saldoRestante = typeof saldoRestanteOverride === 'number'
                ? saldoRestanteOverride
                : recalcTotals().saldoRestante;
            const { tipoLabel, statusLabel } = resolveStatusLabel(saldoRestante);
            setText('closureFinSummaryType', tipoLabel);
            setText('closureFinSummaryStatus', statusLabel);
            setText('closureFooterStatus', statusLabel);
        };

        const updateConfirmStep = () => {
            const { totalRecebido, totalTaxas, saldoRestante, liquido, lucro } = recalcTotals();
            updateFinancialSummary(saldoRestante);

            const { tipoLabel, statusLabel } = resolveStatusLabel(saldoRestante);
            // Em modo Adiantamento/Sinal o campo #dataEntrega fica com o valor
            // padrão (hoje) preenchido pelo blade mesmo escondido — só reflete
            // a realidade se "Equipamento foi entregue?" estiver marcado, senão
            // essa data nunca é aplicada de verdade (ver registerAdvance() no
            // backend, que só usa data_entrega quando equipamento_entregue=true).
            const entregue = isAdvanceClosure()
                ? Boolean(equipamentoEntregueCheckbox?.checked) && Boolean(dataEntregaInput?.value)
                : true;
            const dataEntrega = entregue ? (dataEntregaInput?.value || '') : '';

            setText('closureConfirmType', tipoLabel);
            setText('closureConfirmStatus', statusLabel);
            setText('closureConfirmDelivery', dataEntrega ? `Data da entrega: ${dataEntrega}` : 'Equipamento ainda não entregue');
            setText('closureConfirmActionValue', `R$ ${formatMoney(totalRecebido)}`);
            setText('closureConfirmBalance', `Saldo restante: R$ ${formatMoney(saldoRestante)}`);
            setText('closureConfirmProfit', `R$ ${formatMoney(lucro)}`);
            setText('closureConfirmNet', `Líquido previsto: R$ ${formatMoney(liquido)}`);

            // Devolvido sem reparo / descartado nunca geram pendencia financeira
            // nem cobranca agendada (o backend ja forca isso independente do
            // saldo em aberto da OS) — o preview precisa refletir a mesma regra,
            // senao mostra uma pendencia/cobranca que nunca vai de fato ocorrer.
            const paymentState = isNoRepairClosure()
                ? 'Sem pendência financeira'
                : (saldoRestante <= 0 ? 'Pagamento quitado' : `Pendência: R$ ${formatMoney(saldoRestante)}`);
            setText('closureConfirmPaymentState', `Situação financeira: ${paymentState}`);

            const confirmWarning = document.getElementById('closureConfirmWarning');
            if (confirmWarning) {
                if (isAdvanceClosure()) {
                    confirmWarning.className = 'alert alert-info';
                    confirmWarning.textContent = entregue
                        ? `A OS NÃO será encerrada — só o valor será lançado e o status vira "${config.statusPagamentoPendente?.nome || 'Entregue - Pendência Financeira'}" (continua aberta).`
                        : 'A OS NÃO será encerrada nesta ação — apenas o valor será registrado no financeiro. O status atual continua o mesmo.';
                } else if (isNoRepairClosure()) {
                    confirmWarning.className = 'alert alert-success';
                    confirmWarning.textContent = 'A OS será concluída sem lançamento financeiro nesta ação.';
                } else if (saldoRestante <= 0) {
                    confirmWarning.className = 'alert alert-success';
                    confirmWarning.textContent = 'A OS será concluída com o financeiro quitado nesta ação.';
                } else {
                    confirmWarning.className = 'alert alert-warning';
                    confirmWarning.textContent = `Saldo de R$ ${formatMoney(saldoRestante)} continuará em aberto. 3 cobranças por WhatsApp serão agendadas automaticamente.`;
                }
            }
        };

        const reindexRows = () => {
            rows().forEach((row, index) => {
                row.querySelectorAll('[data-field]').forEach((field) => {
                    const fieldName = field.getAttribute('data-field');
                    field.setAttribute('name', `recebimentos[${index}][${fieldName}]`);
                });
            });
        };

        const addRow = (overrides) => {
            const fragment = receiptTemplate.content.cloneNode(true);
            const row = fragment.querySelector('[data-receipt-row]');
            receiptsList.appendChild(row);
            receiptIndex += 1;

            if (overrides && typeof overrides === 'object') {
                Object.keys(overrides).forEach((field) => {
                    const input = row.querySelector(`[data-field="${field}"]`);
                    if (input) input.value = overrides[field];
                });
            }

            const dataPagamentoInput = row.querySelector('[data-field="data_pagamento"]');
            if (dataPagamentoInput instanceof HTMLInputElement && !dataPagamentoInput.value) {
                dataPagamentoInput.value = dataEntregaInput?.value || config.dataEntregaDefault || '';
            }

            bindMoneyInput(row.querySelector('[data-field="valor"]'));

            toggleCardFields(row);
            reindexRows();
            updateEmptyHint();
            recalcTotals();

            return row;
        };

        const goToStep = (step) => {
            currentStep = step;

            if (currentStepInput instanceof HTMLInputElement) {
                currentStepInput.value = String(step);
            }

            const panels = Array.from(document.querySelectorAll('[data-step-panel]'));
            const indicators = Array.from(document.querySelectorAll('[data-step-indicator]'));

            panels.forEach((panel) => {
                panel.classList.toggle('d-none', panel.getAttribute('data-step-panel') !== String(step));
            });

            indicators.forEach((indicator) => {
                const indicatorStep = Number(indicator.getAttribute('data-step-indicator'));
                indicator.classList.toggle('is-active', indicatorStep === step);
                indicator.classList.toggle('is-done', indicatorStep < step && !indicator.disabled);
            });

            // Botões do rodapé
            if (footerPrevBtn) footerPrevBtn.classList.toggle('d-none', step <= 1);
            if (footerNextBtn) footerNextBtn.classList.toggle('d-none', step >= 3);

            // Botão de submissão visível apenas na etapa 3
            if (submitButton) submitButton.classList.toggle('d-none', step !== 3);

            updateFinanceiroTabAvailability();

            if (step === 2) updateFinancialSummary();
            if (step === 3) updateConfirmStep();
        };

        // Valida as etapas entre a atual e o destino antes de trocar (usado pelo
        // rodapé Continuar/Voltar e pelo clique direto nas abas de indicador).
        const attemptGoToStep = (targetStep) => {
            // Devolvido sem reparo / descartado: a etapa Financeiro é pulada,
            // independentemente de quem pediu a navegação (rodapé, aba ou
            // redirecionamento de erro de validação).
            if (targetStep === 2 && isNoRepairClosure()) {
                targetStep = targetStep > currentStep ? 3 : 1;
            }

            if (targetStep === currentStep) return;

            if (targetStep > currentStep) {
                if (isAdvanceClosure()) {
                    const entregaPendente = Boolean(equipamentoEntregueCheckbox?.checked) && !dataEntregaInput?.value;
                    if (entregaPendente) {
                        dataEntregaInput?.reportValidity?.();
                        return;
                    }
                } else if (!encerrarComoSelect?.value || !dataEntregaInput?.value) {
                    encerrarComoSelect?.reportValidity?.();
                    dataEntregaInput?.reportValidity?.();
                    return;
                } else if (isBlockedByUnapprovedBudget()) {
                    showStepError('Esta OS tem um orçamento vinculado que ainda não foi aprovado. Escolha outro encerramento ou aguarde a aprovação antes de continuar.');
                    return;
                }

                if (targetStep >= 3) {
                    const result = validateFinancialStep();
                    if (!result.ok) {
                        showStepError(result.errors);
                        goToStep(isNoRepairClosure() ? 1 : 2);
                        return;
                    }
                }
            }

            clearStepError();
            goToStep(targetStep);
        };

        // Navegação pelo rodapé
        footerNextBtn?.addEventListener('click', () => {
            if (currentStep < 3) attemptGoToStep(currentStep + 1);
        });

        footerPrevBtn?.addEventListener('click', () => {
            if (currentStep > 1) attemptGoToStep(currentStep - 1);
        });

        // Navegação direta pelas abas de indicador (Encerramento/Financeiro/Confirmação)
        document.querySelectorAll('[data-step-indicator]').forEach((indicator) => {
            indicator.addEventListener('click', () => {
                const targetStep = Number(indicator.getAttribute('data-step-indicator'));
                if (Number.isInteger(targetStep)) attemptGoToStep(targetStep);
            });
        });

        // Revalidação defensiva no submit (cobre edição de recebimentos já na etapa 3)
        form.addEventListener('submit', (event) => {
            if (isBlockedByUnapprovedBudget()) {
                event.preventDefault();
                showStepError('Esta OS tem um orçamento vinculado que ainda não foi aprovado. Escolha outro encerramento ou aguarde a aprovação antes de continuar.');
                goToStep(1);
                return;
            }

            const result = validateFinancialStep();
            if (!result.ok) {
                event.preventDefault();
                showStepError(result.errors);
                goToStep(2);
                return;
            }

            // Desfaz a mascara BRL ("R$ 50,00") antes de enviar: backend e
            // validacao do desktop esperam o valor canonico ("50.00").
            rows().forEach((row) => {
                const valorInput = row.querySelector('[data-field="valor"]');
                if (valorInput instanceof HTMLInputElement) {
                    valorInput.value = parseMoney(valorInput.value).toFixed(2);
                }
            });
        });

        // Eventos dos recebimentos
        receiptsList.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;

            if (target.matches('[data-action="remover-recebimento"]')) {
                target.closest('[data-receipt-row]')?.remove();
                reindexRows();
                updateEmptyHint();
                recalcTotals();
            }
        });

        receiptsList.addEventListener('input', (event) => {
            if (event.target instanceof HTMLElement) recalcTotals();
        });

        const handleReceiptChange = (target) => {
            if (!(target instanceof HTMLElement)) return;

            const row = target.closest('[data-receipt-row]');

            if (target.matches('[data-field="forma_pagamento"]')) {
                toggleCardFields(row);
            }

            if (row && target.matches('[data-field="operadora_id"], [data-field="bandeira_id"], [data-field="modalidade"], [data-field="forma_pagamento"]')) {
                syncParcelasLimits(row);
            }

            recalcTotals();
        };

        receiptsList.addEventListener('change', (event) => handleReceiptChange(event.target));

        // Select2 (aplicado por padrao em todo `select.form-select` pelo init
        // global de desktop.js) dispara `change` apenas via
        // `jQuery(el).trigger('change')` ao selecionar uma opcao pela sua UI —
        // isso nao gera um evento nativo que propague ate o addEventListener
        // acima. Sem este binding paralelo via jQuery, escolher operadora,
        // bandeira, modalidade ou forma de pagamento pelo Select2 nao
        // atualizava os campos de cartao nem o preview de taxa.
        if (window.jQuery) {
            window.jQuery(receiptsList).on('change', (event) => handleReceiptChange(event.target));
        }

        document.querySelector('[data-action="adicionar-recebimento"]')?.addEventListener('click', () => {
            addRow();
        });

        receiveAllBalanceBtn?.addEventListener('click', () => {
            const { saldoRestante } = recalcTotals();
            if (saldoRestante <= 0.009) {
                showStepError('Nao ha saldo em aberto para receber nesta OS.');
                return;
            }

            clearStepError();
            addRow({ valor: saldoRestante.toFixed(2) });
        });

        const handleEncerrarComoChange = () => {
            updateReceiptEntryAvailability();
            updateFinancialSummary();
            updateFinanceiroTabAvailability();

            // Se o usuário estava na etapa Financeiro e mudou para um status
            // sem reparo, sai imediatamente dessa etapa (agora desabilitada).
            if (currentStep === 2 && isNoRepairClosure()) {
                goToStep(1);
            }
        };
        encerrarComoSelect?.addEventListener('change', handleEncerrarComoChange);
        // Select2 (aplicado por padrao em todo `select.form-select` pelo init
        // global de desktop.js) dispara `change` apenas via
        // `jQuery(el).trigger('change')` ao escolher uma opcao pela sua UI —
        // isso nao gera um evento nativo que propague ate o addEventListener
        // acima (mesmo padrao ja visto em classificacaoBaixaSelect e
        // receiptsList, ver comentarios acima). Sem este binding paralelo, a
        // trava de orcamento nao aprovado (updateFinanceiroTabAvailability)
        // NUNCA rodava ao escolher "Entregue - Reparado e Pago" pela UI real
        // do Select2 — so via script/teste automatizado que seta o valor
        // direto, mascarando o bug.
        if (window.jQuery && encerrarComoSelect) {
            window.jQuery(encerrarComoSelect).on('change', handleEncerrarComoChange);
        }

        // Classificação da baixa: Baixa (fecha a OS) vs Adiantamento/Sinal
        // (só registra o valor). Troca qual bloco aparece na etapa
        // Encerramento e some com "Retorno pós-serviço" na Confirmação.
        const handleClassificacaoBaixaChange = () => {
            clearStepError();
            updateClosureModeVisibility();
            updateReturnCardVisibility();
            updateFinancialSummary();
        };
        classificacaoBaixaSelect?.addEventListener('change', handleClassificacaoBaixaChange);
        // Select2 (aplicado por padrao em todo `select.form-select` pelo init
        // global de desktop.js) dispara `change` apenas via
        // `jQuery(el).trigger('change')` ao escolher uma opcao pela sua UI —
        // isso nao gera um evento nativo que propague ate o addEventListener
        // acima. Sem este binding paralelo, escolher Adiantamento/Sinal pelo
        // Select2 nao escondia "Encerrar como"/"Data da entrega" nem mostrava
        // o toggle "Equipamento foi entregue?" (mesmo padrao ja visto nos
        // campos de cartao do recebimento, ver bind de receiptsList acima).
        if (window.jQuery && classificacaoBaixaSelect) {
            window.jQuery(classificacaoBaixaSelect).on('change', handleClassificacaoBaixaChange);
        }

        equipamentoEntregueCheckbox?.addEventListener('change', () => {
            updateDataEntregaVisibility();
            updateFinancialSummary();
        });

        // Toggle de notificação
        notificarClienteCheckbox?.addEventListener('change', () => {
            const phone = config.clienteTelefone || '';
            const isOn = notificarClienteCheckbox.checked;
            const stateText = isOn
                ? (phone ? `Enviar por WhatsApp (${phone})` : 'Enviar por WhatsApp')
                : 'Não enviar';
            setText('closureNotifyState', stateText);
            setText('closureFooterNotify', stateText);
        });

        // Toggle de retorno pós-serviço
        agendarRetornoCheckbox?.addEventListener('change', () => {
            const isOn = agendarRetornoCheckbox.checked;
            retornoDataWrapper?.classList.toggle('d-none', !isOn);
            const returnDate = document.getElementById('retornoData')?.value || '';
            setText('closureReturnState', isOn ? `Agendar para ${returnDate || '—'}` : 'Não agendar');
        });

        document.getElementById('retornoData')?.addEventListener('change', (e) => {
            if (agendarRetornoCheckbox?.checked) {
                setText('closureReturnState', `Agendar para ${e.target.value || '—'}`);
            }
        });

        // Habilitar botão de submissão ao confirmar revisão
        confirmacaoCheckbox?.addEventListener('change', () => {
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = !confirmacaoCheckbox.checked;
            }
        });

        // Estado inicial: reconstrói recebimentos anteriores (retorno de validação).
        // Nunca insere automaticamente o saldo total numa linha — isso só deve
        // acontecer quando o usuário clicar em "Receber saldo total".
        const oldReceipts = Array.isArray(config.old?.recebimentos) ? config.old.recebimentos : [];
        oldReceipts.forEach((receipt) => addRow(receipt));

        updateClosureModeVisibility();
        updateReturnCardVisibility();
        updateReceiptEntryAvailability();
        updateFinanceiroTabAvailability();
        retornoDataWrapper?.classList.toggle('d-none', !agendarRetornoCheckbox?.checked);

        // Reidratação de erros de recebimentos vindos do backend (após redirect por falha de validação)
        const recebimentoErrors = config.recebimentoErrors && typeof config.recebimentoErrors === 'object'
            ? config.recebimentoErrors
            : {};
        const recebimentoErrorMessages = [];

        Object.keys(recebimentoErrors).forEach((key) => {
            const match = key.match(/^recebimentos\.(\d+)\.(.+)$/);
            if (!match) return;

            const rowIndex = Number(match[1]);
            const fieldName = match[2];
            const row = rows()[rowIndex];
            const input = row?.querySelector(`[data-field="${fieldName}"]`);
            if (input instanceof HTMLElement) {
                input.classList.add('is-invalid');
            }
            recebimentoErrorMessages.push(recebimentoErrors[key]);
        });

        // Prioridade de navegação pós-erro: campo com erro > etapa salva pelo usuário
        const hasOperationalFieldError = Boolean(
            encerrarComoSelect?.classList.contains('is-invalid')
            || dataEntregaInput?.classList.contains('is-invalid')
        );

        if (hasOperationalFieldError) {
            currentStep = 1;
        } else if (recebimentoErrorMessages.length > 0) {
            currentStep = isNoRepairClosure() ? 1 : 2;
        }

        // Estado restaurado (ex.: old('current_step')) pode apontar para a
        // etapa Financeiro mesmo com um status sem reparo já selecionado.
        if (currentStep === 2 && isNoRepairClosure()) {
            currentStep = 1;
        }

        goToStep(currentStep);

        if (recebimentoErrorMessages.length > 0) {
            showStepError(recebimentoErrorMessages);
        }
    });
})();
