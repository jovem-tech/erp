/**
 * Helpers de taxa de cartão compartilhados entre telas de recebimento.
 *
 * Extraído de `orders-closure.js` (baixa de OS) para o PDV não virar a segunda
 * cópia da mesma regra — specs/027-vendas-balcao-pdv.
 *
 * `orders-closure.js` ainda usa a cópia própria: migrá-lo é um refactor
 * separado, porque o fluxo de baixa de OS não tem cobertura automatizada de JS
 * e não pertence à entrega do módulo de vendas. Quem for mexer naquele arquivo:
 * troque as três funções locais por estas.
 *
 * O dataset esperado é o de FinanceiroCartaoService::buildActiveDataset():
 * { operadoras: [...], bandeiras: [...], taxas: [...] }.
 */
window.PagamentosCartao = (function () {
    'use strict';

    /**
     * Taxa aplicável para operadora + modalidade + parcelas (+ bandeira).
     *
     * Empate é resolvido pela mais específica: bandeira definida ganha de
     * bandeira coringa, e faixa de parcelas mais estreita ganha da mais ampla.
     */
    const findApplicableRate = (taxas, operadoraId, modalidade, parcelas, bandeiraId) => {
        if (!Array.isArray(taxas) || !operadoraId || !modalidade) return null;

        const candidates = taxas.filter((taxa) => {
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

    /**
     * Faixa de parcelas realmente liberada nas taxas cadastradas — usada para
     * limitar o campo "Parcelas" em vez de aceitar qualquer valor de 1 a 99.
     */
    const getParcelasRange = (taxas, operadoraId, modalidade, bandeiraId) => {
        if (!Array.isArray(taxas) || !operadoraId || !modalidade) return null;

        const candidates = taxas.filter((taxa) => {
            if (Number(taxa.operadora_id) !== Number(operadoraId)) return false;
            if (taxa.modalidade !== modalidade) return false;
            if (taxa.bandeira_id === null || taxa.bandeira_id === undefined) return true;
            return bandeiraId !== null && Number(taxa.bandeira_id) === Number(bandeiraId);
        });

        if (candidates.length === 0) return null;

        const min = candidates.reduce(
            (acc, taxa) => Math.min(acc, Math.max(1, Number(taxa.parcelas_inicial) || 1)),
            Infinity
        );
        const max = candidates.reduce(
            (acc, taxa) => Math.max(acc, Math.max(1, Number(taxa.parcelas_final) || 1)),
            1
        );

        return { min: min === Infinity ? 1 : min, max };
    };

    /**
     * Estimativa de taxa/líquido. Apenas indicativa: o valor gravado é sempre o
     * que FinanceiroCartaoService::simulate() calcula no backend.
     */
    const estimateFee = (taxas, operadoraId, modalidade, parcelas, bandeiraId, valorBruto) => {
        const rate = findApplicableRate(taxas, operadoraId, modalidade, parcelas, bandeiraId);
        if (!rate || !valorBruto) return null;

        const percentual = Number(rate.taxa_percentual) || 0;
        const fixa = Number(rate.taxa_fixa) || 0;
        const taxa = Math.round((valorBruto * (percentual / 100) + fixa) * 100) / 100;
        const liquido = Math.round((valorBruto - taxa) * 100) / 100;

        return { taxa, liquido, prazo: Number(rate.prazo_recebimento_dias) || 0 };
    };

    return { findApplicableRate, getParcelasRange, estimateFee };
})();
