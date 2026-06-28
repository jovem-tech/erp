<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comissoes_tecnicos')) {
            Schema::create('comissoes_tecnicos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tecnico_id')->unique();
                $table->decimal('percentual_padrao', 5, 2)->default(0);
                $table->boolean('ativo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('os_margem')) {
            Schema::create('os_margem', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('os_id')->unique();
                $table->decimal('receita_liquida', 12, 2)->default(0);
                $table->decimal('custo_pecas', 12, 2)->default(0);
                $table->decimal('custo_comissao', 12, 2)->default(0);
                $table->decimal('margem_contribuicao', 12, 2)->default(0);
                $table->decimal('percentual_margem', 7, 2)->default(0);
                $table->dateTime('calculado_em')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('financeiro', 'origem_garantia')) {
            Schema::table('financeiro', function (Blueprint $table): void {
                $table->boolean('origem_garantia')->default(false)->after('dre_fixo_mensal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('financeiro', 'origem_garantia')) {
            Schema::table('financeiro', function (Blueprint $table): void {
                $table->dropColumn('origem_garantia');
            });
        }

        Schema::dropIfExists('os_margem');
        Schema::dropIfExists('comissoes_tecnicos');
    }
};
