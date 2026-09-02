<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos que o XML ja' entregava e o sistema jogava fora.
 *
 * O `NfseXmlImporter` lia competencia, numero da DPS, situacao (`cStat`) e o
 * valor apurado pelo Ambiente Nacional — e `registrarPorXml` gravava so'
 * numero, serie, chave e data. Consequencia pratica: se o valor emitido no
 * portal divergisse do valor da OS, ninguem via. A tela mostrava o numero
 * certo de uma nota com outro valor.
 *
 * `assinatura_conferida` guarda o veredito da conferencia da assinatura. E'
 * nullable de proposito: `null` e' "documento anterior a esta verificacao",
 * que nao e' a mesma coisa que "conferido e reprovado".
 *
 * `emitido_por` / `cancelado_por` fecham a trilha de auditoria. Cancelar
 * documento fiscal e' ato que precisa de autor: ate' aqui so' `criado_por`
 * existia, e o cancelamento — o ato mais grave desta tela — nao registrava
 * quem o fez.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documentos_fiscais')) {
            return;
        }

        Schema::table('documentos_fiscais', function (Blueprint $table): void {
            if (! Schema::hasColumn('documentos_fiscais', 'competencia')) {
                $table->date('competencia')->nullable()->after('emitido_em');
            }

            if (! Schema::hasColumn('documentos_fiscais', 'numero_dps')) {
                $table->string('numero_dps', 20)->nullable()->after('serie');
            }

            if (! Schema::hasColumn('documentos_fiscais', 'situacao_codigo')) {
                $table->string('situacao_codigo', 10)->nullable()->after('chave');
            }

            if (! Schema::hasColumn('documentos_fiscais', 'valor_xml')) {
                // O valor LIQUIDO que o Ambiente Nacional apurou, para poder
                // ser comparado com `valor_total`, que veio da OS.
                $table->decimal('valor_xml', 12, 2)->nullable()->after('valor_total');
            }

            if (! Schema::hasColumn('documentos_fiscais', 'assinatura_conferida')) {
                $table->boolean('assinatura_conferida')->nullable()->after('xml_tamanho_bytes');
            }

            if (! Schema::hasColumn('documentos_fiscais', 'emitido_por')) {
                $table->unsignedBigInteger('emitido_por')->nullable()->after('criado_por');
            }

            if (! Schema::hasColumn('documentos_fiscais', 'cancelado_por')) {
                $table->unsignedBigInteger('cancelado_por')->nullable()->after('emitido_por');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('documentos_fiscais')) {
            return;
        }

        Schema::table('documentos_fiscais', function (Blueprint $table): void {
            foreach ([
                'competencia',
                'numero_dps',
                'situacao_codigo',
                'valor_xml',
                'assinatura_conferida',
                'emitido_por',
                'cancelado_por',
            ] as $coluna) {
                if (Schema::hasColumn('documentos_fiscais', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
