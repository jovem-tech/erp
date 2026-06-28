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
        if (el) {
            el.textContent = text;
        }
    };

    const findApplicableRate = (operadoraId, modalidade, parcelas, bandeiraId) => {
        if (!operadoraId || !modalidade) {
            return null;
        }

        const candidates = cartaoTaxas.filter((taxa) => {
            if (Number(taxa.operadora_id) !== Number(operadoraId)) {
                return false;
            }
            if (taxa.modalidade !== modalidade) {
                return false;
            }

            const inicio = Math.max(1, Number(taxa.parcelas_inicial) || 1);
            const fim = Math.max(inicio, Number(taxa.parcelas_final) || inicio);
            if (parcelas < inicio || parcelas > fim) {
                return false;
            }

            if (taxa.bandeira_id === null || taxa.bandeira_id === undefined) {
                return true;
            }

            return bandeiraId !== null && Number(taxa.bandeira_id) === Number(bandeiraId);
        });

        if (candidates.length === 0) {
            return null;
        }

        candidates.sort((a, b) => {
            const aSpecific = bandeiraId !== null && a.bandeira_id !== null ? 1 : 0;
            const bSpecific = bandeiraId !== null && b.bandeira_id !== null ? 1 : 0;
            if (aSpecific !== bSpecific) {
                return bSpecific - aSpecific;
            }

            const aRange = (Number(a.parcelas_final) || 1) - (Number(a.parcelas_inicial) || 1);
            const bRange = (Number(b.parcelas_final) || 1) - (Number(b.parcelas_inicial) || 1);
            if (aRange !== bRange) {
                return aRange - bRange;
            }

            return Number(a.id) - Number(b.id);
        });

        return candidates[0];
    };

    const estimateFee = (operadoraId, modalidade, parcelas, bandeiraId, valorBruto) => {
        const rate = findApplicableRate(operadoraId, modalidade, parcelas, bandeiraId);
        if (!rate || !valorBruto) {
            return null;
        }

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
        const retornoDataWrapper = document.getElementById('closureRetornoDataWrapper');
        const confirmacaoCheckbox = document.getElementById('confirmacaoBaixa');
        const submitButton = document.getElementById('closureSubmitButton');
        const summaryBox = document.getElementById('closureSummaryBox');

        if (!(form instanceof HTMLFormElement) || !(receiptsList instanceof HTMLElement) || !(receiptTemplate instanceof HTMLTemplateElement)) {
            return;
        }

        let receiptIndex = 0;

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
                if (field instanceof HTMLElement) {
                    field.classList.toggle('d-none', hide);
                }
            });

            const advanceBtn = document.querySelector('[data-action="adicionar-adiantamento"]');
            if (advanceBtn instanceof HTMLElement) {
                advanceBtn.classList.toggle('d-none', hide);
            }
        };

        const toggleCardFields = (row) => {
            const formaPagamento = row.querySelector('[data-field="forma_pagamento"]')?.value || '';
            const cardFields = row.querySelector('[data-card-fields]');
            const isCard = formaPagamento.startsWith('cartao');

            if (cardFields instanceof HTMLElement) {
                cardFields.classList.toggle('d-none', !isCard);
            }

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

                if (!formaPagamento.startsWith('cartao')) {
                    return;
                }

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

                if (fee) {
                    totalTaxas += fee.taxa;
                }
            });

            const liquido = totalRecebido - totalTaxas;
            const lucro = (Number(config.valorFinal) || 0) - (Number(config.custoTotal) || 0) - totalTaxas;
            const saldoRestante = Math.max(0, (Number(config.valorAberto) || 0) - totalRecebido);

            setText('closureTotalTaxas', `R$ ${formatMoney(totalTaxas)}`);
            setText('closureTotalLiquido', `R$ ${formatMoney(liquido)}`);
            setText('closureLucroEstimado', `R$ ${formatMoney(lucro)}`);
            setText('closureSaldoAbertoStep2', `R$ ${formatMoney(saldoRestante)}`);

            return { totalRecebido, totalTaxas, saldoRestante };
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
                    if (input) {
                        input.value = overrides[field];
                    }
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

        receiptsList.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.matches('[data-action="remover-recebimento"]')) {
                target.closest('[data-receipt-row]')?.remove();
                reindexRows();
                updateEmptyHint();
                recalcTotals();
            }
        });

        receiptsList.addEventListener('input', (event) => {
            if (event.target instanceof HTMLElement) {
                recalcTotals();
            }
        });

        receiptsList.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

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
            if (saldoRestante > 0) {
                addRow({ valor: saldoRestante.toFixed(2) });
            }
        });

        encerrarComoSelect?.addEventListener('change', updateClassificationVisibility);

        agendarRetornoCheckbox?.addEventListener('change', () => {
            retornoDataWrapper?.classList.toggle('d-none', !agendarRetornoCheckbox.checked);
        });

        confirmacaoCheckbox?.addEventListener('change', () => {
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = !confirmacaoCheckbox.checked;
            }
        });

        // Navegação entre etapas
        const panels = Array.from(document.querySelectorAll('[data-step-panel]'));
        const indicators = Array.from(document.querySelectorAll('[data-step-indicator]'));

        const goToStep = (step) => {
            panels.forEach((panel) => {
                panel.classList.toggle('d-none', panel.getAttribute('data-step-panel') !== String(step));
            });

            indicators.forEach((indicator) => {
                const indicatorStep = Number(indicator.getAttribute('data-step-indicator'));
                indicator.classList.toggle('is-active', indicatorStep === step);
                indicator.classList.toggle('is-done', indicatorStep < step);
            });

            if (step === 3) {
                renderSummary();
            }
        };

        const renderSummary = () => {
            if (!(summaryBox instanceof HTMLElement)) {
                return;
            }

            const { totalRecebido, totalTaxas, saldoRestante } = recalcTotals();
            const encerrarComoLabel = encerrarComoSelect?.selectedOptions?.[0]?.text || 'Não selecionado';
            const dataEntrega = dataEntregaInput?.value || '';

            const linhas = [
                `<strong>Encerrar como:</strong> ${encerrarComoLabel}`,
                `<strong>Data de entrega:</strong> ${dataEntrega || 'Não informada'}`,
                `<strong>Total a receber nesta baixa:</strong> R$ ${formatMoney(totalRecebido)}`,
            ];

            if (totalTaxas > 0) {
                linhas.push(`<strong>Taxas de cartão estimadas:</strong> R$ ${formatMoney(totalTaxas)}`);
            }

            if (saldoRestante > 0 && !isNoRepairClosure()) {
                linhas.push(`<strong>Saldo que continuará em aberto:</strong> R$ ${formatMoney(saldoRestante)} (a OS ficará com status "Entregue - Pendência Financeira" e 3 cobranças por WhatsApp serão agendadas).`);
            }

            summaryBox.innerHTML = `<ul class="mb-0 ps-3">${linhas.map((linha) => `<li>${linha}</li>`).join('')}</ul>`;
        };

        document.querySelectorAll('[data-step-action="next"]').forEach((button) => {
            button.addEventListener('click', () => {
                const currentPanel = button.closest('[data-step-panel]');
                const currentStep = Number(currentPanel?.getAttribute('data-step-panel') || '1');

                if (currentStep === 1) {
                    if (!encerrarComoSelect?.value || !dataEntregaInput?.value) {
                        encerrarComoSelect?.reportValidity?.();
                        dataEntregaInput?.reportValidity?.();
                        return;
                    }
                }

                goToStep(currentStep + 1);
            });
        });

        document.querySelectorAll('[data-step-action="prev"]').forEach((button) => {
            button.addEventListener('click', () => {
                const currentPanel = button.closest('[data-step-panel]');
                const currentStep = Number(currentPanel?.getAttribute('data-step-panel') || '1');
                goToStep(Math.max(1, currentStep - 1));
            });
        });

        // Estado inicial: reconstrói recebimentos antigos (retorno de validação) ou
        // sugere um único recebimento com o saldo em aberto.
        const oldReceipts = Array.isArray(config.old?.recebimentos) ? config.old.recebimentos : [];
        if (oldReceipts.length > 0) {
            oldReceipts.forEach((receipt) => addRow(receipt));
        } else if ((Number(config.valorAberto) || 0) > 0) {
            addRow({ valor: Number(config.valorAberto).toFixed(2) });
        }

        updateClassificationVisibility();
        retornoDataWrapper?.classList.toggle('d-none', !agendarRetornoCheckbox?.checked);
    });
})();
