<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Troca `orcamento_itens.nivel_minimo` (cascata: item a partir do nível N
 * aparece em N e em todos os níveis acima) por `orcamento_itens.niveis`
 * (conjunto explícito de níveis em que o item entra — sem cascata).
 *
 * A cascata quebrava quando um item de nível mais alto era uma ALTERNATIVA a
 * um item de nível mais baixo (ex.: RAM 2GB na Básica, RAM 4GB na Avançada,
 * RAM 8GB na Completa): a opção Completa somava as três em vez de usar só a
 * de 8GB. Com associação explícita, cada item marca só os níveis em que
 * realmente deve aparecer.
 *
 * Backfill preserva o comportamento de cascata que os itens já existentes
 * têm hoje (nivel_minimo=k vira niveis=[k..3]), então nenhum orçamento já
 * criado muda de comportamento por causa desta migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table): void {
            $table->json('niveis')->nullable()->after('nivel_minimo');
        });

        foreach ([1, 2, 3] as $nivelMinimo) {
            DB::table('orcamento_itens')
                ->where('nivel_minimo', $nivelMinimo)
                ->update(['niveis' => json_encode(range($nivelMinimo, 3))]);
        }

        DB::table('orcamento_itens')
            ->whereNull('niveis')
            ->update(['niveis' => json_encode([1, 2, 3])]);

        Schema::table('orcamento_itens', function (Blueprint $table): void {
            $table->dropColumn('nivel_minimo');
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table): void {
            $table->unsignedTinyInteger('nivel_minimo')->default(1)->after('ordem');
        });

        // Melhor esforço: o menor nível marcado no conjunto vira o início da
        // cascata (perde a informação de exclusividade entre níveis).
        DB::table('orcamento_itens')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $niveis = json_decode((string) $row->niveis, true);
                $nivelMinimo = is_array($niveis) && $niveis !== [] ? (int) min($niveis) : 1;

                DB::table('orcamento_itens')
                    ->where('id', $row->id)
                    ->update(['nivel_minimo' => max(1, min(3, $nivelMinimo))]);
            }
        });

        Schema::table('orcamento_itens', function (Blueprint $table): void {
            $table->dropColumn('niveis');
        });
    }
};
