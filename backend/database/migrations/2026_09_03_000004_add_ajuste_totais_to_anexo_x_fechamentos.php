<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumo do ajuste manual nas colunas do fechamento.
 *
 * O `payload_json` do fechamento já congela os ajustes por inteiro — estas duas
 * colunas existem pelo MESMO motivo que a migration `000001` deu para as dez
 * linhas: a tabela do ano precisa marcar "este mês carrega ajuste" em 24
 * células (12 meses × 2 regimes) sem desserializar 24 payloads a cada carga de
 * tela.
 *
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anexo_x_fechamentos')) {
            return;
        }

        Schema::table('anexo_x_fechamentos', function (Blueprint $table): void {
            if (! Schema::hasColumn('anexo_x_fechamentos', 'ajuste_total')) {
                $table->decimal('ajuste_total', 14, 2)->default(0)->after('deducao_devolucoes');
            }

            if (! Schema::hasColumn('anexo_x_fechamentos', 'ajuste_quantidade')) {
                $table->unsignedInteger('ajuste_quantidade')->default(0)->after('ajuste_total');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('anexo_x_fechamentos')) {
            return;
        }

        Schema::table('anexo_x_fechamentos', function (Blueprint $table): void {
            foreach (['ajuste_total', 'ajuste_quantidade'] as $coluna) {
                if (Schema::hasColumn('anexo_x_fechamentos', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
