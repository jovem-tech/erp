<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devolve ao DRE as compras de peça que já estão gravadas e nunca viraram custo.
 *
 * Companheira de 2026_09_10_000004: aquela conserta o padrão daqui pra frente,
 * esta conserta o passado. Título de peça sem entrada de estoque vinculada
 * significa que a peça nunca existiu como ativo — foi comprada e consumida no
 * mesmo período —, então o custo pertence ao resultado daquele mês.
 *
 * O `tipo = 'entrada'` no NOT EXISTS é obrigatório: cancelar um lançamento não
 * apaga a entrada, gera uma SAÍDA com o mesmo `financeiro_id`
 * (EntradaPecaService::estornarDeLancamento). Sem o filtro, um título
 * cancelado-e-estornado passaria por "tem movimentação" e ficaria de fora.
 *
 * Cancelados ficam intocados de propósito — não entram em relatório nenhum.
 *
 * ATENÇÃO ao aplicar: isto muda o resultado de meses já fechados. Rode antes o
 * SELECT de pré-visualização (mesmo WHERE, com SUM(valor) agrupado por
 * data_competencia) e mostre ao dono quais meses mudam.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `movimentacoes` é legada: nos testes ela só nasce em
        // BuildsLegacyErpSchema, que roda DEPOIS das migrations. Lá o banco
        // está vazio, então pular é inócuo.
        if (! Schema::hasTable('financeiro') || ! Schema::hasTable('movimentacoes')) {
            return;
        }

        DB::table('financeiro')
            ->where('tipo', 'pagar')
            ->where('grupo_dre', 'Custo Direto (OS)')
            ->where('status', '<>', 'cancelado')
            ->where('impacta_dre', false)
            ->whereNotExists(static function ($query): void {
                $query->select(DB::raw(1))
                    ->from('movimentacoes')
                    ->whereColumn('movimentacoes.financeiro_id', 'financeiro.id')
                    ->where('movimentacoes.tipo', 'entrada');
            })
            ->update(['impacta_dre' => true]);
    }

    /**
     * Sem volta: não há como saber quais títulos estavam em `false` por escolha
     * do dono e quais estavam pelo padrão errado da categoria.
     */
    public function down(): void
    {
    }
};
