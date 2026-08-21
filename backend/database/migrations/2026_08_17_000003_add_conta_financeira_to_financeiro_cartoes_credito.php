<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financeiro_cartoes_credito')
            && ! Schema::hasColumn('financeiro_cartoes_credito', 'conta_financeira_id')) {
            Schema::table('financeiro_cartoes_credito', function (Blueprint $table): void {
                // Conta de Contas e Saldos de onde o dinheiro sai. No débito é
                // ela que recebe a baixa (o valor sai na hora da compra); no
                // crédito serve de sugestão para quando a fatura for paga.
                $table->unsignedBigInteger('conta_financeira_id')->nullable()->after('instituicao');

                $table->index(['conta_financeira_id'], 'idx_fin_cartoes_credito_conta');
                $table->foreign('conta_financeira_id', 'fk_fin_cartoes_credito_conta')
                    ->references('id')->on('financeiro_contas')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('financeiro_cartoes_credito')
            && Schema::hasColumn('financeiro_cartoes_credito', 'conta_financeira_id')) {
            Schema::table('financeiro_cartoes_credito', function (Blueprint $table): void {
                $table->dropForeign('fk_fin_cartoes_credito_conta');
                $table->dropIndex('idx_fin_cartoes_credito_conta');
                $table->dropColumn('conta_financeira_id');
            });
        }
    }
};
