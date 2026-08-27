<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration de DADOS, nao de schema.
 *
 * As despesas de taxa de cartao criadas na baixa da OS
 * (OrderClosureService::registerCardFeeExpense, origem_tipo
 * 'os_recebimento_cartao') nasciam com `grupo_dre` nulo. Como o DRE agrupa
 * por grupo — groupByCompetencia()/groupByMovimento() filtram
 * `where grupo_dre = 'Despesas Operacionais'` — esses titulos nao caiam em
 * NENHUMA linha do relatorio, apesar de `impacta_dre = true`. Efeito: a taxa
 * saia do caixa e nunca aparecia no resultado, subestimando a despesa do
 * periodo.
 *
 * A origem ja foi corrigida; aqui o historico e reclassificado para a mesma
 * categoria que a despesa equivalente do modulo financeiro sempre usou
 * ('Despesas Operacionais' / 'Taxas e impostos').
 *
 * ATENCAO OPERACIONAL: DREs de meses ja fechados passam a exibir essas taxas
 * como despesa variavel. O resultado exibido cai — porque antes estava alto
 * por omissao, nao porque o mes piorou.
 *
 * Nao mexe em titulos que ja tenham grupo preenchido (classificacao manual do
 * usuario tem precedencia) nem em cancelados.
 */
return new class extends Migration
{
    private const ORIGEM = 'os_recebimento_cartao';

    public function up(): void
    {
        if (! Schema::hasTable('financeiro')) {
            return;
        }

        DB::table('financeiro')
            ->where('origem_tipo', self::ORIGEM)
            ->where('status', '!=', 'cancelado')
            ->whereNull('grupo_dre')
            ->update([
                'grupo_dre' => 'Despesas Operacionais',
                'subgrupo_dre' => 'Taxas e impostos',
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('financeiro')) {
            return;
        }

        // Reverte apenas o que esta migration poderia ter escrito: a
        // combinacao exata de origem + grupo + subgrupo.
        DB::table('financeiro')
            ->where('origem_tipo', self::ORIGEM)
            ->where('grupo_dre', 'Despesas Operacionais')
            ->where('subgrupo_dre', 'Taxas e impostos')
            ->update([
                'grupo_dre' => null,
                'subgrupo_dre' => null,
            ]);
    }
};
