{{-- Evolução financeira do ano em DOIS gráficos que compartilham o eixo X:
     em cima, faturamento e recebido contra as barras empilhadas de despesa;
     embaixo, uma faixa só para o lucro líquido.

     A separação não é estética. O lucro vai de -5.100 a +3.035 e, no mesmo
     eixo das outras séries, espremia todas elas num canto da tela. Em faixa
     própria, o gráfico de cima fica só com valores positivos e o lucro ganha a
     escala inteira — sem recorrer a eixo Y duplo, que faria grandezas iguais
     parecerem diferentes.

     O seletor de ano NÃO é duplicado aqui: #dashboardYear é global e já dispara
     o refetch do painel inteiro, então este gráfico acompanha o de OS acima.

     Os dois alternadores trocam a leitura no cliente, sem ida ao servidor: o
     backend manda os dois regimes no mesmo payload, e o trimestre é a soma dos
     três meses. --}}
<article class="dashboard-panel dashboard-panel-lg" data-dashboard-financial-panel hidden>
    <div class="dashboard-panel-head">
        <div>
            <h2>Evolução financeira</h2>
            <p data-dashboard-financial-subtitle>
                Faturamento, despesas e lucro líquido mês a mês.
            </p>
        </div>

        <div class="dashboard-financial-switches">
            <div class="dashboard-regime-switch" role="group" aria-label="Granularidade do gráfico financeiro">
                <button type="button" class="dashboard-regime-option is-active"
                        data-dashboard-financial-granularidade="mensal" aria-pressed="true">
                    Mensal
                </button>
                <button type="button" class="dashboard-regime-option"
                        data-dashboard-financial-granularidade="trimestral" aria-pressed="false">
                    Trimestral
                </button>
            </div>

            <div class="dashboard-regime-switch" role="group" aria-label="Regime do gráfico financeiro">
                <button type="button" class="dashboard-regime-option is-active"
                        data-dashboard-financial-regime="competencia" aria-pressed="true">
                    Competência
                </button>
                <button type="button" class="dashboard-regime-option"
                        data-dashboard-financial-regime="caixa" aria-pressed="false">
                    Caixa
                </button>
            </div>
        </div>
    </div>

    <div class="dashboard-chart-legend-row" data-dashboard-financial-legend></div>

    {{-- UM canvas, UM Chart.js: combo com as barras empilhadas de despesa e as
         três linhas (faturamento, recebido e lucro) no MESMO eixo Y em reais.
         Sem eixo secundário — barras e linhas medem a mesma grandeza, e duas
         escalas fariam uma despesa pequena parecer do tamanho de um faturamento
         grande. --}}
    <div class="dashboard-chart-wrap dashboard-chart-wrap-financial" data-dashboard-financial-chart-wrap>
        <canvas id="dashboardFinancialChart"
                aria-label="Faturamento, recebido, despesas e lucro líquido por período"
                role="img"></canvas>
    </div>

</article>
