<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipamento orçado antes de chegar à assistência.
 *
 * O cliente pede orçamento com o aparelho ainda em casa: dá para registrar
 * tipo/marca/modelo, mas não a foto de perfil (StoreEquipmentRequest exige
 * `fotos` justamente porque, no balcão, o aparelho está na mão do atendente).
 * Esta coluna marca esse cadastro como incompleto para que a OS do aparelho
 * não possa ser salva antes de alguém completá-lo — a foto é tirada quando o
 * cliente traz o equipamento.
 *
 * Default 0: cadastro nascido pelo fluxo normal (e todo o legado) continua
 * completo, sem varredura de dados.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `equipamentos` e' tabela LEGADA: nos testes ela e' reconstruida DEPOIS
        // das migrations, entao aqui ela ainda nao existe. `hasColumn` numa
        // tabela inexistente devolve false, e sem o `hasTable` o ALTER abaixo
        // rodava assim mesmo e derrubava a suite inteira.
        if (! Schema::hasTable('equipamentos') || Schema::hasColumn('equipamentos', 'cadastro_pendente')) {
            return;
        }

        Schema::table('equipamentos', function (Blueprint $table): void {
            $table->boolean('cadastro_pendente')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('equipamentos') || ! Schema::hasColumn('equipamentos', 'cadastro_pendente')) {
            return;
        }

        Schema::table('equipamentos', function (Blueprint $table): void {
            $table->dropColumn('cadastro_pendente');
        });
    }
};
