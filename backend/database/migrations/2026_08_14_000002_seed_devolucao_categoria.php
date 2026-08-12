<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categoria e subgrupo DRE da devolução de venda — specs/029-devolucao-troca.
 *
 * A devolução é um título A PAGAR, e por isso mora sob "Despesas Operacionais".
 * Contabilmente ela é redução de receita, mas o DRE do sistema só tem grupos de
 * receita e de despesa: manter a receita original intacta e lançar a devolução
 * como despesa infla os dois lados em paralelo, fecha certo no resultado e
 * deixa o volume de devoluções visível — que é o que a gestão precisa enxergar.
 *
 * Estornar a receita original seria pior: reescreveria o DRE de um mês
 * possivelmente já fechado.
 *
 * Não cria permissão nova: devolver é ação do módulo `vendas`.
 */
return new class extends Migration
{
    private const CATEGORIA = 'Devolução de venda';

    public function up(): void
    {
        if (! Schema::hasTable('financeiro_dre_grupos')
            || ! Schema::hasTable('financeiro_dre_subgrupos')
            || ! Schema::hasTable('financeiro_categorias')
        ) {
            return;
        }

        $grupoId = (int) DB::table('financeiro_dre_grupos')
            ->where('nome', 'Despesas Operacionais')
            ->value('id');

        if ($grupoId <= 0) {
            return;
        }

        $now = now();

        $subgrupoId = (int) DB::table('financeiro_dre_subgrupos')
            ->where('grupo_id', $grupoId)
            ->where('nome', self::CATEGORIA)
            ->value('id');

        if ($subgrupoId <= 0) {
            $subgrupoId = (int) DB::table('financeiro_dre_subgrupos')->insertGetId([
                'grupo_id' => $grupoId,
                'nome' => self::CATEGORIA,
                'ordem_exibicao' => 85,
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (DB::table('financeiro_categorias')->where('nome', self::CATEGORIA)->exists()) {
            return;
        }

        DB::table('financeiro_categorias')->insert([
            'nome' => self::CATEGORIA,
            'tipo' => 'pagar',
            'dre_grupo_id' => $grupoId,
            'dre_subgrupo_id' => $subgrupoId,
            'impacta_dre_padrao' => true,
            'impacta_fluxo_caixa_padrao' => true,
            'dre_fixo_mensal_padrao' => false,
            'ordem_exibicao' => 95,
            'ativo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Cache::flush();
    }

    public function down(): void
    {
        if (Schema::hasTable('financeiro_categorias')) {
            DB::table('financeiro_categorias')->where('nome', self::CATEGORIA)->delete();
        }

        if (Schema::hasTable('financeiro_dre_subgrupos')) {
            DB::table('financeiro_dre_subgrupos')->where('nome', self::CATEGORIA)->delete();
        }

        Cache::flush();
    }
};
