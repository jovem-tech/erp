<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador do `nDPS` por serie — specs/041-emissao-fiscal-nfse.
 *
 * A DPS e' numerada pelo CONTRIBUINTE, nao pelo fisco: serie + numero entram no
 * `Id` que vai assinado, e reenviar um par ja' usado e' recusado pelo Ambiente
 * Nacional. Ate' agora ninguem gerava esse numero porque ninguem transmitia —
 * `DpsXmlBuilder::gerarAssinado()` recebia o numero de quem chamasse, e so'
 * teste chamava.
 *
 * Tabela propria, e nao `MAX(numero_dps) + 1` sobre `documentos_fiscais`,
 * porque o maximo la' so' enxerga o que ESTE sistema emitiu. Quem ja' emitiu
 * pelo portal do gov.br antes de ligar a automacao tem numeros que o banco
 * nunca viu, e comecar do 1 colidiria com eles.
 *
 * ⚠️ Nasce em ZERO de proposito. O numero correto e' o que o Emissor Nacional
 * mostra como ultima DPS da serie, e isso varia por empresa — cravar aqui o
 * contador de uma delas quebraria a proxima instalacao. Ajuste antes de ligar
 * em producao com:
 *
 *     php artisan fiscal:sequencia-dps --serie=70000 --definir=4
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fiscal_sequencias')) {
            return;
        }

        Schema::create('fiscal_sequencias', function (Blueprint $table): void {
            $table->id();
            // 5 posicoes, como o `serie` da DPS.
            $table->string('serie', 5)->unique();
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_sequencias');
    }
};
