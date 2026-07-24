<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relato do cliente / defeito relatado no orçamento. No fluxo avulso, o defeito
 * informado pelo cliente é a razão do orçamento; ao gerar a OS ele pré-preenche
 * o "Relato do cliente" da ordem (que é obrigatório).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orcamentos')) {
            return;
        }

        Schema::table('orcamentos', function (Blueprint $table): void {
            if (! Schema::hasColumn('orcamentos', 'relato_cliente')) {
                $table->text('relato_cliente')->nullable()->after('titulo');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orcamentos')) {
            return;
        }

        Schema::table('orcamentos', function (Blueprint $table): void {
            if (Schema::hasColumn('orcamentos', 'relato_cliente')) {
                $table->dropColumn('relato_cliente');
            }
        });
    }
};
