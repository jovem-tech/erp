(function () {
    const config = window.__DESKTOP_ORDER_CLOSURE || {};
    const cartaoTaxas = Array.isArray(config.cartao?.taxas) ? config.cartao.taxas : [];
    const noRepairStatuses = Array.isArray(config.noRepairStatuses) ? config.noRepairStatuses : [];

    const formatMoney = (value) => Number(value || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

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
        const encerrarComoSelect = document.getElementById('encerrarComo');
        const dataEntregaInput = document.getElementById('dataEntrega');
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
        const currentStepInput = document.getElementById('closureCurrentStepInput');
        const stepErrorBox = document.getElementById('closureStepError');

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

        const rows = () => Array.from(receiptsList.querySelectorAll('[data-receipt-row]'));

        const updateEmptyHint = () => {
            if (emptyHint instanceof HTMLElement) {
                emptyHint.classList.toggle('d-none', rows().length > 0);
            }
        };

        const updateClassificationVisibility = () => {
            const hide = isNoRepairClosure();
            rows().forEach((row) => {
                const field = row.querySelector('[data-classificacao-field]');
                if (field instanceof HTMLElement) field.classList.toggle('d-none', hide);
            });

            const advanceBtn = document.querySelector('[data-action="adicionar-adiantamento"]');
            if (advanceBtn instanceof HTMLElement) advanceBtn.classList.toggle('d-none', hide);
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

        const recalcTotals = () => {
            let totalRecebido = 0;
            let totalTaxas = 0;

            rows().forEach((row) => {
                const valor = parseFloat(row.querySelector('[data-field="valor"]')?.value || '0') || 0;
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

            return { totalRecebido, totalTaxas, saldoRestante, liquido, lucro };
        };

        const validateReceiptRow = (row) => {
            const errors = [];
            const valor = parseFloat(row.querySelector('[data-field="valor"]')?.value || '0') || 0;
            const formaPagamento = row.querySelector('[data-field="forma_pagamento"]')?.value || '';
            const operadoraId = row.querySelector('[data-field="operadora_id"]')?.value || '';

            if (valor <= 0) {
                errors.push('Informe um valor maior que zero para todos os recebimentos lançados.');
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

            const { totalRecebido } = recalcTotals();
            const valorAberto = Number(config.valorAberto) || 0;
            if (totalRecebido - valorAberto > 0.009) {
                errors.push(`O total lançado (R$ ${formatMoney(totalRecebido)}) é maior que o saldo em aberto (R$ ${formatMoney(valorAberto)}).`);
            }

            return { ok: errors.length === 0, errors };
        };

        const resolveStatusLabel = (saldoRestante) => {
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
            const dataEntrega = dataEntregaInput?.value || '';

            setText('closureConfirmType', tipoLabel);
            setText('closureConfirmStatus', statusLabel);
            setText('closureConfirmDelivery', dataEntrega ? `Data da entrega: ${dataEntrega}` : 'Data da entrega: —');
            setText('closureConfirmActionValue', `R$ ${formatMoney(totalRecebido)}`);
            setText('closureConfirmBalance', `Saldo restante: R$ ${formatMoney(saldoRestante)}`);
            setText('closureConfirmProfit', `R$ ${formatMoney(lucro)}`);
            setText('closureConfirmNet', `Líquido previsto: R$ ${formatMoney(liquido)}`);

            const paymentState = saldoRestante <= 0 ? 'Pagamento quitado' : `Pendência: R$ ${formatMoney(saldoRestante)}`;
            setText('closureConfirmPaymentState', `Situação financeira: ${paymentState}`);

            const confirmWarning = document.getElementById('closureConfirmWarning');
            if (confirmWarning) {
                if (saldoRestante <= 0) {
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

            toggleCardFields(row);
            reindexRows();
            updateEmptyHint();
            updateClassificationVisibility();
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
                indicator.classList.toggle('is-done', indicatorStep < step);
            });

            // Botões do rodapé
            if (footerPrevBtn) footerPrevBtn.classList.toggle('d-none', step <= 1);
            if (footerNextBtn) footerNextBtn.classList.toggle('d-none', step >= 3);

            // Botão de submissão visível apenas na etapa 3
            if (submitButton) submitButton.classList.toggle('d-none', step !== 3);

            if (step === 2) updateFinancialSummary();
            if (step === 3) updateConfirmStep();
        };

        // Valida as etapas entre a atual e o destino antes de trocar (usado pelo
        // rodapé Continuar/Voltar e pelo clique direto nas abas de indicador).
        const attemptGoToStep = (targetStep) => {
            if (targetStep === currentStep) return;

            if (targetStep > currentStep) {
                if (!encerrarComoSelect?.value || !dataEntregaInput?.value) {
                    encerrarComoSelect?.reportValidity?.();
                    dataEntregaInput?.reportValidity?.();
                    return;
                }

                if (targetStep >= 3) {
                    const result = validateFinancialStep();
                    if (!result.ok) {
                        showStepError(result.errors);
                        goToStep(2);
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
            const result = validateFinancialStep();
            if (!result.ok) {
                event.preventDefault();
                showStepError(result.errors);
                goToStep(2);
            }
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

        receiptsList.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;

            if (target.matches('[data-field="forma_pagamento"]')) {
                toggleCardFields(target.closest('[data-receipt-row]'));
            }

            recalcTotals();
        });

        document.querySelector('[data-action="adicionar-recebimento"]')?.addEventListener('click', () => {
            addRow();
        });

        document.querySelector('[data-action="adicionar-adiantamento"]')?.addEventListener('click', () => {
            addRow({ classificacao_recebimento: 'adiantamento' });
        });

        document.querySelector('[data-action="receber-saldo-total"]')?.addEventListener('click', () => {
            const { saldoRestante } = recalcTotals();
            if (saldoRestante > 0) addRow({ valor: saldoRestante.toFixed(2) });
        });

        encerrarComoSelect?.addEventListener('change', () => {
            updateClassificationVisibility();
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

        // Estado inicial: reconstrói recebimentos anteriores (retorno de validação)
        const oldReceipts = Array.isArray(config.old?.recebimentos) ? config.old.recebimentos : [];
        if (oldReceipts.length > 0) {
            oldReceipts.forEach((receipt) => addRow(receipt));
        } else if ((Number(config.valorAberto) || 0) > 0) {
            addRow({ valor: Number(config.valorAberto).toFixed(2) });
        }

        updateClassificationVisibility();
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
            currentStep = 2;
        }

        goToStep(currentStep);

        if (recebimentoErrorMessages.length > 0) {
            showStepError(recebimentoErrorMessages);
        }
    });
})();
