<?php

namespace App\Support;

/**
 * Divide um valor líquido entre mercadoria e serviço na proporção dos valores
 * brutos de cada parcela.
 *
 * Extraído de `DocumentoFiscalService::valoresLiquidos()`, que já fazia
 * exatamente esta conta para separar o que sai em NFS-e do que sai em NF-e. O
 * Anexo X precisa da mesma divisão para separar "revenda de mercadorias" de
 * "prestação de serviços" — e precisa que ela seja a MESMA conta, senão o
 * documento fiscal e o relatório mensal declaram repartições diferentes da
 * mesma OS.
 *
 * O desconto é rateado na proporção de cada parcela porque é assim que ele foi
 * concedido: um abatimento sobre o total, não uma liberalidade sobre a mão de
 * obra.
 *
 * **O resíduo de arredondamento fica no serviço** — a parcela maior (~79% do
 * faturamento desta base). Isso não é preferência estética: a soma tem que
 * fechar no líquido POR CONSTRUÇÃO. Arredondar os dois lados
 * independentemente perderia um centavo por operação, o que em 200 OS/mês vira
 * R$ 2,00 de divergência entre o Anexo X e o DRE — e um teste vermelho que
 * ninguém consegue explicar.
 */
final class RateioAtividade
{
    /**
     * @param  float  $mercadoria  valor bruto da parcela de mercadoria (peças)
     * @param  float  $servico  valor bruto da parcela de serviço (mão de obra)
     * @param  float  $liquido  total efetivamente cobrado, já com desconto
     * @return array{mercadoria: float, servico: float}
     */
    public static function dividir(float $mercadoria, float $servico, float $liquido): array
    {
        $bruto = $mercadoria + $servico;

        // Sem parcela para ratear (operação sem quebra de valores, ou total
        // ausente) não há rateio a fazer: devolver o que veio é melhor que
        // dividir por zero.
        if ($bruto <= 0.0 || $liquido <= 0.0) {
            return ['mercadoria' => $mercadoria, 'servico' => $servico];
        }

        $mercadoriaLiquida = round($mercadoria * $liquido / $bruto, 2);

        return [
            'mercadoria' => $mercadoriaLiquida,
            'servico' => round($liquido - $mercadoriaLiquida, 2),
        ];
    }
}
