<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financeiro') && ! Schema::hasColumn('financeiro', 'cartao_modalidade')) {
            Schema::table('financeiro', function (Blueprint $table): void {
                // Crédito ou débito da compra no cartão.
                //
                // Não dá para derivar isso de financeiro.forma_pagamento: essa
                // coluna é sincronizada a partir das BAIXAS
                // (FinanceiroService::syncFromMovements()) e fica NULL enquanto
                // o título está pendente — justamente o estado em que a despesa
                // passa a maior parte da vida esperando a fatura. Sem uma coluna
                // própria, o agrupamento por fatura não teria como separar o
                // crédito (entra na fatura) do débito (sai da conta na hora).
                $table->string('cartao_modalidade', 10)->nullable()->after('cartao_credito_id');

                $table->index(['cartao_credito_id', 'cartao_modalidade'], 'idx_financeiro_cartao_modalidade');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('financeiro') && Schema::hasColumn('financeiro', 'cartao_modalidade')) {
            Schema::table('financeiro', function (Blueprint $table): void {
                $table->dropIndex('idx_financeiro_cartao_modalidade');
                $table->dropColumn('cartao_modalidade');
            });
        }
    }
};
