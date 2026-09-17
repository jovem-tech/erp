<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamento em níveis de manutenção (Básica / Avançada / Completa).
 *
 * Não existem "3 orçamentos": a lista de itens continua única e cada item
 * diz a partir de qual nível ele entra (`nivel_minimo`, cumulativo). O nível
 * N é a projeção "itens com nivel_minimo <= N". Um orçamento cujos itens são
 * todos de nível 1 é um orçamento comum e se comporta exatamente como antes.
 *
 * Na aprovação o cliente escolhe o nível; os itens acima dele saem da lista
 * (a OS e o estoque leem os itens ao vivo, então o escopo contratado precisa
 * ser a própria lista) e o que foi oferecido fica guardado em
 * `orcamento_aprovacoes.niveis_snapshot`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table): void {
            $table->unsignedTinyInteger('nivel_minimo')->default(1)->after('ordem');
        });

        Schema::table('orcamentos', function (Blueprint $table): void {
            $table->unsignedTinyInteger('nivel_recomendado')->nullable()->after('parcelas_sem_juros');
            $table->unsignedTinyInteger('nivel_aprovado')->nullable()->after('nivel_recomendado');
        });

        Schema::table('orcamento_aprovacoes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('nivel')->nullable()->after('resposta_cliente');
            $table->json('niveis_snapshot')->nullable()->after('nivel');
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_aprovacoes', function (Blueprint $table): void {
            $table->dropColumn(['nivel', 'niveis_snapshot']);
        });

        Schema::table('orcamentos', function (Blueprint $table): void {
            $table->dropColumn(['nivel_recomendado', 'nivel_aprovado']);
        });

        Schema::table('orcamento_itens', function (Blueprint $table): void {
            $table->dropColumn('nivel_minimo');
        });
    }
};
