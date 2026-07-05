<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financeiro', function (Blueprint $table): void {
            $table->boolean('avulso')->default(false)->after('fornecedor_id');
            $table->index(
                ['cliente_id', 'tipo', 'data_vencimento', 'id'],
                'idx_financeiro_cliente_tipo_vencimento'
            );
        });
    }

    public function down(): void
    {
        Schema::table('financeiro', function (Blueprint $table): void {
            $table->dropIndex('idx_financeiro_cliente_tipo_vencimento');
            $table->dropColumn('avulso');
        });
    }
};
