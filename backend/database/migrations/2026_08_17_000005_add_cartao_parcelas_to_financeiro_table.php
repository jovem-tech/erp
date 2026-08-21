<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financeiro') && ! Schema::hasColumn('financeiro', 'cartao_parcelas_total')) {
            Schema::table('financeiro', function (Blueprint $table): void {
                // Compra parcelada no cartão (ex.: ar-condicionado em 12x).
                //
                // Cada parcela é um título próprio, caindo numa fatura
                // consecutiva — igual à vida real, em que a fatura de cada mês
                // traz só a parcela daquele mês. Estas colunas guardam a
                // posição ("3 de 12") para a listagem e a fatura mostrarem de
                // onde aquele valor veio.
                //
                // Não confundir com "repetir nos próximos meses" (despesa fixa
                // recorrente, ex.: plano de internet): lá o valor se repete e
                // não há fim; aqui um valor total é dividido e acaba.
                $table->unsignedTinyInteger('cartao_parcela_numero')->nullable()->after('cartao_modalidade');
                $table->unsignedTinyInteger('cartao_parcelas_total')->nullable()->after('cartao_parcela_numero');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('financeiro') && Schema::hasColumn('financeiro', 'cartao_parcelas_total')) {
            Schema::table('financeiro', function (Blueprint $table): void {
                $table->dropColumn(['cartao_parcela_numero', 'cartao_parcelas_total']);
            });
        }
    }
};
