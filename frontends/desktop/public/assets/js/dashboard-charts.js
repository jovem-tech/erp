/**
 * Fábricas de gráfico do dashboard.
 *
 * Separado de dashboard.js porque os dois arquivos mudam por motivos
 * diferentes: aqui mora a configuração do Chart.js (escalas, tooltip, cores),
 * lá mora o ciclo de fetch e a montagem do HTML dos painéis.
 */
(() => {
    const instances = {
        monthly: null,
        financial: null,
        status: null,
        equipment: null,
        context: null,
    };

    const formatNumber = (value) => new Intl.NumberFormat('pt-BR', {
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));

    const destroy = (key) => {
        if (instances[key]) {
            instances[key].destroy();
            instances[key] = null;
        }
    };

    const baseTooltip = {
        backgroundColor: '#1f2937',
        titleColor: '#fff',
        bodyColor: '#fff',
        padding: 10,
        displayColors: true,
    };

    /**
     * Cor de fundo real do card, lida do tema ativo. Era um '#f8fafc' fixo, que
     * desenhava um vinco claro entre os segmentos empilhados em qualquer tema
     * escuro — o separador precisa ser a cor da superfície, não branco.
     */
    const surfaceColor = () => {
        const value = getComputedStyle(document.documentElement)
            .getPropertyValue('--desktop-surface')
            .trim();

        return value !== '' ? value : '#f8fafc';
    };

    const navigateTo = (url) => {
        if (typeof url !== 'string' || url === '') {
            return;
        }

        // Sem esta marcação o guard de sessão interpreta a saída da página como
        // "navegador fechado" e desloga o usuário no meio do clique.
        window.erpMarkInternalNavigation?.();
        window.location.assign(url);
    };

    const createLineChart = (canvas, summary) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const points = Array.isArray(summary?.points) ? summary.points : [];
        const labels = points.map((point) => point.label || '');
        const series = Array.isArray(summary?.series) ? summary.series : [];

        // Dinheiro do mesmo mês, quando o usuário tem acesso financeiro.
        const faturamento = Array.isArray(summary?.money?.faturamento) ? summary.money.faturamento : null;
        const lucro = Array.isArray(summary?.money?.lucro) ? summary.money.lucro : null;
        const temDinheiro = Boolean(faturamento && lucro);

        // Zeros ALINHADOS entre os dois eixos.
        //
        // Sem isto o R$ 0,00 cai na altura de "10 OS" e as barras de prejuízo
        // ficam penduradas no meio do gráfico, como se um lucro negativo
        // começasse em dez ordens de serviço. Alinhar é obrigatório em eixo
        // duplo com série negativa: os dois eixos passam a ter a mesma fração
        // de altura abaixo do zero.
        const limites = () => {
            if (!temDinheiro) {
                return { osMin: 0, osMax: undefined, moneyMin: 0, moneyMax: 1 };
            }

            const valores = [...faturamento, ...lucro].map(Number).filter(Number.isFinite);
            const bruto = Math.min(0, ...valores);
            const teto = Math.max(0, ...valores, 1);
            const unidade = Math.pow(10, Math.floor(Math.log10((teto - bruto) / 5 || 1)));
            const moneyMin = Math.floor(bruto / unidade) * unidade;
            const moneyMax = Math.ceil(teto / unidade) * unidade;

            const fracaoAbaixo = moneyMin < 0 ? -moneyMin / (moneyMax - moneyMin) : 0;

            // Teto do eixo de OS arredondado ANTES de derivar o piso. Usar o
            // máximo cru (36) e deixar o Chart.js arredondar depois (para 40)
            // mudaria a proporção e os dois zeros sairiam 6px fora de registro.
            const osMax = niceCeil(Math.max(1, ...series.flatMap((item) => (item.data || []).map(Number))));

            return {
                moneyMin,
                moneyMax,
                osMax,
                osMin: fracaoAbaixo > 0 ? -(osMax * fracaoAbaixo) / (1 - fracaoAbaixo) : 0,
            };
        };

        const { osMin, osMax, moneyMin, moneyMax } = limites();

        // Só a primeira linha (OS abertas) preenche a área — é o pano de fundo
        // contra o qual a segunda linha é lida. Preencher as duas faz as áreas
        // se sobreporem numa mancha só, sem dar pra distinguir de qual série é
        // qual pedaço. tension 0 (reta, não curva) evita o efeito "balão" que
        // uma curva suave faz quando dois meses distantes se conectam.
        const linhas = series.map((item, index) => ({
            type: 'line',
            label: item.label || '',
            data: Array.isArray(item.data) ? item.data : [],
            borderColor: item.color || '#6f5afc',
            backgroundColor: item.backgroundColor || 'rgba(111, 90, 252, 0.18)',
            tension: 0,
            fill: index === 0,
            pointRadius: 3,
            pointHoverRadius: 5,
            pointBackgroundColor: item.color || '#6f5afc',
            borderWidth: 3,
            yAxisID: 'y',
            order: 1,
        }));

        // Barras AGRUPADAS, não empilhadas: lucro é derivado do faturamento
        // (faturamento − despesas), então somar os dois numa pilha produziria um
        // total que não existe — em março daria R$ 6.070 para um faturamento de
        // R$ 3.035. Lado a lado, cada barra vale por si.
        const barras = temDinheiro ? [
            {
                type: 'bar',
                label: 'Faturamento',
                data: faturamento,
                // Âmbar, e não o roxo do gráfico financeiro: aqui o roxo já é a
                // linha "OS abertas". Duas séries de unidades diferentes com a
                // mesma cor no mesmo gráfico é o pior tipo de ambiguidade.
                backgroundColor: 'rgba(245, 158, 11, 0.55)',
                yAxisID: 'yMoney',
                borderRadius: 2,
                barPercentage: 0.72,
                categoryPercentage: 0.72,
                order: 2,
            },
            {
                type: 'bar',
                label: 'Lucro líquido',
                data: lucro,
                // Verde/vermelho fica: é semântico (lucro x prejuízo) e vale
                // mais que evitar a vizinhança com a linha verde de OS
                // entregues — a legenda distingue barra de linha pela forma.
                backgroundColor: lucro.map((v) => (Number(v) < 0 ? 'rgba(220, 38, 38, 0.55)' : 'rgba(22, 163, 74, 0.55)')),
                yAxisID: 'yMoney',
                borderRadius: 2,
                barPercentage: 0.72,
                categoryPercentage: 0.72,
                order: 2,
            },
        ] : [];

        destroy('monthly');
        instances.monthly = new Chart(canvas, {
            data: {
                labels,
                datasets: [...barras, ...linhas],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...baseTooltip,
                        callbacks: {
                            // Contagem e dinheiro dividem o tooltip, então cada
                            // linha precisa do seu formato: 36 OS x R$ 1.986,00.
                            label: (context) => (context.dataset.yAxisID === 'yMoney'
                                ? ` ${context.dataset.label}: ${formatMoney(context.parsed.y)}`
                                : ` ${context.dataset.label}: ${formatNumber(context.parsed.y)}`),
                            // O saldo é a leitura que interessa ao gestor: entrou
                            // mais do que saiu neste mês, ou o contrário? Ler isso
                            // subtraindo duas linhas de olho é trabalho manual.
                            footer: (items) => {
                                const os = items.filter((item) => item.dataset.yAxisID !== 'yMoney');

                                if (os.length < 2) {
                                    return '';
                                }

                                const balance = Number(os[0]?.parsed?.y ?? 0) - Number(os[1]?.parsed?.y ?? 0);

                                return `Saldo: ${balance >= 0 ? '+' : ''}${formatNumber(balance)}`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#94a3b8' } },
                    y: {
                        min: osMin,
                        max: osMax,
                        grid: { color: 'rgba(148, 163, 184, 0.14)' },
                        ticks: {
                            color: '#94a3b8',
                            precision: 0,
                            // A faixa abaixo do zero existe só para alinhar com
                            // o eixo do dinheiro; "-20 OS" não significa nada.
                            callback: (value) => (value < 0 ? undefined : formatNumber(value)),
                        },
                    },
                    // Segundo eixo, à direita, só quando há dinheiro no payload.
                    //
                    // Aqui o eixo secundário é a solução, não o problema: OS é
                    // contagem (pico 36) e faturamento é dinheiro (pico 3.035) —
                    // 84x de diferença. No mesmo eixo, as linhas de OS virariam
                    // um risco de 1% de altura colado na base. É o oposto do
                    // gráfico financeiro, onde todas as séries são reais e um
                    // segundo eixo faria grandezas iguais parecerem diferentes.
                    yMoney: {
                        display: temDinheiro,
                        position: 'right',
                        min: moneyMin,
                        max: moneyMax,
                        grid: { display: false },
                        ticks: { color: '#94a3b8', callback: (value) => formatMoney(value) },
                    },
                },
            },
        });
    };

    /**
     * Donut das macrofases. Recebe `groups` (agrupamento visual montado no
     * DashboardService) e navega para a listagem de OS filtrada no clique.
     */
    const createDoughnutChart = (canvas, groups) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const items = Array.isArray(groups) ? groups : [];
        const total = items.reduce((sum, item) => sum + Number(item.total ?? 0), 0);

        destroy('status');
        instances.status = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: items.map((item) => item.nome || 'Sem status'),
                datasets: [
                    {
                        data: items.map((item) => Number(item.total ?? 0)),
                        backgroundColor: items.map((item) => item.cor || '#6f5afc'),
                        borderWidth: 0,
                        hoverOffset: 6,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                onHover: (event, elements) => {
                    const target = event?.native?.target;
                    if (target) {
                        target.style.cursor = elements.length > 0 && items[elements[0].index]?.url
                            ? 'pointer'
                            : 'default';
                    }
                },
                onClick: (event, elements) => {
                    if (elements.length === 0) {
                        return;
                    }

                    navigateTo(items[elements[0].index]?.url);
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...baseTooltip,
                        callbacks: {
                            label: (context) => {
                                const value = Number(context.parsed ?? 0);
                                const share = total > 0 ? (value / total) * 100 : 0;

                                return ` ${formatNumber(value)} OS · ${share.toFixed(1).replace('.', ',')}%`;
                            },
                        },
                    },
                },
            },
        });
    };

    const createStackedBarChart = (canvas, summary) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const labels = Array.isArray(summary?.labels) ? summary.labels : [];
        const series = Array.isArray(summary?.series) ? summary.series : [];
        const stackSurfaceColor = surfaceColor();
        const lastSeriesIndex = series.length - 1;

        destroy('equipment');
        instances.equipment = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: series.map((item, seriesIndex) => ({
                    label: item.label || 'Sem tipo',
                    data: Array.isArray(item.data) ? item.data.map((value) => Number(value ?? 0)) : [],
                    backgroundColor: item.backgroundColor || item.color || '#3b82f6',
                    borderColor: stackSurfaceColor,
                    borderWidth: 2,
                    // Só o topo da pilha arredonda; arredondar cada segmento faria
                    // a coluna parecer uma sequência de pílulas soltas.
                    borderRadius: seriesIndex === lastSeriesIndex
                        ? { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 }
                        : 0,
                    borderSkipped: false,
                    maxBarThickness: 24,
                    stack: 'equipment',
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...baseTooltip,
                        // Sem itemSort/filter o tooltip lista os nove tipos, quase
                        // todos zerados, e cobre metade do gráfico.
                        filter: (context) => Number(context.parsed?.y ?? 0) > 0,
                        callbacks: {
                            label: (context) => ` ${context.dataset.label}: ${formatNumber(context.parsed.y)} OS`,
                            footer: (items) => {
                                const total = items.reduce((sum, item) => sum + Number(item.parsed?.y ?? 0), 0);

                                return `Total: ${formatNumber(total)} OS`;
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { color: '#94a3b8' } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.14)' },
                        ticks: { color: '#94a3b8', precision: 0 },
                    },
                },
            },
        });
    };

    const createBarChart = (canvas, summary, options = {}) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const labels = Array.isArray(summary?.labels) ? summary.labels : [];
        const data = (Array.isArray(summary?.values) ? summary.values : []).map((value) => Number(value ?? 0));

        destroy('context');
        instances.context = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: options.label || 'Valores',
                        data,
                        backgroundColor: options.color || '#3b82f6',
                        borderRadius: 10,
                        maxBarThickness: options.maxBarThickness || 30,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: options.indexAxis || 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...baseTooltip,
                        callbacks: {
                            label: (context) => ` ${options.formatValue
                                ? options.formatValue(context.parsed.x ?? context.parsed.y)
                                : formatNumber(context.parsed.x ?? context.parsed.y)}`,
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.14)' },
                        ticks: { color: '#94a3b8' },
                    },
                    y: { grid: { display: false }, ticks: { color: '#94a3b8' } },
                },
            },
        });
    };

    /** Arredonda para cima num passo "redondo" (1, 2, 2.5, 5 ou 10 x 10^n). */
    const niceCeil = (valor, divisoes = 4) => {
        if (!(valor > 0)) {
            return 1;
        }

        const bruto = valor / divisoes;
        const magnitude = Math.pow(10, Math.floor(Math.log10(bruto)));
        const normalizado = bruto / magnitude;
        const passo = (normalizado <= 1 ? 1 : normalizado <= 2 ? 2 : normalizado <= 2.5 ? 2.5 : normalizado <= 5 ? 5 : 10) * magnitude;

        return Math.ceil(valor / passo) * passo;
    };

    const formatMoney = (value) => new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number(value ?? 0));

    /**
     * Séries escondidas na legenda. Vive aqui, e não no payload, porque é
     * preferência de leitura do usuário: precisa sobreviver à troca de regime,
     * de granularidade e ao refetch por mudança de ano.
     *
     * "Recebido" começa DESLIGADO de propósito. Com as cinco séries ligadas, o
     * combo fica denso demais para leitura de relance, e o recebido é a que
     * menos responde a pergunta central do painel ("estou tendo lucro ou
     * prejuízo?") — ela responde uma segunda, sobre liquidez. Quem quiser
     * comparar faturado x recebido liga num clique, e a escolha fica guardada.
     */
    const financialHidden = new Set(['recebido']);

    const isFinancialSeriesHidden = (key) => financialHidden.has(key);

    const LUCRO_POSITIVO = '#16a34a';
    const LUCRO_NEGATIVO = '#dc2626';

    /**
     * Despesa fixa em slate-600, e não no slate-400 de antes: aquele era
     * exatamente a cor do texto dos eixos, então a série lia como moldura
     * desabilitada em vez de dado.
     */
    const DESPESA_FIXA = '#475569';
    const DESPESA_VARIAVEL = '#f59e0b';

    const SERIES_KEYS = ['faturamento', 'recebido', 'deducoes', 'outras_receitas', 'despesa_fixa', 'despesa_variavel', 'lucro'];

    /**
     * Soma os meses em trimestres.
     *
     * Feito no cliente, e não numa segunda consulta, porque todas as séries são
     * aditivas: o trimestre é a soma dos três meses e sai idêntico ao que o
     * banco devolveria. Isso garante que mensal e trimestral nunca discordem —
     * são a mesma série — e mantém a troca instantânea.
     */
    const aggregateQuarterly = (series) => {
        const trimestres = [0, 1, 2, 3];
        const agregada = {};

        SERIES_KEYS.forEach((key) => {
            const mensal = Array.isArray(series?.[key]) ? series[key] : [];
            agregada[key] = trimestres.map((t) => {
                const soma = mensal.slice(t * 3, t * 3 + 3)
                    .reduce((acc, valor) => acc + Number(valor ?? 0), 0);

                return Math.round(soma * 100) / 100;
            });
        });

        return {
            labels: trimestres.map((t) => `T${t + 1}`),
            series: agregada,
            // Um trimestre é futuro quando o PRIMEIRO mês dele já é futuro: com
            // setembro corrente, T3 (Jul–Set) é presente e T4 (Out–Dez) não.
            futuroDe: (primeiroFuturoMensal) => Math.ceil(primeiroFuturoMensal / 3),
        };
    };

    const toggleFinancialSeries = (key) => {
        if (financialHidden.has(key)) {
            financialHidden.delete(key);
        } else {
            financialHidden.add(key);
        }

        applyFinancialVisibility();

        return !financialHidden.has(key);
    };

    const applyFinancialVisibility = () => {
        const chart = instances.financial;

        if (!chart) {
            return;
        }

        chart.data.datasets.forEach((dataset, index) => {
            chart.setDatasetVisibility(index, !financialHidden.has(dataset.datasetKey));
        });

        chart.update();
    };

    /**
     * Evolução financeira do período: UM combo chart, um canvas, cinco séries no
     * MESMO eixo Y em reais.
     *
     * Duas armadilhas do Chart.js que este arranjo obriga a tratar:
     *
     * 1. Empilhar as BARRAS sem empilhar as LINHAS. O Chart.js só empilha com
     *    `stacked` ligado na escala, e ligado ele arrastaria as linhas junto —
     *    o lucro apareceria somado ao faturamento. A saída é ligar o stacking e
     *    dar a cada linha um `stack` próprio e exclusivo, o que a isola do grupo
     *    'despesas' e das outras linhas.
     *
     * 2. Eixo Y único, jamais secundário. Barras e linhas medem a mesma
     *    grandeza; duas escalas fariam uma despesa pequena parecer do tamanho de
     *    um faturamento grande.
     *
     * Ordem de desenho (`order`): o lucro por último, por cima de todo o resto —
     * é a série que responde "estou ganhando ou perdendo dinheiro".
     */
    const createFinancialChart = (canvas, payload, regime, granularidade) => {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const mensais = Array.isArray(payload?.labels) ? payload.labels : [];
        const seriesMensais = payload?.regimes?.[regime];

        if (!seriesMensais) {
            return;
        }

        // Índice do primeiro período que ainda não aconteceu. Em anos passados
        // nada é futuro; em anos futuros, tudo é.
        const anoCorrente = Number(payload?.ano_corrente ?? 0);
        const ano = Number(payload?.year ?? 0);
        let primeiroFuturo = mensais.length;
        if (ano > anoCorrente) {
            primeiroFuturo = 0;
        } else if (ano === anoCorrente) {
            primeiroFuturo = Number(payload?.mes_atual ?? mensais.length);
        }

        let labels = mensais;
        let series = seriesMensais;

        if (granularidade === 'trimestral') {
            const agregado = aggregateQuarterly(seriesMensais);
            labels = agregado.labels;
            series = agregado.series;
            primeiroFuturo = agregado.futuroDe(primeiroFuturo);
        }

        const isFuturo = (index) => index >= primeiroFuturo;
        const barColor = (base, faded) => labels.map((_, i) => (isFuturo(i) ? faded : base));
        const lucro = Array.isArray(series.lucro) ? series.lucro : [];

        // Período futuro sempre lê mais fraco que realizado — nas barras pela
        // opacidade, nas linhas pelo tracejado. O "Recebido" já é tracejado por
        // identidade, então nele o futuro se distingue só pela opacidade.
        const futuroDash = (ctx) => (isFuturo(ctx.p1DataIndex) ? [6, 4] : undefined);
        const futuroCor = (cor, fraca) => (ctx) => (isFuturo(ctx.p1DataIndex) ? fraca : cor);

        destroy('financial');
        instances.financial = new Chart(canvas, {
            data: {
                labels,
                datasets: [
                    {
                        type: 'bar',
                        datasetKey: 'despesa_fixa',
                        label: 'Despesa fixa',
                        data: series.despesa_fixa || [],
                        backgroundColor: barColor(DESPESA_FIXA, 'rgba(71, 85, 105, 0.32)'),
                        stack: 'despesas',
                        borderRadius: 2,
                        order: 4,
                    },
                    {
                        type: 'bar',
                        datasetKey: 'despesa_variavel',
                        label: 'Despesa variável',
                        data: series.despesa_variavel || [],
                        backgroundColor: barColor(DESPESA_VARIAVEL, 'rgba(245, 158, 11, 0.32)'),
                        stack: 'despesas',
                        borderRadius: 2,
                        order: 4,
                    },
                    {
                        type: 'line',
                        datasetKey: 'faturamento',
                        label: 'Faturamento',
                        data: series.faturamento || [],
                        borderColor: '#6f5afc',
                        pointBackgroundColor: '#6f5afc',
                        backgroundColor: 'rgba(111, 90, 252, 0.14)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        // tension 0: segmentos retos. A curva suave do Chart.js
                        // faz overshoot entre pontos distantes e chega a mergulhar
                        // abaixo de zero entre dois meses positivos — num gráfico
                        // de dinheiro isso desenha prejuízo que não existiu.
                        tension: 0,
                        // Área preenchida: o que interessa entre faturamento e
                        // recebido é a DISTÂNCIA — quanto do faturado ainda não
                        // virou dinheiro. Massa lê isso melhor que dois traços.
                        fill: true,
                        // `stack` próprio e exclusivo isola a linha do grupo das
                        // barras — ver o comentário do bloco.
                        stack: 'linha-faturamento',
                        order: 3,
                        segment: {
                            borderDash: futuroDash,
                            borderColor: futuroCor('#6f5afc', 'rgba(111, 90, 252, 0.45)'),
                        },
                    },
                    {
                        type: 'line',
                        datasetKey: 'recebido',
                        label: 'Recebido',
                        data: series.recebido || [],
                        borderColor: '#0ea5e9',
                        pointBackgroundColor: '#0ea5e9',
                        backgroundColor: 'rgba(14, 165, 233, 0.14)',
                        borderWidth: 2,
                        borderDash: [5, 4],
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0,
                        fill: true,
                        stack: 'linha-recebido',
                        order: 2,
                        segment: { borderColor: futuroCor('#0ea5e9', 'rgba(14, 165, 233, 0.45)') },
                    },
                    {
                        type: 'line',
                        datasetKey: 'lucro',
                        label: 'Lucro líquido',
                        data: lucro,
                        borderWidth: 3,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0,
                        stack: 'linha-lucro',
                        order: 1,
                        borderColor: LUCRO_POSITIVO,
                        pointBackgroundColor: lucro.map((v) => (Number(v) < 0 ? LUCRO_NEGATIVO : LUCRO_POSITIVO)),
                        pointBorderColor: lucro.map((v) => (Number(v) < 0 ? LUCRO_NEGATIVO : LUCRO_POSITIVO)),
                        // Preenche do zero até a linha, verde para cima e
                        // vermelho para baixo: transforma "a linha está negativa"
                        // em massa vermelha que se vê de longe.
                        fill: {
                            target: { value: 0 },
                            above: 'rgba(22, 163, 74, 0.16)',
                            below: 'rgba(220, 38, 38, 0.16)',
                        },
                        segment: {
                            // Um trecho que cruza o zero fica de uma cor só —
                            // limitação do `segment`, que pinta de ponto a ponto.
                            borderColor: (ctx) => (Number(ctx.p1?.parsed?.y) < 0 ? LUCRO_NEGATIVO : LUCRO_POSITIVO),
                            borderDash: futuroDash,
                        },
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    // Legenda nativa desligada: a do painel é custom, fica acima
                    // do canvas e alterna as séries no clique.
                    legend: { display: false },
                    tooltip: {
                        ...baseTooltip,
                        callbacks: {
                            label: (context) => ` ${context.dataset.label}: ${formatMoney(context.parsed.y)}`,
                            // Deduções e outras receitas só aparecem quando
                            // existem — zeradas seriam só ruído.
                            afterBody: (items) => {
                                const i = items?.[0]?.dataIndex;

                                if (i === undefined) {
                                    return [];
                                }

                                const extras = [];
                                const deducoes = Number(series.deducoes?.[i] ?? 0);
                                const outras = Number(series.outras_receitas?.[i] ?? 0);

                                if (deducoes) {
                                    extras.push(` Deduções: -${formatMoney(deducoes)}`);
                                }
                                if (outras) {
                                    extras.push(` Outras receitas: ${formatMoney(outras)}`);
                                }

                                return extras;
                            },
                            // Rótulo neutro de propósito: recebido acima do
                            // faturado NÃO significa adiantamento, significa
                            // cobrança de meses anteriores caindo agora.
                            footer: (items) => {
                                const i = items?.[0]?.dataIndex;

                                // Sem o "Recebido" na tela não há o que
                                // comparar: o Chart.js já omite a linha dele do
                                // corpo do tooltip, e um rodapé calculado sobre
                                // um número invisível confunde em vez de ajudar.
                                if (i === undefined || financialHidden.has('recebido')) {
                                    return '';
                                }

                                const faturado = Number(series.faturamento?.[i] ?? 0);
                                const recebido = Number(series.recebido?.[i] ?? 0);
                                const diferenca = Math.round((recebido - faturado) * 100) / 100;

                                if (!diferenca) {
                                    return '';
                                }

                                return `Recebido − faturado: ${diferenca > 0 ? '+' : '−'}${formatMoney(Math.abs(diferenca))}`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { color: '#94a3b8' },
                    },
                    y: {
                        // Único eixo, em reais. Sem eixo secundário: barras e
                        // linhas medem a mesma grandeza.
                        stacked: true,
                        grid: { color: 'rgba(148, 163, 184, 0.14)' },
                        ticks: { color: '#94a3b8', callback: (value) => formatMoney(value) },
                    },
                },
            },
        });

        applyFinancialVisibility();
    };

    window.DesktopDashboardCharts = {
        createLineChart,
        createFinancialChart,
        toggleFinancialSeries,
        isFinancialSeriesHidden,
        createDoughnutChart,
        createStackedBarChart,
        createBarChart,
        navigateTo,
    };
})();
