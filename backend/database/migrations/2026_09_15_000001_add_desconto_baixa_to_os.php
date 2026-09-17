<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desconto concedido ao cliente no fechamento da OS (specs: desconto na baixa).
 *
 * Guardado em colunas próprias, separadas de `os.desconto`/`os.valor_final` —
 * essas duas são reescritas por inteiro a cada sincronização de orçamento
 * (BudgetOrderSyncService::syncOrderFinancials), então um desconto gravado só
 * nelas seria apagado silenciosamente se o orçamento vinculado for
 * ressincronizado depois. `desconto_baixa_*` fica como registro histórico
 * estável de quanto — e por quê — foi concedido nesta baixa, independente do
 * que aconteça depois com o orçamento; ver OrderClosureService::close().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('os') || Schema::hasColumn('os', 'desconto_baixa')) {
            return;
        }

        Schema::table('os', function (Blueprint $table): void {
            $table->decimal('desconto_baixa', 10, 2)->default(0)->after('desconto');
            $table->string('desconto_baixa_tipo', 20)->default('valor')->after('desconto_baixa');
            $table->decimal('desconto_baixa_percentual', 8, 4)->nullable()->after('desconto_baixa_tipo');
            $table->string('desconto_baixa_motivo', 255)->nullable()->after('desconto_baixa_percentual');
            $table->unsignedBigInteger('desconto_baixa_concedido_por')->nullable()->after('desconto_baixa_motivo');
            $table->dateTime('desconto_baixa_concedido_em')->nullable()->after('desconto_baixa_concedido_por');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('os') || ! Schema::hasColumn('os', 'desconto_baixa')) {
            return;
        }

        Schema::table('os', function (Blueprint $table): void {
            $table->dropColumn([
                'desconto_baixa',
                'desconto_baixa_tipo',
                'desconto_baixa_percentual',
                'desconto_baixa_motivo',
                'desconto_baixa_concedido_por',
                'desconto_baixa_concedido_em',
            ]);
        });
    }
};
