/**
 * Gráfico do Anexo X: faturamento bruto mês a mês, nos dois regimes.
 *
 * Segue o desenho do gráfico financeiro do dashboard (dashboard-charts.js):
 * os dois regimes no mesmo payload, meses futuros esmaecidos, legenda própria
 * fora do canvas.
 */
(function () {
    'use strict';

    var CINZA_FUTURO = 'rgba(148, 163, 184, .35)';

    function moeda(valor) {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor) || 0);
    }

    /**
     * Índice do primeiro mês que ainda não terminou. Meses dali em diante saem
     * esmaecidos: já aparecem no gráfico, mas não convidam a ler como
     * resultado fechado.
     */
    function primeiroFuturo(grafico) {
        if (grafico.year > grafico.ano_corrente) {
            return 0;
        }

        if (grafico.year < grafico.ano_corrente) {
            return 12;
        }

        return Math.max(0, Number(grafico.mes_atual) - 1);
    }

    function cores(base, corte) {
        return function (contexto) {
            return contexto.dataIndex >= corte ? CINZA_FUTURO : base;
        };
    }

    function montarLegenda(alvo, grafico) {
        if (!alvo) {
            return;
        }

        alvo.innerHTML = grafico.legend.map(function (item) {
            var marca = item.type === 'dashed'
                ? '<span style="display:inline-block;width:14px;border-top:2px dashed ' + item.color + ';vertical-align:middle;"></span>'
                : '<span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' + item.color + ';"></span>';

            return '<span class="d-inline-flex align-items-center gap-1">' + marca + ' ' + item.label + '</span>';
        }).join('');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var canvas = document.querySelector('[data-anexo-x-chart]');
        var estado = window.__DESKTOP_ANEXO_X;

        if (!canvas || !estado || !estado.resumo || !estado.resumo.grafico || typeof Chart === 'undefined') {
            return;
        }

        var grafico = estado.resumo.grafico;
        var corte = primeiroFuturo(grafico);
        var limiteMensal = Number(grafico.limite.mensal_medio) || 0;

        montarLegenda(document.querySelector('[data-anexo-x-legenda]'), grafico);

        new Chart(canvas, {
            data: {
                labels: grafico.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Competência',
                        data: grafico.regimes.competencia.bruto,
                        backgroundColor: cores('#6f5afc', corte),
                        borderRadius: 4,
                        order: 2
                    },
                    {
                        type: 'bar',
                        label: 'Caixa',
                        data: grafico.regimes.caixa.bruto,
                        backgroundColor: cores('#0ea5e9', corte),
                        borderRadius: 4,
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Média mensal do limite',
                        data: new Array(12).fill(limiteMensal),
                        borderColor: '#f59e0b',
                        borderDash: [4, 4],
                        borderWidth: 1.5,
                        pointRadius: 0,
                        fill: false,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    // NUNCA empilhado. Competência e caixa são duas LEITURAS da
                    // mesma receita: empilhá-las desenharia R$ 2.400 num mês que
                    // faturou R$ 1.200. É o erro mais perigoso disponível aqui.
                    x: { stacked: false, grid: { display: false } },
                    y: {
                        stacked: false,
                        beginAtZero: true,
                        ticks: {
                            callback: function (valor) {
                                return moeda(valor).replace(/\s/g, ' ');
                            }
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (contexto) {
                                return contexto.dataset.label + ': ' + moeda(contexto.parsed.y);
                            },
                            afterBody: function (itens) {
                                var indice = itens[0].dataIndex;
                                var extras = [];

                                ['competencia', 'caixa'].forEach(function (regime) {
                                    var ajuste = Number(grafico.regimes[regime].ajuste[indice]) || 0;

                                    if (ajuste !== 0) {
                                        extras.push('Ajuste manual (' + regime + '): ' + moeda(ajuste));
                                    }
                                });

                                return extras;
                            }
                        }
                    }
                }
            }
        });
    });
})();
