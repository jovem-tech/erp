<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_collector_pairings', function (Blueprint $table): void {
            if (! Schema::hasColumn('equipment_collector_pairings', 'submission_token')) {
                // Token de uso unico por pareamento — substitui o antigo
                // segredo global (COLLECTOR_API_TOKEN) na validacao do
                // POST /api/v1/collector/snapshots. Evita que um arquivo
                // baixado pelo cliente (ou o comando copia-e-cola) carregue
                // um segredo mestre valido pra sempre e pra todo mundo.
                $table->string('submission_token', 64)->nullable()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('equipment_collector_pairings', function (Blueprint $table): void {
            if (Schema::hasColumn('equipment_collector_pairings', 'submission_token')) {
                $table->dropColumn('submission_token');
            }
        });
    }
};
