<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documento_solicitacoes_assinatura')
            && ! Schema::hasColumn('documento_solicitacoes_assinatura', 'revisao_template_hash')) {
            Schema::table('documento_solicitacoes_assinatura', function (Blueprint $table): void {
                $table->string('revisao_template_hash', 64)->nullable()->after('revisao_snapshot_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('documento_solicitacoes_assinatura')
            && Schema::hasColumn('documento_solicitacoes_assinatura', 'revisao_template_hash')) {
            Schema::table('documento_solicitacoes_assinatura', function (Blueprint $table): void {
                $table->dropColumn('revisao_template_hash');
            });
        }
    }
};
