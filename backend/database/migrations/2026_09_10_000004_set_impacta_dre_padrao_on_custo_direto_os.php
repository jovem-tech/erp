<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Compra de peças" volta a impactar o DRE por padrão.
 *
 * O seed de 2026_06_27_000001 criou a categoria com `impacta_dre_padrao = 0` —
 * única categoria "pagar" assim — apoiado na regra da specs/039: comprar peça
 * é aquisição de ativo, e o custo só vira CMV quando a peça sai do estoque
 * (CPC 16 / Lei 6.404/76 art. 187).
 *
 * A regra está certa, mas dependia de uma evidência que quase nunca existe: a
 * entrada de estoque. Sem ela não há ativo nenhum — a peça foi comprada e
 * aplicada direto na bancada — e o gasto sumia do resultado para sempre, já
 * que as queries de DRE filtram `impacta_dre = 1` e o outro caminho ("Peças
 * aplicadas") depende de movimentação de estoque que nunca foi criada.
 *
 * O padrão passa a ser "consumido"; quem força "ativo" é
 * FinanceiroService::resolveClassification(), e só quando a compra realmente
 * gera entrada de estoque. Inverter importa: se a regra tivesse de ADICIONAR
 * impacto, qualquer caminho novo de criação que a esquecesse voltaria a perder
 * o custo em silêncio.
 */
return new class extends Migration
{
    private const GRUPO = 'Custo Direto (OS)';

    public function up(): void
    {
        if (! Schema::hasTable('financeiro_dre_grupos') || ! Schema::hasTable('financeiro_categorias')) {
            return;
        }

        $grupoId = (int) DB::table('financeiro_dre_grupos')
            ->where('nome', self::GRUPO)
            ->value('id');

        if ($grupoId <= 0) {
            return;
        }

        DB::table('financeiro_categorias')
            ->where('tipo', 'pagar')
            ->where('dre_grupo_id', $grupoId)
            ->where('impacta_dre_padrao', false)
            ->update(['impacta_dre_padrao' => true]);
    }

    /**
     * Sem volta: reverter reabriria o buraco que esta migration fecha, e não há
     * como distinguir a categoria que nasceu `false` no seed daquela que o dono
     * desmarcou de propósito em Configurações financeiras.
     */
    public function down(): void
    {
    }
};
