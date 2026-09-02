<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos fiscais de cliente e servico — specs/041-emissao-fiscal-nfse/spec.md.
 *
 * Mesma decisao (e mesmo formato) da `027`, que ja' preparou `pecas` com NCM,
 * CEST, CFOP e CSOSN declarando que nasciam "sem uso". Aqui vem o lado que
 * ficou de fora: o servico, que e' 79% do faturamento, e o codigo IBGE do
 * municipio, que a NFS-e exige tanto do prestador quanto do tomador.
 *
 * Tudo nullable de proposito. Nenhuma coluna vira obrigatoria: a cobranca de
 * preenchimento e' do relatorio de prontidao (`fiscal:prontidao`), nao do
 * schema — sao 1.323 clientes ja' cadastrados que passariam a violar a
 * restricao no mesmo instante.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clientes')) {
            $this->addMissing('clientes', [
                // Codigo IBGE do municipio: a NFS-e identifica cidade por ele,
                // nao pelo nome. `cidade`/`uf` continuam sendo o que o operador
                // digita; este e' o que vai no XML.
                'codigo_ibge_municipio' => static fn (Blueprint $table) => $table->string('codigo_ibge_municipio', 7)->nullable(),
            ]);
        }

        if (Schema::hasTable('servicos')) {
            $this->addMissing('servicos', [
                'codigo_tributacao_nacional' => static fn (Blueprint $table) => $table->string('codigo_tributacao_nacional', 20)->nullable(),
                'item_lc116' => static fn (Blueprint $table) => $table->string('item_lc116', 10)->nullable(),
                'aliquota_iss' => static fn (Blueprint $table) => $table->decimal('aliquota_iss', 5, 2)->nullable(),
                'unidade' => static fn (Blueprint $table) => $table->string('unidade', 6)->nullable(),
            ]);
        }
    }

    public function down(): void
    {
        $this->dropExisting('clientes', ['codigo_ibge_municipio']);
        $this->dropExisting('servicos', [
            'codigo_tributacao_nacional',
            'item_lc116',
            'aliquota_iss',
            'unidade',
        ]);
    }

    /**
     * @param  array<string, callable(Blueprint): mixed>  $columns
     */
    private function addMissing(string $table, array $columns): void
    {
        $missing = array_filter(
            $columns,
            static fn (string $column): bool => ! Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );

        if ($missing === []) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($missing): void {
            foreach ($missing as $definition) {
                $definition($blueprint);
            }
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropExisting(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $existing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($existing): void {
            $blueprint->dropColumn($existing);
        });
    }
};
