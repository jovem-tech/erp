<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selo "emite nota fiscal de serviço (NFS-e)" na landing pública do
 * orçamento em níveis. Booleano simples, sem a dimensão "por nível" que
 * `entrega_domicilio`/`garantia_dias` ganharam: emissão de nota não varia
 * conforme a opção de manutenção escolhida, então não há override em
 * `orcamento_nivel_condicoes` para este campo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orcamentos') && ! Schema::hasColumn('orcamentos', 'emite_nota_fiscal')) {
            Schema::table('orcamentos', function (Blueprint $table): void {
                $table->boolean('emite_nota_fiscal')->default(false)->after('entrega_domicilio');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orcamentos') && Schema::hasColumn('orcamentos', 'emite_nota_fiscal')) {
            Schema::table('orcamentos', function (Blueprint $table): void {
                $table->dropColumn('emite_nota_fiscal');
            });
        }
    }
};
