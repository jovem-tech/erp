<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `movimentacoes` ganha `financeiro_id` e `custo_unitario` (specs/039).
 *
 * `financeiro_id` e o quarto membro de uma familia que ja existe na tabela —
 * `os_id`, `venda_id`, `venda_item_id` — e responde a mesma pergunta: QUAL
 * DOCUMENTO GEROU ESTE MOVIMENTO. Aqui o documento e o lancamento financeiro da
 * compra.
 *
 * Nao e `compra_id` (planejado na 036): aquela coluna aponta para um documento
 * de nota que ainda nao existe, e um titulo a pagar nao e uma nota de compra.
 * Quando Compras nascer, os dois vao coexistir na mesma linha, do mesmo jeito
 * que `os_id` e `venda_id` coexistem hoje.
 *
 * `custo_unitario` pertence ao Bloco B da 036 e sai adiantado de proposito: com
 * varios itens num mesmo lancamento, o custo POR LINHA so existe no instante em
 * que a compra e digitada — `financeiro.valor` guarda apenas o total. Nao gravar
 * agora e perder o dado para sempre. Semantica e nome sao os que a 036 reservou:
 * custo congelado na movimentacao, preenchido so em entrada.
 *
 * SEM FOREIGN KEY, deliberadamente: nenhuma coluna dessa familia tem
 * (BuildsLegacyErpSchema declara `unsignedBigInteger` puro), e `movimentacoes` e
 * tabela LEGADA — criar a primeira FK aqui divergiria do resto da tabela. A
 * protecao contra orfao e de aplicacao: excluir lancamento com entrada e
 * bloqueado com 409 em FinanceiroController.
 *
 * `movimentacoes` e legada e so existe em tests/Concerns/BuildsLegacyErpSchema.php,
 * que roda DEPOIS das migrations e recria a tabela do zero — as colunas novas
 * precisam ser declaradas nos dois lugares, ou a suite nao as enxerga.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('movimentacoes')) {
            return;
        }

        Schema::table('movimentacoes', function (Blueprint $table): void {
            if (! Schema::hasColumn('movimentacoes', 'financeiro_id')) {
                $table->unsignedBigInteger('financeiro_id')->nullable()->after('venda_item_id');
                $table->index('financeiro_id', 'idx_movimentacoes_financeiro');
            }

            if (! Schema::hasColumn('movimentacoes', 'custo_unitario')) {
                // NULL, nao 0: "nao sei o custo" e diferente de "o custo e zero".
                // Saidas nascem sem custo unitario ate a 036 calcular o medio.
                $table->decimal('custo_unitario', 14, 4)->nullable()->after('quantidade');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('movimentacoes')) {
            return;
        }

        Schema::table('movimentacoes', function (Blueprint $table): void {
            if (Schema::hasColumn('movimentacoes', 'financeiro_id')) {
                $table->dropIndex('idx_movimentacoes_financeiro');
                $table->dropColumn('financeiro_id');
            }

            if (Schema::hasColumn('movimentacoes', 'custo_unitario')) {
                $table->dropColumn('custo_unitario');
            }
        });
    }
};
