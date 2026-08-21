(function () {
    // Modal "Lançar despesa em fatura paga": trava o calendário da data da
    // compra na janela em que a fatura escolhida esteve aberta (do dia seguinte
    // ao fechamento anterior até o fechamento dela).
    //
    // Sem isso dava para escolher qualquer data passada, montar o formulário
    // inteiro e só descobrir no save que a compra cairia em outra fatura — o
    // backend recusa (ver FinanceiroCartaoCreditoService::registerForgottenExpense()),
    // mas errar antes de digitar é bem melhor do que errar depois.
    const select = document.querySelector('[data-despesa-esquecida-fatura]');
    const dataCompra = document.querySelector('[data-despesa-esquecida-data-compra]');
    const janelaHint = document.querySelector('[data-despesa-esquecida-janela]');

    if (!(select instanceof HTMLSelectElement) || !(dataCompra instanceof HTMLInputElement)) {
        return;
    }

    const formatarBr = (iso) => {
        if (!iso) { return ''; }
        const [ano, mes, dia] = iso.split('-');

        return `${dia}/${mes}/${ano}`;
    };

    const aplicarJanela = () => {
        const opcao = select.selectedOptions[0];
        const abertura = opcao?.dataset.abertura || '';
        const fechamento = opcao?.dataset.fechamento || '';

        if (abertura === '' || fechamento === '') {
            dataCompra.removeAttribute('min');
            dataCompra.removeAttribute('max');
            return;
        }

        dataCompra.min = abertura;
        dataCompra.max = fechamento;

        if (janelaHint instanceof HTMLElement) {
            janelaHint.textContent = `Compras de ${formatarBr(abertura)} a ${formatarBr(fechamento)} entram nesta fatura.`;
        }

        // Data já preenchida que ficou fora da janela (troca de fatura) seria
        // recusada no save — limpa para o usuário escolher de novo dentro do
        // intervalo agora permitido.
        if (dataCompra.value !== '' && (dataCompra.value < abertura || dataCompra.value > fechamento)) {
            dataCompra.value = '';
        }
    };

    select.addEventListener('change', aplicarJanela);

    // Os selects viram Select2, que não dispara o 'change' nativo de forma
    // confiável — mesmo par de listeners usado no resto do financeiro.
    if (window.jQuery) {
        window.jQuery(select).on('change', aplicarJanela);
    }

    aplicarJanela();
})();
