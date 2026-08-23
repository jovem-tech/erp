<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula uma conta financeira interna a uma conta bancaria da integracao.
 *
 * Aditiva e nullable: contas existentes seguem funcionando sem vinculo nenhum.
 *
 * `integracao_provider` guardado mesmo com um unico provedor possivel hoje —
 * sem ele, o dia em que existir um segundo (ou um segundo tenant) nao ha como
 * saber retroativamente de onde veio cada saldo conferido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financeiro_contas')) {
            return;
        }

        Schema::table('financeiro_contas', function (Blueprint $table): void {
            if (! Schema::hasColumn('financeiro_contas', 'integracao_provider')) {
                $table->string('integracao_provider', 30)->nullable()->after('instituicao');
            }

            if (! Schema::hasColumn('financeiro_contas', 'integracao_conta_ref')) {
                // Conta corrente no provedor. Quando a aplicacao do Inter tem
                // mais de uma conta vinculada, e' o que vai no x-conta-corrente.
                $table->string('integracao_conta_ref', 30)->nullable()->after('integracao_provider');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('financeiro_contas')) {
            return;
        }

        Schema::table('financeiro_contas', function (Blueprint $table): void {
            foreach (['integracao_conta_ref', 'integracao_provider'] as $coluna) {
                if (Schema::hasColumn('financeiro_contas', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
