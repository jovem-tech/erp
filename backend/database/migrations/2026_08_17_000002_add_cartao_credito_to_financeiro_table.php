<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financeiro') && ! Schema::hasColumn('financeiro', 'cartao_credito_id')) {
            Schema::table('financeiro', function (Blueprint $table): void {
                $table->unsignedBigInteger('cartao_credito_id')->nullable()->after('fornecedor_id');
                // Data real da compra no cartao — distinta de data_vencimento, que
                // quando ha cartao vinculado passa a ser calculada (fechamento +
                // vencimento do cartao) em vez de digitada livremente. Ver
                // FinanceiroService::resolveClassification().
                $table->date('data_compra')->nullable()->after('data_vencimento');

                $table->index(['cartao_credito_id', 'data_vencimento'], 'idx_financeiro_cartao_credito_fatura');
                $table->foreign('cartao_credito_id', 'fk_financeiro_cartao_credito')
                    ->references('id')->on('financeiro_cartoes_credito')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('financeiro') && Schema::hasColumn('financeiro', 'cartao_credito_id')) {
            Schema::table('financeiro', function (Blueprint $table): void {
                $table->dropForeign('fk_financeiro_cartao_credito');
                $table->dropIndex('idx_financeiro_cartao_credito_fatura');
                $table->dropColumn(['cartao_credito_id', 'data_compra']);
            });
        }
    }
};
