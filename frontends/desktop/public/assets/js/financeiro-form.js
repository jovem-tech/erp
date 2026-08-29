(function () {
    const config = window.__DESKTOP_FINANCEIRO_FORM || {};

    const clientSearchUrl = String(config.clientSearchUrl || '').trim();
    const orderSearchUrl = String(config.orderSearchUrl || '').trim();
    const supplierSearchUrl = String(config.supplierSearchUrl || '').trim();
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const els = {
        clientSelect: document.getElementById(config.clientSelectId || 'financeiroClienteId'),
        categoriaSelect: document.getElementById('financeiroCategoria'),
        valorDisplay: document.getElementById('financeiroValorDisplay'),
        valorHidden: document.getElementById('financeiroValorHidden'),
        quickClientModal: document.getElementById('quickClientModal'),
        quickClientForm: document.getElementById('quickClientForm'),
        quickClientSubmit: document.getElementById('quickClientSubmit'),
        quickClientButton: document.getElementById('btnNovoClienteFinanceiro'),
        statusSelect: document.getElementById('financeiroStatus'),
        paymentMethodSelect: document.getElementById('financeiroFormaPagamento'),
        accountWrapper: document.getElementById('financeiroContaWrapper'),
        accountSelect: document.getElementById('financeiroConta'),
        dataPagamentoWrapper: document.getElementById('financeiroDataPagamentoWrapper'),
        osSelect: document.getElementById('financeiroOsId'),
        osHelp: document.getElementById('financeiroOsHelp'),
        avulsoInput: document.getElementById('financeiroAvulso'),
        tipoSelect: document.getElementById('financeiroTipo'),
        fornecedorWrapper: document.getElementById('financeiroFornecedorWrapper'),
        fornecedorSelect: document.getElementById('financeiroFornecedorId'),
        // Filtro da lista de categorias. NÃO é a classificação do lançamento —
        // não viaja no payload (o nome está fora do whitelist do controller).
        categoriaFiltroWrapper: document.getElementById('financeiroCategoriaFiltroWrapper'),
        categoriaFiltro: document.getElementById('financeiroCategoriaFiltro'),
        // Resumo da classificação resolvida + override (este sim é dre_fixo_mensal).
        classificacaoResumo: document.getElementById('financeiroClassificacaoResumo'),
        classificacaoOverrideWrapper: document.getElementById('financeiroClassificacaoOverrideWrapper'),
        classificacaoOverride: document.getElementById('financeiroClassificacaoOverride'),
        repetirWrapper: document.getElementById('financeiroRepetirWrapper'),
        osWrapper: document.getElementById('financeiroOsWrapper'),
        clienteWrapper: document.getElementById('financeiroClienteWrapper'),
        vinculosSection: document.getElementById('financeiroVinculosSection'),
        avulsoWrapper: document.getElementById('financeiroAvulsoWrapper'),
        formaPagamentoWrapper: document.getElementById('financeiroFormaPagamentoWrapper'),
        form: document.getElementById('financeiroForm'),
    };

    const escapeHtml = (unsafe) => String(unsafe ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const normalizeText = (value) => String(value ?? '').trim();

    /**
     * DONA ÚNICA de #financeiroRepetirWrapper.
     *
     * Antes eram duas: syncRepetirVisibility() (dentro de initVinculos) e
     * syncParcelasHint() (dentro de initCartaoCredito). Elas alternavam o mesmo
     * `d-none` sem se falar, então escolher 12x escondia o checkbox e mexer na
     * classificação depois o trazia de volta — parcelamento e repetição são
     * mutuamente exclusivos por decisão de negócio, e a tela deixava os dois
     * ligados.
     *
     * Vive no escopo do módulo justamente para que nenhum init "seja dono" dela.
     * Lê o estado do DOM em vez de receber parâmetros: assim não há estado
     * compartilhado entre os dois inits que poderia divergir de novo.
     */
    const parcelasAtuais = () => {
        const select = document.querySelector('[data-cartao-credito-parcelas]');
        return parseInt(select?.value ?? '1', 10) || 1;
    };

    /**
     * Classificação de DRE que o backend VAI aplicar — espelho exato da
     * precedência de FinanceiroService::resolveClassification():
     *
     *     payload > valor já gravado > padrão da categoria > false
     *
     * Existe porque a tela precisa mostrar a classificação REAL, não a que o
     * operador escolheu num select. O bug que motivou isto: deixar o filtro em
     * "todas" e escolher Aluguel produzia um lançamento fixo com o checkbox de
     * repetição invisível — o caso de uso mais óbvio, inalcançável.
     *
     * `origem` alimenta a linha de resumo na tela; sem ela o operador não tem
     * como saber POR QUE está fixa.
     */
    const classificacaoEfetiva = () => {
        const ehPagar = !els.tipoSelect || els.tipoSelect.value === 'pagar';
        if (!ehPagar) { return { aplica: false, fixa: false, origem: 'na', categoria: '' }; }

        const categoriaNome = normalizeText(els.categoriaSelect?.value);
        const base = { aplica: true, categoria: categoriaNome };

        // 1. Override explícito do operador.
        const override = normalizeText(els.classificacaoOverride?.value);
        if (override !== '') {
            return { ...base, fixa: override === '1', origem: 'override' };
        }

        // 2. Em EDIÇÃO o backend prioriza o valor gravado sobre o padrão da
        //    categoria — trocar a categoria aqui não muda a classificação. Sem
        //    este passo a tela prometeria uma mudança que o servidor ignora.
        const salva = normalizeText(els.form?.dataset?.classificacaoSalva);
        if (salva !== '') {
            return { ...base, fixa: salva === '1', origem: 'lancamento' };
        }

        if (categoriaNome === '') {
            return { ...base, fixa: false, origem: 'sem-categoria' };
        }

        // 3. Padrão da categoria, pelo atributo que o servidor já renderizou.
        const option = els.categoriaSelect?.selectedOptions?.[0];
        if (option?.dataset?.fixo !== undefined) {
            return { ...base, fixa: option.dataset.fixo === '1', origem: 'categoria' };
        }

        // 4. Mesma coisa pelo catálogo — cobre a <option> fora-do-catálogo que o
        //    Blade renderiza sem data-fixo quando old() traz categoria estranha.
        const catalogo = Array.isArray(config.categorias) ? config.categorias : [];
        const categoria = catalogo.find((c) => normalizeText(c?.nome) === categoriaNome);
        if (categoria) {
            return { ...base, fixa: Boolean(categoria.dre_fixo_mensal_padrao), origem: 'categoria' };
        }

        // 5. Categoria criada na hora (Select2 tags): o backend faz `?? false`.
        return { ...base, fixa: false, origem: 'nova' };
    };

    const syncRepetir = () => {
        if (!(els.repetirWrapper instanceof HTMLElement)) { return; }

        const ehPagar = !els.tipoSelect || els.tipoSelect.value === 'pagar';
        const visivel = ehPagar && classificacaoEfetiva().fixa && parcelasAtuais() <= 1;

        els.repetirWrapper.classList.toggle('d-none', !visivel);

        // Zera, não preserva: é flag de ação. Deixada marcada fora de contexto,
        // ela cria 11 títulos futuros que ninguém pediu.
        if (!visivel) {
            const checkbox = els.repetirWrapper.querySelector('input[type="checkbox"]');
            if (checkbox instanceof HTMLInputElement) { checkbox.checked = false; }
        }
    };

    const initFinancialAccount = () => {
        if (!(els.accountSelect instanceof HTMLSelectElement) || !(els.statusSelect instanceof HTMLSelectElement)) { return; }

        const defaults = config.contasFinanceiras?.contas_padrao || {};
        const syncVisibility = () => {
            const required = els.statusSelect.value === 'pago';
            els.accountWrapper?.classList.toggle('d-none', !required);
            els.accountSelect.required = required;
        };
        const syncDefault = () => {
            if (!(els.paymentMethodSelect instanceof HTMLSelectElement) || els.statusSelect.value !== 'pago') { return; }
            const defaultId = defaults[els.paymentMethodSelect.value];
            if (!defaultId || !Array.from(els.accountSelect.options).some((option) => Number(option.value) === Number(defaultId))) { return; }
            els.accountSelect.value = String(defaultId);
            if (window.jQuery) { window.jQuery(els.accountSelect).trigger('change'); }
        };

        els.statusSelect.addEventListener('change', () => { syncVisibility(); syncDefault(); });
        els.paymentMethodSelect?.addEventListener('change', syncDefault);
        if (window.jQuery) {
            window.jQuery(els.statusSelect).on('change', () => { syncVisibility(); syncDefault(); });
            window.jQuery(els.paymentMethodSelect).on('change', syncDefault);
        }
        syncVisibility();
        syncDefault();
    };

    // Cartão de crédito da assistência: aparece só quando a forma de
    // pagamento é "cartão de crédito". Escolhido um cartão, a data de
    // vencimento deixa de ser digitada e passa a refletir a fatura em que a
    // compra cai — a data mostrada aqui vem sempre do backend (mesmo cálculo
    // do save), nunca de uma conta feita no navegador, para a prévia não
    // divergir do que será gravado.
    const initCartaoCredito = () => {
        const wrapper = document.querySelector('[data-cartao-credito-wrapper]');
        const select = document.querySelector('[data-cartao-credito-select]');
        const compraWrapper = document.querySelector('[data-cartao-credito-compra-wrapper]');
        const dataCompra = document.querySelector('[data-cartao-credito-data-compra]');
        const preview = document.querySelector('[data-cartao-credito-preview]');
        const contaHint = document.querySelector('[data-cartao-credito-conta-hint]');
        const parcelasWrapper = document.querySelector('[data-cartao-credito-parcelas-wrapper]');
        const parcelasSelect = document.querySelector('[data-cartao-credito-parcelas]');
        const parcelasHint = document.querySelector('[data-cartao-credito-parcelas-hint]');
        const vencimentoInput = document.getElementById('financeiroDataVencimento');
        const vencimentoHint = document.querySelector('[data-cartao-credito-vencimento-hint]');
        const CREDITO = 'cartao_credito';
        const DEBITO = 'cartao_debito';

        if (!wrapper || !(select instanceof HTMLSelectElement)) { return; }

        const previewTemplate = wrapper.getAttribute('data-preview-url-template') || '';
        let previewTimer = null;

        // Depende de DUAS escolhas que mudam sem recarregar a tela: Tipo
        // (em "Novo lançamento" a tela nasce como "a receber") e Forma de
        // pagamento. Por isso a visibilidade é recalculada aqui, e não fixada
        // no Blade.
        const syncPaymentVisibility = () => {
            const isPagar = !(els.tipoSelect instanceof HTMLSelectElement)
                || els.tipoSelect.value === 'pagar';
            const isCard = els.paymentMethodSelect instanceof HTMLSelectElement
                && [CREDITO, DEBITO].includes(els.paymentMethodSelect.value);
            const visible = isPagar && isCard;

            wrapper.classList.toggle('d-none', !visible);
            syncParcelasVisibility();
            syncStatusLock();

            // Alternar crédito <-> débito muda o vencimento (fatura x dia da
            // compra), então recalcula quando já houver cartão escolhido.
            if (visible && select.value !== '') {
                refreshPreview();
            }

            // Sair de "a pagar" ou trocar para uma forma que não é cartão
            // desfaz o vínculo — senão o título continuaria preso a uma fatura
            // sem ter sido comprado no cartão.
            if (!visible && select.value !== '') {
                select.value = '';
                syncCardSelection();
            }
        };

        const setVencimentoLocked = (locked) => {
            if (!(vencimentoInput instanceof HTMLInputElement)) { return; }
            vencimentoInput.readOnly = locked;
            vencimentoInput.classList.toggle('bg-body-secondary', locked);
            vencimentoHint?.classList.toggle('d-none', !locked);
        };

        const isCredito = () => els.paymentMethodSelect instanceof HTMLSelectElement
            && els.paymentMethodSelect.value === CREDITO;

        // Compra no crédito é liquidada pela fatura (baixa em lote), nunca pelo
        // status do próprio título: deixar escolher "Pago" aqui geraria a baixa
        // automática (ver FinanceiroService::finalizeAfterSave()) e a despesa
        // sairia do saldo em aberto da fatura sem ninguém ter pago a fatura. O
        // backend normaliza de qualquer jeito; travar aqui é para o usuário não
        // escolher algo que seria silenciosamente desfeito.

        const syncStatusLock = () => {
            const statusSelect = els.statusSelect;
            if (!(statusSelect instanceof HTMLSelectElement)) { return; }

            // Título que já tem baixa real reflete o que os movimentos dizem —
            // travar em "Pendente" mostraria pendente para algo já pago.
            const hasMovements = statusSelect.getAttribute('data-has-movements') === '1';
            const isPagar = !(els.tipoSelect instanceof HTMLSelectElement)
                || els.tipoSelect.value === 'pagar';
            const locked = isPagar && isCredito() && !hasMovements;

            Array.from(statusSelect.options).forEach((option) => {
                if (option.value === 'pendente') { return; }
                // Preserva quem já estava desabilitado pelas regras do Blade
                // (parcial sem baixa, cancelado com baixa).
                if (locked) {
                    option.dataset.lockedByCartao = '1';
                    option.disabled = true;
                } else if (option.dataset.lockedByCartao === '1') {
                    delete option.dataset.lockedByCartao;
                    option.disabled = false;
                }
            });

            // Comunica o travamento por DOM em vez de mexer nos <small>
            // diretamente: quem é dono dos dois hints é initStatusHints(), que
            // roda SEMPRE. Esta função vive dentro de initCartaoCredito, que
            // retorna cedo quando não há cartão cadastrado — numa instalação
            // sem cartões os hints nunca eram sincronizados.
            statusSelect.dataset.cartaoLocked = locked ? '1' : '0';
            statusSelect.dispatchEvent(new CustomEvent('financeiro:status-lock', { bubbles: true }));

            if (locked && statusSelect.value !== 'pendente') {
                statusSelect.value = 'pendente';
                // Os wrappers de data de pagamento/conta escutam 'change' — sem
                // disparar, continuariam visíveis com o status já em pendente.
                statusSelect.dispatchEvent(new Event('change', { bubbles: true }));
                if (window.jQuery) { window.jQuery(statusSelect).trigger('change'); }
            }
        };

        const refreshPreview = () => {
            if (!preview) { return; }

            const cartaoId = select.value;
            const compra = dataCompra instanceof HTMLInputElement ? dataCompra.value : '';

            if (cartaoId === '' || compra === '') { return; }

            // Débito não tem fatura: o dinheiro sai da conta no dia da compra,
            // então o vencimento é a própria data — sem ida ao servidor.
            if (!isCredito()) {
                if (vencimentoInput instanceof HTMLInputElement) {
                    vencimentoInput.value = compra;
                }
                const [dY, dM, dD] = compra.split('-');
                preview.textContent = `Sai da conta em ${dD}/${dM}/${dY} — compras no débito não entram em fatura.`;
                return;
            }

            if (previewTemplate === '') { return; }

            preview.textContent = 'Calculando a fatura...';

            fetch(`${previewTemplate.replace('__CARTAO__', encodeURIComponent(cartaoId))}?data_compra=${encodeURIComponent(compra)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : Promise.reject(new Error('preview'))))
                .then((payload) => {
                    const vencimento = payload?.fatura?.data_vencimento || '';
                    if (vencimento === '') { throw new Error('preview'); }

                    if (vencimentoInput instanceof HTMLInputElement) {
                        vencimentoInput.value = vencimento;
                    }

                    // Compra não pode cair em fatura já paga (o save recusa —
                    // ver FinanceiroService::resolveClassification()). O "min"
                    // impede escolher a data; o aviso explica quando a data
                    // atual já é inválida (ex.: cartão trocado depois de a data
                    // já estar preenchida).
                    aplicarCompraMinima(payload?.fatura?.compra_minima || '');

                    const [ano, mes, dia] = vencimento.split('-');

                    if (payload?.fatura?.fatura_paga) {
                        preview.textContent = `A fatura que vence em ${dia}/${mes}/${ano} já foi paga — escolha uma data que caia numa fatura ainda aberta.`;
                        preview.classList.add('text-danger');
                        return;
                    }

                    preview.classList.remove('text-danger');
                    preview.textContent = `Entra na fatura que vence em ${dia}/${mes}/${ano}.`;
                })
                .catch(() => {
                    preview.textContent = 'Não foi possível calcular a fatura agora. O vencimento será definido ao salvar.';
                });
        };

        // Trava o calendário na primeira data que ainda cai numa fatura aberta.
        // Vazio = nenhuma fatura paga ainda, então não há restrição.
        const aplicarCompraMinima = (compraMinima) => {
            if (!(dataCompra instanceof HTMLInputElement)) { return; }

            if (compraMinima === '') {
                dataCompra.removeAttribute('min');
                return;
            }

            dataCompra.min = compraMinima;
        };

        const scheduleRefresh = () => {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(refreshPreview, 300);
        };

        // Mostra de qual conta (Contas e Saldos) o cartão debita e já
        // pré-seleciona essa conta na baixa — no débito o dinheiro sai dali na
        // hora; no crédito ela serve de sugestão ao pagar a fatura.
        const syncContaVinculada = () => {
            const option = select.selectedOptions[0];
            const contaId = option?.getAttribute('data-conta-id') || '';
            const contaNome = option?.getAttribute('data-conta-nome') || '';

            if (contaHint) {
                contaHint.textContent = select.value === ''
                    ? ''
                    : (contaNome !== ''
                        ? `Vinculado à conta ${contaNome}.`
                        : 'Este cartão ainda não tem conta financeira vinculada (edite o cartão em Contas e Saldos).');
            }

            if (contaId === '' || contaId === '0' || !(els.accountSelect instanceof HTMLSelectElement)) { return; }

            const existe = Array.from(els.accountSelect.options)
                .some((opt) => Number(opt.value) === Number(contaId));

            if (existe) {
                els.accountSelect.value = String(contaId);
                if (window.jQuery) { window.jQuery(els.accountSelect).trigger('change'); }
            }
        };

        // Parcelar só existe no crédito (débito é à vista) e some quando não
        // há cartão escolhido. Parcelamento e "repetir nos próximos meses" são
        // exclusivos: um divide um total que acaba, o outro repete um valor sem
        // fim — deixar os dois ativos geraria 12 parcelas vezes 12 repetições.
        function syncParcelasVisibility() {
            if (!parcelasWrapper) { return; }

            const isCredito = els.paymentMethodSelect instanceof HTMLSelectElement
                && els.paymentMethodSelect.value === CREDITO;
            const isPagar = !(els.tipoSelect instanceof HTMLSelectElement)
                || els.tipoSelect.value === 'pagar';
            const mostrar = isPagar && isCredito && select.value !== '';

            parcelasWrapper.classList.toggle('d-none', !mostrar);

            if (!mostrar && parcelasSelect instanceof HTMLSelectElement) {
                parcelasSelect.value = '1';
            }

            syncParcelasHint();
        }

        const parseValor = () => {
            const hidden = els.valorHidden;
            const bruto = hidden instanceof HTMLInputElement ? hidden.value : '';
            const numero = parseFloat(String(bruto).replace(',', '.'));

            return Number.isFinite(numero) ? numero : 0;
        };

        function syncParcelasHint() {
            if (!parcelasHint || !(parcelasSelect instanceof HTMLSelectElement)) { return; }

            const parcelas = parseInt(parcelasSelect.value, 10) || 1;

            // Parcelamento e repetição são mutuamente exclusivos, mas quem
            // decide a visibilidade do "repetir" é syncRepetir() — dona única.
            // Alternar o d-none aqui também é o que fazia os dois se atropelarem.
            syncRepetir();

            if (parcelas <= 1) {
                parcelasHint.textContent = 'O valor informado acima é o total da compra.';
                return;
            }

            const total = parseValor();

            if (total <= 0) {
                parcelasHint.textContent = `Serão criadas ${parcelas} despesas, uma por fatura. Informe o valor total da compra.`;
                return;
            }

            const centavos = Math.round(total * 100);
            const base = Math.floor(centavos / parcelas);
            const primeira = (base + (centavos - base * parcelas)) / 100;
            const demais = base / 100;
            const money = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

            parcelasHint.textContent = primeira === demais
                ? `${parcelas}x de ${money(demais)} — uma em cada fatura.`
                : `${parcelas}x: 1ª de ${money(primeira)} e as demais de ${money(demais)} — uma em cada fatura.`;
        }

        function syncCardSelection() {
            const hasCard = select.value !== '';
            compraWrapper?.classList.toggle('d-none', !hasCard);
            setVencimentoLocked(hasCard);
            syncContaVinculada();
            syncParcelasVisibility();

            if (dataCompra instanceof HTMLInputElement) {
                dataCompra.required = hasCard;
                if (hasCard && dataCompra.value === '') {
                    dataCompra.value = new Date().toISOString().slice(0, 10);
                }
            }

            if (hasCard) {
                refreshPreview();
            } else if (preview) {
                preview.textContent = 'Escolha o cartão e a data para ver em qual fatura a compra cai.';
            }
        }

        select.addEventListener('change', syncCardSelection);
        dataCompra?.addEventListener('change', scheduleRefresh);
        parcelasSelect?.addEventListener('change', syncParcelasHint);
        els.valorDisplay?.addEventListener('input', syncParcelasHint);
        if (window.jQuery) { window.jQuery(parcelasSelect).on('change', syncParcelasHint); }
        els.paymentMethodSelect?.addEventListener('change', syncPaymentVisibility);
        els.tipoSelect?.addEventListener('change', syncPaymentVisibility);

        // Os selects viram Select2, que não dispara o evento nativo 'change'
        // de forma confiável — daí o par de listeners.
        if (window.jQuery) {
            window.jQuery(select).on('change', syncCardSelection);
            window.jQuery(els.paymentMethodSelect).on('change', syncPaymentVisibility);
            window.jQuery(els.tipoSelect).on('change', syncPaymentVisibility);
        }

        syncPaymentVisibility();
        syncCardSelection();
    };

    // As duas dicas sob "Forma de pagamento" explicam lógicas opostas: ao
    // RECEBER, cartão é a maquininha (operadora/taxa); ao PAGAR, é o cartão da
    // própria assistência (fatura/conta). Vive fora de initCartaoCredito de
    // propósito — a explicação precisa aparecer mesmo sem nenhum cartão
    // cadastrado, que é justamente quando o usuário mais se confunde.
    const initFormaPagamento = () => {
        const hintReceber = document.querySelector('[data-forma-pagamento-hint-receber]');
        const hintPagar = document.querySelector('[data-forma-pagamento-hint-pagar]');

        const sync = () => {
            const isPagar = !(els.tipoSelect instanceof HTMLSelectElement)
                || els.tipoSelect.value === 'pagar';
            const ehPago = els.statusSelect instanceof HTMLSelectElement
                && els.statusSelect.value === 'pago';

            hintReceber?.classList.toggle('d-none', isPagar);
            hintPagar?.classList.toggle('d-none', !isPagar);

            // Em "a pagar" o campo abre o bloco do cartão; em "pago" ele escolhe
            // a conta padrão. Fora disso é intenção que o backend descarta.
            const visivel = isPagar || ehPago;
            els.formaPagamentoWrapper?.classList.toggle('d-none', !visivel);

            // Preserva o valor e tira do POST — voltar para "a pagar" reabre o
            // campo com a escolha anterior intacta.
            if (els.paymentMethodSelect instanceof HTMLSelectElement) {
                els.paymentMethodSelect.disabled = !visivel;
            }
        };

        els.tipoSelect?.addEventListener('change', sync);
        els.statusSelect?.addEventListener('change', sync);
        if (window.jQuery) {
            window.jQuery(els.tipoSelect).on('change', sync);
            window.jQuery(els.statusSelect).on('change', sync);
        }
        sync();
    };

    /**
     * DONO ÚNICO dos dois <small> sob o Status.
     *
     * Vive fora de initCartaoCredito porque aquele faz `return` cedo quando não
     * há cartão cadastrado — e numa instalação sem cartões os hints nunca eram
     * sincronizados, deixando "Selecionar Pago gera a baixa automaticamente"
     * na tela de um lançamento pendente, onde não se aplica.
     */
    const initStatusHints = () => {
        const hintPadrao = document.querySelector('[data-status-hint-padrao]');
        const hintCartao = document.querySelector('[data-status-cartao-credito-hint]');

        if (!(els.statusSelect instanceof HTMLSelectElement) || (!hintPadrao && !hintCartao)) { return; }

        const sync = () => {
            const travadoPeloCartao = els.statusSelect.dataset.cartaoLocked === '1';
            const temBaixa = els.statusSelect.getAttribute('data-has-movements') === '1';
            const ehPago = els.statusSelect.value === 'pago';

            // O aviso de baixa automática só vale quando o operador acabou de
            // escolher "Pago" num título que ainda não tem baixa.
            hintPadrao?.classList.toggle('d-none', !(ehPago && !travadoPeloCartao && !temBaixa));
            hintCartao?.classList.toggle('d-none', !travadoPeloCartao);
        };

        els.statusSelect.addEventListener('change', sync);
        els.statusSelect.addEventListener('financeiro:status-lock', sync);
        if (window.jQuery) { window.jQuery(els.statusSelect).on('change', sync); }
        sync();
    };

    // Data do pagamento só faz sentido quando status = pago (fica em branco
    // e o backend assume hoje). É independente de initFinancialAccount, que
    // só roda quando existem contas financeiras cadastradas.
    const initDataPagamento = () => {
        if (!(els.statusSelect instanceof HTMLSelectElement) || !els.dataPagamentoWrapper) { return; }

        const syncVisibility = () => {
            els.dataPagamentoWrapper.classList.toggle('d-none', els.statusSelect.value !== 'pago');
        };

        els.statusSelect.addEventListener('change', syncVisibility);
        if (window.jQuery) { window.jQuery(els.statusSelect).on('change', syncVisibility); }
        syncVisibility();
    };

    const select2Language = {
        errorLoading: () => 'Os resultados nao puderam ser carregados.',
        inputTooShort: (args) => `Digite mais ${args.minimum - args.input.length} caractere(s) para buscar`,
        noResults: () => 'Nenhum cliente encontrado.',
        searching: () => 'Buscando...',
        loadingMore: () => 'Carregando mais resultados...',
    };

    // --- Currency mask (R$ format) ---

    const rawToDisplay = (raw) => {
        const num = parseFloat(String(raw).replace(',', '.'));
        if (Number.isNaN(num) || num < 0) { return ''; }
        return new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(num);
    };

    const displayToRaw = (display) => {
        // Remove tudo exceto dígitos e vírgula
        const stripped = String(display).replace(/[^\d,]/g, '');
        if (stripped === '') { return ''; }
        const normalized = stripped.replace(',', '.');
        const num = parseFloat(normalized);
        return Number.isNaN(num) ? '' : num.toFixed(2);
    };

    const applyMaskFromDigits = (display, hidden) => {
        const digits = String(display.value).replace(/\D/g, '');
        if (digits === '' || digits === '0') {
            display.value = '';
            if (hidden) { hidden.value = ''; }
            return;
        }
        const amount = parseInt(digits, 10) / 100;
        display.value = new Intl.NumberFormat('pt-BR', {
            style: 'currency', currency: 'BRL',
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        }).format(amount);
        if (hidden) { hidden.value = amount.toFixed(2); }
    };

    // Exportados para financeiro-entrada-estoque.js (specs/039), que mascara o
    // custo de cada linha de peça com exatamente a mesma regra. Duplicar as três
    // funções lá criaria dois formatos de dinheiro no mesmo formulário — e o
    // segundo divergiria do primeiro na primeira correção que só um deles
    // recebesse.
    window.desktopFinanceiroMask = { rawToDisplay, displayToRaw, applyMaskFromDigits };

    const initValorMask = () => {
        const display = els.valorDisplay;
        const hidden = els.valorHidden;
        if (!(display instanceof HTMLInputElement)) { return; }

        // Pre-populate display from hidden value (edit mode / old())
        if (hidden instanceof HTMLInputElement && hidden.value !== '') {
            display.value = rawToDisplay(hidden.value);
        }

        display.addEventListener('input', () => applyMaskFromDigits(display, hidden));

        display.addEventListener('blur', () => {
            const raw = displayToRaw(display.value);
            if (raw !== '') {
                display.value = rawToDisplay(raw);
                if (hidden instanceof HTMLInputElement) { hidden.value = raw; }
            } else {
                display.value = '';
                if (hidden instanceof HTMLInputElement) { hidden.value = ''; }
            }
        });

        display.addEventListener('paste', (e) => {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData)?.getData('text') || '';
            const raw = displayToRaw(text);
            if (raw !== '') {
                display.value = rawToDisplay(raw);
                if (hidden instanceof HTMLInputElement) { hidden.value = raw; }
            }
        });
    };

    // --- Categoria Select2 (tags) ---

    const initCategoriaSelect = () => {
        const select = els.categoriaSelect;
        if (!(select instanceof HTMLSelectElement)) { return; }
        if (typeof window.jQuery === 'undefined' || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') { return; }

        const $ = window.jQuery;
        if ($(select).data('select2')) { return; }

        $(select).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: select.dataset.select2Placeholder || 'Ex.: Serviço, Aluguel, Energia...',
            allowClear: true,
            tags: true,
            createTag: (params) => {
                const term = normalizeText(params.term);
                if (term === '' || term.length > 50) { return null; }
                return { id: term, text: term, newTag: true };
            },
            language: {
                noResults: () => 'Nenhuma categoria. Pressione Enter para criar.',
                searching: () => 'Buscando...',
            },
            // O Select2 RENDERIZA opções `disabled` — cinzentas, mas visíveis.
            // Sem este matcher, um filtro que promete "só as fixas" mostra as
            // variáveis assim mesmo, o que não é filtro nenhum.
            matcher: (params, data) => {
                const termo = normalizeText(params.term).toLowerCase();
                const texto = normalizeText(data.text);

                if (termo !== '' && !texto.toLowerCase().includes(termo)) { return null; }
                if (texto === '') { return data; }

                const filtro = els.categoriaFiltro instanceof HTMLSelectElement ? els.categoriaFiltro.value : '';
                if (filtro === '') { return data; }

                // A já escolhida passa sempre (o filtro não pode invalidar a
                // escolha do operador); a criada na hora também, porque a pessoa
                // está justamente digitando um nome que ainda não existe.
                const fixo = data.element?.dataset?.fixo;
                if (fixo === undefined || data.element?.selected) { return data; }

                return fixo === filtro ? data : null;
            },
        });
    };

    const getModal = (element) => {
        if (!(element instanceof HTMLElement) || typeof window.bootstrap === 'undefined') {
            return null;
        }

        return window.bootstrap.Modal.getOrCreateInstance(element);
    };

    const showToast = (icon, title) => {
        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({
            toast: true,
            position: 'top-end',
            timer: 3200,
            timerProgressBar: true,
            showConfirmButton: false,
            icon,
            title,
        });
    };

    const showAlert = (icon, title, text = '') => {
        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({ icon, title, text });
    };

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

    const renderClientTemplate = (client) => {
        if (!client || client.loading) {
            return escapeHtml(client?.text || client?.name || '');
        }

        const title = escapeHtml(normalizeText(client.name || client.text || `Cliente #${client.id}`));
        const meta = [
            normalizeText(client.phone || ''),
            normalizeText(client.email || ''),
            client.city || client.uf ? [client.city, client.uf].filter(Boolean).join(' / ') : '',
        ].filter(Boolean);

        return `
            <div class="d-flex flex-column py-1">
                <strong>${title}</strong>
                ${meta.length > 0 ? `<small class="text-secondary">${escapeHtml(meta.join(' / '))}</small>` : ''}
            </div>
        `;
    };

    const setClientSelectValue = (clientId, clientName) => {
        if (!(els.clientSelect instanceof HTMLSelectElement)) {
            return;
        }

        const value = String(clientId || '');
        if (value === '') {
            return;
        }

        let option = Array.from(els.clientSelect.options).find((o) => o.value === value) || null;

        if (!(option instanceof HTMLOptionElement)) {
            option = document.createElement('option');
            option.value = value;
            option.textContent = normalizeText(clientName || `Cliente #${value}`);
            els.clientSelect.appendChild(option);
        }

        if (
            typeof window.jQuery !== 'undefined'
            && window.jQuery.fn
            && typeof window.jQuery.fn.select2 === 'function'
            && Boolean(window.jQuery(els.clientSelect).data('select2'))
        ) {
            window.jQuery(els.clientSelect).val(value).trigger('change.select2');
        } else {
            els.clientSelect.value = value;
        }
    };

    const initClientSelect = () => {
        if (!(els.clientSelect instanceof HTMLSelectElement) || clientSearchUrl === '') {
            return;
        }

        if (
            typeof window.jQuery === 'undefined'
            || !window.jQuery.fn
            || typeof window.jQuery.fn.select2 !== 'function'
        ) {
            return;
        }

        const $ = window.jQuery;

        if ($(els.clientSelect).data('select2')) {
            return;
        }

        const placeholder = els.clientSelect.dataset.select2Placeholder || 'Buscar cliente pelo nome...';

        $(els.clientSelect).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder,
            allowClear: true,
            minimumInputLength: 1,
            language: select2Language,
            escapeMarkup: (markup) => markup,
            templateResult: renderClientTemplate,
            templateSelection: (client) => {
                if (!client || client.loading) {
                    return escapeHtml(client?.text || placeholder);
                }

                return escapeHtml(normalizeText(client.name || client.text || placeholder));
            },
            ajax: {
                url: clientSearchUrl,
                dataType: 'json',
                delay: 250,
                cache: true,
                data: (params) => ({
                    q: params.term || '',
                    page: params.page || 1,
                    per_page: 10,
                }),
                processResults: (data, params) => {
                    const page = params.page || 1;
                    const clients = Array.isArray(data?.clients) ? data.clients : [];

                    return {
                        results: clients.map((item) => ({
                            id: String(item?.id || ''),
                            text: normalizeText(item?.text || item?.name || ''),
                            name: normalizeText(item?.name || item?.text || ''),
                            phone: normalizeText(item?.phone || ''),
                            email: normalizeText(item?.email || ''),
                            city: normalizeText(item?.city || ''),
                            uf: normalizeText(item?.uf || ''),
                        })),
                        pagination: {
                            more:
                                Number(data?.pagination?.current_page || page)
                                < Number(data?.pagination?.last_page || page),
                        },
                    };
                },
            },
        });
    };

    // --- OS (ordem de serviço) Select2 ---

    const renderOrderTemplate = (order) => {
        if (!order || order.loading) {
            return escapeHtml(order?.text || '');
        }

        const title = escapeHtml(normalizeText(
            order.text || (order.numero_os ? `OS ${order.numero_os}` : `OS #${order.id}`)
        ));
        const meta = [
            normalizeText(order.cliente_nome || ''),
            normalizeText(order.equipamento || ''),
            normalizeText(order.status_nome || ''),
        ].filter(Boolean);

        return `
            <div class="d-flex flex-column py-1">
                <strong>${title}</strong>
                ${meta.length > 0 ? `<small class="text-secondary">${escapeHtml(meta.join(' / '))}</small>` : ''}
            </div>
        `;
    };

    const initOrderSelect = () => {
        if (!(els.osSelect instanceof HTMLSelectElement) || orderSearchUrl === '') {
            return;
        }

        if (
            typeof window.jQuery === 'undefined'
            || !window.jQuery.fn
            || typeof window.jQuery.fn.select2 !== 'function'
        ) {
            return;
        }

        const $ = window.jQuery;

        if ($(els.osSelect).data('select2')) {
            return;
        }

        const placeholder = els.osSelect.dataset.select2Placeholder || 'Buscar OS pelo número...';

        $(els.osSelect).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder,
            allowClear: true,
            minimumInputLength: 0,
            language: {
                ...select2Language,
                noResults: () => 'Nenhuma OS em aberto encontrada.',
            },
            escapeMarkup: (markup) => markup,
            templateResult: renderOrderTemplate,
            templateSelection: (order) => {
                if (!order || order.loading) {
                    return escapeHtml(order?.text || placeholder);
                }

                return escapeHtml(normalizeText(order.text || placeholder));
            },
            ajax: {
                url: orderSearchUrl,
                dataType: 'json',
                delay: 250,
                cache: true,
                data: (params) => ({
                    q: params.term || '',
                    client_id: els.clientSelect instanceof HTMLSelectElement ? (els.clientSelect.value || '') : '',
                    page: params.page || 1,
                    per_page: 10,
                }),
                processResults: (data, params) => {
                    const page = params.page || 1;
                    const orders = Array.isArray(data?.orders) ? data.orders : [];

                    return {
                        results: orders.map((item) => ({
                            id: String(item?.id || ''),
                            text: normalizeText(item?.text || ''),
                            numero_os: normalizeText(item?.numero_os || ''),
                            cliente_id: item?.cliente_id ? String(item.cliente_id) : '',
                            cliente_nome: normalizeText(item?.cliente_nome || ''),
                            equipamento: normalizeText(item?.equipamento || ''),
                            status_nome: normalizeText(item?.status_nome || ''),
                        })),
                        pagination: {
                            more:
                                Number(data?.pagination?.current_page || page)
                                < Number(data?.pagination?.last_page || page),
                        },
                    };
                },
            },
        });
    };

    // --- Fornecedor Select2 ---

    const renderSupplierTemplate = (supplier) => {
        if (!supplier || supplier.loading) {
            return escapeHtml(supplier?.text || '');
        }

        const title = escapeHtml(normalizeText(supplier.name || supplier.text || `Fornecedor #${supplier.id}`));
        const meta = [normalizeText(supplier.phone || '')].filter(Boolean);

        return `
            <div class="d-flex flex-column py-1">
                <strong>${title}</strong>
                ${meta.length > 0 ? `<small class="text-secondary">${escapeHtml(meta.join(' / '))}</small>` : ''}
            </div>
        `;
    };

    const initSupplierSelect = () => {
        if (!(els.fornecedorSelect instanceof HTMLSelectElement) || supplierSearchUrl === '') {
            return;
        }

        if (
            typeof window.jQuery === 'undefined'
            || !window.jQuery.fn
            || typeof window.jQuery.fn.select2 !== 'function'
        ) {
            return;
        }

        const $ = window.jQuery;

        if ($(els.fornecedorSelect).data('select2')) {
            return;
        }

        const placeholder = els.fornecedorSelect.dataset.select2Placeholder || 'Buscar fornecedor pelo nome...';

        $(els.fornecedorSelect).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder,
            allowClear: true,
            minimumInputLength: 1,
            language: {
                ...select2Language,
                noResults: () => 'Nenhum fornecedor encontrado.',
            },
            escapeMarkup: (markup) => markup,
            templateResult: renderSupplierTemplate,
            templateSelection: (supplier) => {
                if (!supplier || supplier.loading) {
                    return escapeHtml(supplier?.text || placeholder);
                }

                return escapeHtml(normalizeText(supplier.name || supplier.text || placeholder));
            },
            ajax: {
                url: supplierSearchUrl,
                dataType: 'json',
                delay: 250,
                cache: true,
                data: (params) => ({
                    q: params.term || '',
                    page: params.page || 1,
                    per_page: 10,
                }),
                processResults: (data, params) => {
                    const page = params.page || 1;
                    const suppliers = Array.isArray(data?.suppliers) ? data.suppliers : [];

                    return {
                        results: suppliers.map((item) => ({
                            id: String(item?.id || ''),
                            text: normalizeText(item?.text || ''),
                            name: normalizeText(item?.name || item?.text || ''),
                            phone: normalizeText(item?.phone || ''),
                        })),
                        pagination: {
                            more:
                                Number(data?.pagination?.current_page || page)
                                < Number(data?.pagination?.last_page || page),
                        },
                    };
                },
            },
        });
    };

    // --- Avulso / OS / Fornecedor coordination ---
    //
    // Compra de peças quase sempre serve para fechar a OS de um cliente, raramente
    // é para estoque — por isso selecionar uma OS preenche o cliente automaticamente
    // e desmarca "avulso". Trocar o cliente limpa a OS selecionada (ela pertencia ao
    // cliente anterior). Como o cliente é setado via `change.select2` (evento
    // namespaced), esse listener plain 'change' só dispara em edições feitas pelo
    // usuário na UI, não quando o próprio código preenche o cliente a partir da OS.

    const initVinculos = () => {
        const $ = window.jQuery;
        const hasSelect2 = typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function';

        const clearSelect2Value = (select) => {
            if (!(select instanceof HTMLSelectElement)) { return; }
            if (hasSelect2 && $(select).data('select2')) {
                $(select).val(null).trigger('change');
            } else {
                select.value = '';
            }
        };

        // Fornecedor, filtro de categoria e classificação só existem em "a pagar".
        //
        // Fornecedor é `required` em TODA conta a pagar, despesa fixa inclusive:
        // aluguel tem locador, internet tem provedora. Antes a despesa fixa
        // escondia a seção inteira e apagava o fornecedor já escolhido,
        // contradizendo o texto do próprio campo.
        const syncClassificacaoVisibility = () => {
            if (!els.tipoSelect) { return; }

            const isPagar = els.tipoSelect.value === 'pagar';
            els.fornecedorWrapper?.classList.toggle('d-none', !isPagar);
            els.categoriaFiltroWrapper?.classList.toggle('d-none', !isPagar);

            if (els.fornecedorSelect instanceof HTMLSelectElement) {
                els.fornecedorSelect.required = isPagar;
                // Desabilita em vez de limpar: some do POST e da validação
                // HTML5 sem destruir o que o operador já tinha escolhido.
                els.fornecedorSelect.disabled = !isPagar;
            }

            // O override some junto e para de viajar; o filtro pode manter o
            // valor, já que só desapareceu da tela.
            if (els.classificacaoOverride instanceof HTMLSelectElement) {
                els.classificacaoOverride.disabled = !isPagar
                    || els.classificacaoOverrideWrapper?.classList.contains('d-none') === true;
                if (!isPagar) { els.classificacaoOverride.value = ''; }
            }
        };

        // Linha "Classificação: Despesa fixa (padrão de Aluguel) · alterar".
        //
        // É a resposta visível à pergunta que o select antigo fazia mal: em vez
        // de perguntar "é fixa?" e aceitar "todas as categorias" como resposta,
        // mostra o que o backend vai realmente aplicar, e de onde veio.
        const syncClassificacaoResumo = () => {
            if (!(els.classificacaoResumo instanceof HTMLElement)) { return; }

            const resultado = classificacaoEfetiva();
            const mostrar = resultado.aplica && resultado.origem !== 'sem-categoria';

            els.classificacaoResumo.classList.toggle('d-none', !mostrar);
            if (!mostrar) { return; }

            const texto = els.classificacaoResumo.querySelector('[data-classificacao-texto]');
            const origem = els.classificacaoResumo.querySelector('[data-classificacao-origem]');

            if (texto) { texto.textContent = resultado.fixa ? 'Despesa fixa' : 'Despesa variável'; }
            if (!origem) { return; }

            origem.textContent = {
                override: ' (definido por você)',
                lancamento: ' (deste lançamento)',
                nova: ' (categoria nova — o sistema assume variável)',
            }[resultado.origem] ?? ` (padrão de ${resultado.categoria})`;
        };

        // OS vinculada e Cliente só fazem sentido, em "a pagar", quando a
        // despesa é compra de peça ligada a uma OS (categoria do grupo DRE
        // "Custo Direto (OS)") — para despesas operacionais genéricas
        // (Energia, Água, Internet, Aluguel...) não há relação com OS/cliente.
        const osClienteOcultos = () => {
            if (!els.tipoSelect) { return false; }

            const isPagar = els.tipoSelect.value === 'pagar';
            const categoriaNome = els.categoriaSelect instanceof HTMLSelectElement ? els.categoriaSelect.value : '';
            const categorias = Array.isArray(config.categorias) ? config.categorias : [];
            const categoria = categorias.find((c) => normalizeText(c?.nome) === normalizeText(categoriaNome));
            const isPecaCategoria = categoria?.dre_grupo?.nome === 'Custo Direto (OS)';

            return isPagar && !isPecaCategoria;
        };

        /**
         * DONA ÚNICA do `disabled` do select de OS.
         *
         * Duas razões independentes desabilitam a OS — o switch "avulso" e a
         * visibilidade — e cada uma escrevendo `.disabled` por conta própria
         * reproduziria o bug dos dois donos que acabamos de matar no Repetir.
         * Aqui elas viram um OU só.
         */
        const syncOsHabilitada = () => {
            if (!(els.osSelect instanceof HTMLSelectElement)) { return; }

            const avulsoMarcado = els.avulsoInput instanceof HTMLInputElement && els.avulsoInput.checked;
            els.osSelect.disabled = avulsoMarcado || osClienteOcultos();
        };

        // OS vinculada e Cliente só fazem sentido, em "a pagar", quando a
        // despesa é compra de peça ligada a uma OS (categoria do grupo DRE
        // "Custo Direto (OS)") — para despesas operacionais genéricas
        // (Energia, Água, Internet, Aluguel...) não há relação com OS/cliente.
        //
        // Esconder DESABILITA, nunca limpa. Esta função é chamada também na
        // troca de Tipo e no `pageshow`: com `clearSelect2Value` aqui, um F5
        // apagava a OS e o cliente que o operador já tinha escolhido.
        const syncOsClienteState = () => {
            if (!els.tipoSelect || (!els.osWrapper && !els.clienteWrapper)) { return; }

            const hide = osClienteOcultos();

            els.osWrapper?.classList.toggle('d-none', hide);
            els.clienteWrapper?.classList.toggle('d-none', hide);

            if (els.clientSelect instanceof HTMLSelectElement) {
                els.clientSelect.disabled = hide;
            }

            syncOsHabilitada();
        };

        // O switch "avulso" só diz algo enquanto OS/Cliente estão na tela:
        // escondidos, ele é um controle sem nenhum efeito observável.
        const syncAvulsoVisibility = () => {
            const ocultos = osClienteOcultos();
            els.avulsoWrapper?.classList.toggle('d-none', ocultos);

            // `!disabled` importa: em edição com baixa quem manda é o hidden
            // espelho, e o backend recusa mudar `avulso` num título com
            // movimentos. Marcar aqui seria mentira cosmética.
            if (ocultos && els.avulsoInput instanceof HTMLInputElement && !els.avulsoInput.disabled) {
                els.avulsoInput.checked = true;
            }
        };

        // O filtro só encurta a lista de Categoria. NÃO decide a classificação
        // do lançamento — quem decide é a categoria escolhida (ou o override).
        //
        // A opção já selecionada nunca é escondida: sem essa regra o filtro
        // invalidaria a escolha do operador e a limparia, que era o
        // comportamento antigo.
        const filterCategoriaOptions = () => {
            if (!(els.categoriaSelect instanceof HTMLSelectElement)) { return; }

            const filterValue = els.categoriaFiltro instanceof HTMLSelectElement ? els.categoriaFiltro.value : '';
            const selecionada = els.categoriaSelect.value;

            Array.from(els.categoriaSelect.options).forEach((option) => {
                if (option.value === '') { return; }
                const matches = filterValue === ''
                    || option.value === selecionada
                    || option.dataset.fixo === undefined
                    || option.dataset.fixo === filterValue;
                option.hidden = !matches;
                option.disabled = !matches;
            });
        };

        // "alterar" abre o override e não fecha mais: re-esconder um controle
        // que a pessoa abriu de propósito é desorientador.
        const abrirOverride = () => {
            els.classificacaoOverrideWrapper?.classList.remove('d-none');
            if (els.classificacaoOverride instanceof HTMLSelectElement) {
                els.classificacaoOverride.disabled = false;
            }
        };

        els.classificacaoResumo
            ?.querySelector('[data-classificacao-alterar]')
            ?.addEventListener('click', abrirOverride);

        // Um ponto só de sincronização, usado na carga, nos handlers e no
        // pageshow. Antes eram três listas paralelas — e já tinham divergido:
        // syncRepetir ficou de fora do handler de Tipo, deixando o checkbox
        // "repetir nos próximos 12 meses" visível e marcado num a receber.
        const sincronizarTudo = () => {
            syncClassificacaoVisibility();
            syncOsClienteState();
            syncAvulsoVisibility();
            filterCategoriaOptions();
            syncClassificacaoResumo();
            syncRepetir();
        };

        sincronizarTudo();

        // Tipo e "Despesa fixa?" são <select class="form-select"> comuns
        // (sem data-native-select="true"), então o auto-init global de
        // desktop.js os transforma em Select2 — e escolher uma opção pela
        // UI do Select2 dispara 'change' só via jQuery, nunca o evento
        // nativo do DOM (ver comentário em desktop.js, initSelect2()). Um
        // addEventListener('change', ...) puro aqui NUNCA dispararia quando
        // o usuário realmente usasse o dropdown — só funcionaria se o valor
        // fosse setado programaticamente. Por isso o bind duplo: nativo
        // (cobre o caso raro de não virar Select2, ex. JS falhar) + jQuery
        // (cobre o Select2, que é o caso real em produção).
        const bindChange = (select, handler) => {
            if (!(select instanceof HTMLSelectElement)) { return; }
            select.addEventListener('change', handler);
            select.addEventListener('input', handler);
            if (hasSelect2) { $(select).on('change', handler); }
        };

        bindChange(els.tipoSelect, sincronizarTudo);
        bindChange(els.categoriaFiltro, filterCategoriaOptions);
        bindChange(els.classificacaoOverride, () => { syncClassificacaoResumo(); syncRepetir(); });

        if (els.categoriaSelect instanceof HTMLSelectElement) {
            const aoTrocarCategoria = () => { syncOsClienteState(); syncAvulsoVisibility(); syncClassificacaoResumo(); syncRepetir(); };
            els.categoriaSelect.addEventListener('change', aoTrocarCategoria);
            if (hasSelect2) {
                $(els.categoriaSelect).on('change select2:select select2:unselect', aoTrocarCategoria);
            }
        }

        // Alguns navegadores restauram o valor de <select> ao recarregar a
        // página (form state restoration) SEM disparar 'change' — 'pageshow'
        // dispara depois de qualquer restauração desse tipo (recarregar,
        // voltar/avançar), então reconferir o estado ali cobre esse caso.
        window.addEventListener('pageshow', sincronizarTudo);

        if (!(els.avulsoInput instanceof HTMLInputElement) || !(els.osSelect instanceof HTMLSelectElement)) {
            return;
        }

        const syncAvulsoState = () => {
            const isAvulso = els.avulsoInput.checked;

            // Marcar avulso LIMPA a OS de propósito — não é visibilidade, é
            // invalidação semântica: o backend recusa `avulso` com `os_id`
            // preenchido. O `disabled` fica com syncOsHabilitada(), dona única.
            if (isAvulso) { clearSelect2Value(els.osSelect); }
            syncOsHabilitada();

            if (els.osHelp instanceof HTMLElement) {
                els.osHelp.textContent = isAvulso
                    ? 'OS desabilitada: lançamentos avulsos são sempre independentes de ordem de serviço.'
                    : 'Busque pelo número da OS (só aparecem OS em aberto). Selecionar uma OS preenche o cliente automaticamente e desmarca o lançamento avulso.';
            }
        };

        els.avulsoInput.addEventListener('change', syncAvulsoState);

        if (hasSelect2) {
            $(els.osSelect).on('select2:select', (event) => {
                const order = event.params?.data;
                if (!order) { return; }

                if (els.avulsoInput.checked) {
                    els.avulsoInput.checked = false;
                    syncAvulsoState();
                }

                if (order.cliente_id) {
                    setClientSelectValue(order.cliente_id, order.cliente_nome);
                }
            });

            if (els.clientSelect instanceof HTMLSelectElement) {
                $(els.clientSelect).on('change', () => clearSelect2Value(els.osSelect));
            }
        }

        syncAvulsoState();
    };

    // --- Quick Client Modal ---

    const renderQuickClientErrors = (messages, fallback = '') => {
        const box = document.getElementById('quickClientErrors');
        if (!(box instanceof HTMLElement)) {
            return;
        }

        const items = Array.isArray(messages) ? messages.filter(Boolean) : [];
        box.innerHTML = items.length > 0
            ? `<ul class="mb-0 ps-3">${items.map((m) => `<li>${escapeHtml(m)}</li>`).join('')}</ul>`
            : escapeHtml(fallback || 'Nao foi possivel cadastrar o cliente.');
        box.classList.remove('d-none');
    };

    const clearQuickClientErrors = () => {
        const box = document.getElementById('quickClientErrors');
        if (box instanceof HTMLElement) {
            box.classList.add('d-none');
            box.innerHTML = '';
        }
    };

    const setQuickClientSubmitState = (loading) => {
        if (!(els.quickClientSubmit instanceof HTMLButtonElement)) {
            return;
        }

        els.quickClientSubmit.disabled = loading;
        els.quickClientSubmit.innerHTML = loading
            ? '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Salvando...'
            : '<i class="bi bi-person-plus me-2"></i>Cadastrar cliente';
    };

    const applyClientSelection = (client) => {
        const clientId = Number(client?.id || 0) || 0;
        const clientName = normalizeText(client?.nome_razao || client?.name || '');

        if (clientId <= 0) {
            return;
        }

        setClientSelectValue(clientId, clientName);
        showToast('success', 'Cliente cadastrado e selecionado.');
    };

    const initQuickClient = () => {
        if (!config.quickClientStoreUrl) {
            return;
        }

        els.quickClientButton?.addEventListener('click', () => {
            getModal(els.quickClientModal)?.show();
        });

        const form = els.quickClientForm;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const submitHandler = async (event) => {
            event.preventDefault();
            clearQuickClientErrors();

            if (!form.reportValidity()) {
                renderQuickClientErrors([], 'Informe nome/razão social e telefone principal antes de salvar.');
                return;
            }

            setQuickClientSubmitState(true);

            try {
                const payload = Object.fromEntries(new FormData(form).entries());
                const response = await requestJson(config.quickClientStoreUrl, {
                    method: 'POST',
                    body: payload,
                });

                applyClientSelection(response.client || {});
                getModal(els.quickClientModal)?.hide();
            } catch (error) {
                const details = Array.isArray(error?.details)
                    ? error.details
                    : error?.details && typeof error.details === 'object'
                        ? Object.values(error.details).flat().filter(Boolean)
                        : [];

                renderQuickClientErrors(details, error.message);
                showAlert('error', 'Falha ao cadastrar cliente', error.message);
            } finally {
                setQuickClientSubmitState(false);
            }
        };

        els.quickClientSubmit?.addEventListener('click', () => {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });

        form.addEventListener('submit', submitHandler);

        els.quickClientModal?.addEventListener('hidden.bs.modal', () => {
            form.reset();
            clearQuickClientErrors();
            setQuickClientSubmitState(false);
        });
    };

    // Cada init roda isolado: um erro em qualquer um deles não pode impedir
    // os seguintes de rodar (ex.: se initClientSelect() falhar, initVinculos()
    // — que mostra/esconde Fornecedor e Despesa fixa mensal — ainda precisa
    // rodar). Sem isso, uma chamada síncrona simples deixaria tudo depois do
    // ponto de falha completamente sem inicializar, silenciosamente.
    const runInit = (name, fn) => {
        try {
            fn();
        } catch (error) {
            console.error(`[financeiro-form] Falha ao inicializar ${name}:`, error);
        }
    };

    runInit('initValorMask', initValorMask);
    runInit('initCategoriaSelect', initCategoriaSelect);
    runInit('initFinancialAccount', initFinancialAccount);
    runInit('initFormaPagamento', initFormaPagamento);
    runInit('initStatusHints', initStatusHints);
    runInit('initCartaoCredito', initCartaoCredito);
    runInit('initDataPagamento', initDataPagamento);
    runInit('initClientSelect', initClientSelect);
    runInit('initOrderSelect', initOrderSelect);
    runInit('initSupplierSelect', initSupplierSelect);
    runInit('initVinculos', initVinculos);
    runInit('initQuickClient', initQuickClient);
})();
