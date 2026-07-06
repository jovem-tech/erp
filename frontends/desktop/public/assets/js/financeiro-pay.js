(function () {
    const config = window.__DESKTOP_FINANCEIRO_INDEX || {};
    const cartaoTaxas = Array.isArray(config.cartao?.taxas) ? config.cartao.taxas : [];

    const formatMoney = (value) => Number(value || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const findApplicableRate = (operadoraId, modalidade, parcelas, bandeiraId) => {
        if (!operadoraId || !modalidade) { return null; }

        const candidates = cartaoTaxas.filter((taxa) => {
            if (Number(taxa.operadora_id) !== Number(operadoraId)) { return false; }
            if (taxa.modalidade !== modalidade) { return false; }

            const inicio = Math.max(1, Number(taxa.parcelas_inicial) || 1);
            const fim = Math.max(inicio, Number(taxa.parcelas_final) || inicio);
            if (parcelas < inicio || parcelas > fim) { return false; }

            if (taxa.bandeira_id === null || taxa.bandeira_id === undefined) { return true; }
            return bandeiraId !== null && Number(taxa.bandeira_id) === Number(bandeiraId);
        });

        if (candidates.length === 0) { return null; }

        candidates.sort((a, b) => {
            const aSpecific = bandeiraId !== null && a.bandeira_id !== null ? 1 : 0;
            const bSpecific = bandeiraId !== null && b.bandeira_id !== null ? 1 : 0;
            if (aSpecific !== bSpecific) { return bSpecific - aSpecific; }

            const aRange = (Number(a.parcelas_final) || 1) - (Number(a.parcelas_inicial) || 1);
            const bRange = (Number(b.parcelas_final) || 1) - (Number(b.parcelas_inicial) || 1);
            if (aRange !== bRange) { return aRange - bRange; }

            return Number(a.id) - Number(b.id);
        });

        return candidates[0];
    };

    const estimateFee = (operadoraId, modalidade, parcelas, bandeiraId, valorBruto) => {
        const rate = findApplicableRate(operadoraId, modalidade, parcelas, bandeiraId);
        if (!rate || !valorBruto) { return null; }

        const percentual = Number(rate.taxa_percentual) || 0;
        const fixa = Number(rate.taxa_fixa) || 0;
        const taxa = Math.round((valorBruto * (percentual / 100) + fixa) * 100) / 100;
        const liquido = Math.round((valorBruto - taxa) * 100) / 100;

        return { taxa, liquido };
    };

    const initPayForm = (form) => {
        const valorInput = form.querySelector('[data-field="valor_movimento"]');
        const formaPagamentoSelect = form.querySelector('[data-field="forma_pagamento"]');
        const cardFields = form.querySelector('[data-card-fields]');
        const operadoraSelect = form.querySelector('[data-field="operadora_id"]');
        const bandeiraSelect = form.querySelector('[data-field="bandeira_id"]');
        const modalidadeSelect = form.querySelector('[data-field="modalidade"]');
        const parcelasInput = form.querySelector('[data-field="parcelas"]');
        const preview = form.querySelector('[data-card-preview]');
        const valorAberto = parseFloat(form.dataset.valorAberto || '0') || 0;

        if (!(valorInput instanceof HTMLInputElement)) { return; }

        // Select2 (usado em todo `select.form-select` pelo init global de
        // desktop.js) dispara `change` apenas via `jQuery(el).trigger('change')`
        // ao selecionar uma opção pela sua UI — isso NÃO chega a um listener
        // registrado com `addEventListener('change', ...)`, só a handlers
        // ligados via jQuery. Por isso todo campo que pode virar um Select2
        // precisa do binding duplicado abaixo; inputs nativos (valor, parcelas)
        // continuam funcionando normalmente só com addEventListener.
        const bindChange = (el, handler) => {
            if (!el) { return; }
            el.addEventListener('change', handler);
            if (window.jQuery) { window.jQuery(el).on('change', handler); }
        };

        const toggleCardFields = () => {
            const formaPagamento = formaPagamentoSelect instanceof HTMLSelectElement ? formaPagamentoSelect.value : '';
            const isCard = formaPagamento.startsWith('cartao');

            if (cardFields instanceof HTMLElement) { cardFields.classList.toggle('d-none', !isCard); }

            if (isCard && modalidadeSelect instanceof HTMLSelectElement) {
                modalidadeSelect.value = formaPagamento === 'cartao_debito' ? 'debito' : 'credito';
            }
        };

        const updatePreview = () => {
            const formaPagamento = formaPagamentoSelect instanceof HTMLSelectElement ? formaPagamentoSelect.value : '';
            if (!formaPagamento.startsWith('cartao') || !(preview instanceof HTMLElement)) { return; }

            const operadoraId = operadoraSelect instanceof HTMLSelectElement ? operadoraSelect.value : '';
            const bandeiraId = bandeiraSelect instanceof HTMLSelectElement ? bandeiraSelect.value : '';
            const modalidade = modalidadeSelect instanceof HTMLSelectElement ? modalidadeSelect.value : '';
            const parcelas = parseInt(parcelasInput instanceof HTMLInputElement ? parcelasInput.value : '1', 10) || 1;
            const valor = parseFloat(valorInput.value || '0') || 0;

            const fee = operadoraId
                ? estimateFee(operadoraId, modalidade, parcelas, bandeiraId || null, valor)
                : null;

            preview.textContent = fee
                ? `Taxa estimada: R$ ${formatMoney(fee.taxa)} · Líquido estimado: R$ ${formatMoney(fee.liquido)}`
                : 'Selecione operadora, modalidade e parcelas para estimar a taxa.';
        };

        form.querySelector('[data-action="valor-total"]')?.addEventListener('click', () => {
            valorInput.value = valorAberto.toFixed(2);
            updatePreview();
        });

        form.querySelector('[data-action="valor-parcial"]')?.addEventListener('click', () => {
            valorInput.value = '';
            valorInput.focus();
            updatePreview();
        });

        bindChange(formaPagamentoSelect, () => {
            toggleCardFields();
            updatePreview();
        });

        [valorInput, operadoraSelect, bandeiraSelect, modalidadeSelect, parcelasInput].forEach((field) => {
            field?.addEventListener('input', updatePreview);
            bindChange(field, updatePreview);
        });

        toggleCardFields();
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-financeiro-pay-form]').forEach((form) => {
            if (form instanceof HTMLFormElement) { initPayForm(form); }
        });
    });
})();
