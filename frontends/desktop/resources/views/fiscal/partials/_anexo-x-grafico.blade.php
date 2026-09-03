{{-- Evolução mensal do faturamento bruto nos DOIS regimes.

     As barras ficam LADO A LADO, nunca empilhadas: competência e caixa são
     duas leituras da MESMA receita, e empilhá-las desenharia o dobro do que
     foi faturado. O `stacked:false` está no JS com esse comentário.

     O gráfico NÃO obedece ao alternador da tabela — ele mostra sempre os dois,
     que é justamente a comparação que ele existe para permitir. --}}
<section class="desktop-form-card">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <h3 class="surface-title mb-1">Faturamento bruto mês a mês</h3>
            <p class="surface-subtitle mb-0">
                Competência é o que conta para o limite; caixa mostra o que efetivamente entrou.
            </p>
        </div>

        <div class="anexo-x-legenda d-flex flex-wrap gap-3 small" data-anexo-x-legenda></div>
    </div>

    <div class="anexo-x-chart-wrap" style="position: relative; height: 240px;">
        <canvas data-anexo-x-chart aria-label="Faturamento bruto mensal por regime"></canvas>
    </div>
</section>
