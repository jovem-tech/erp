<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table): void {
            // Marca quando uma revisão de orçamento convertido (ver
            // orcamento_revisao_de_id) foi aprovada pelo cliente E aplicada de
            // volta ao orçamento base. Enquanto nula, a revisão aprovada ainda
            // não foi mesclada (não deveria acontecer em produção — o merge é
            // síncrono dentro da mesma transação da aprovação — mas o campo
            // existe para tornar o estado auditável).
            $table->dateTime('aplicada_em')->nullable()->after('convertido_id');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table): void {
            $table->dropColumn('aplicada_em');
        });
    }
};
