<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suporte do módulo de Vendas nas tabelas legadas — specs/027-vendas-balcao-pdv/spec.md.
 *
 * `movimentacoes` só conhece `os_id`, então sem `venda_id` toda saída de venda
 * apareceria na ficha da peça sem origem rastreável. `financeiro` ganha o mesmo
 * vínculo direto: `origem_tipo`/`origem_id` NÃO servem aqui porque, apesar do
 * nome, `origem_id` é um belongsTo(FinanceiroMovimento) — gravar o id da venda
 * ali carregaria um movimento alheio de mesmo id, em silêncio.
 *
 * Os campos fiscais de `pecas` nascem sem uso: preparam o terreno para a emissão
 * futura (fase 4) sem exigir ALTER numa tabela já grande.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('movimentacoes')) {
            $missingVendaId = ! Schema::hasColumn('movimentacoes', 'venda_id');
            $missingVendaItemId = ! Schema::hasColumn('movimentacoes', 'venda_item_id');

            if ($missingVendaId || $missingVendaItemId) {
                Schema::table('movimentacoes', function (Blueprint $table) use ($missingVendaId, $missingVendaItemId): void {
                    if ($missingVendaId) {
                        $table->unsignedBigInteger('venda_id')->nullable()->after('os_id');
                    }
                    if ($missingVendaItemId) {
                        $table->unsignedBigInteger('venda_item_id')->nullable()->after('venda_id');
                    }
                });
            }

            if (! $this->hasIndex('movimentacoes', 'idx_movimentacoes_venda')) {
                Schema::table('movimentacoes', function (Blueprint $table): void {
                    $table->index('venda_id', 'idx_movimentacoes_venda');
                });
            }
        }

        if (Schema::hasTable('financeiro')) {
            if (! Schema::hasColumn('financeiro', 'venda_id')) {
                Schema::table('financeiro', function (Blueprint $table): void {
                    $table->unsignedBigInteger('venda_id')->nullable()->after('os_id');
                });
            }

            if (! $this->hasIndex('financeiro', 'idx_financeiro_venda')) {
                Schema::table('financeiro', function (Blueprint $table): void {
                    $table->index('venda_id', 'idx_financeiro_venda');
                });
            }
        }

        if (Schema::hasTable('pecas')) {
            $columns = [
                // Operacionais: a busca do PDV já reconhece código de barras.
                'codigo_barras' => static fn (Blueprint $table) => $table->string('codigo_barras', 20)->nullable(),
                'unidade' => static fn (Blueprint $table) => $table->string('unidade', 6)->default('UN'),
                // Fiscais (preparação, sem uso no MVP).
                'ncm' => static fn (Blueprint $table) => $table->string('ncm', 8)->nullable(),
                'cest' => static fn (Blueprint $table) => $table->string('cest', 7)->nullable(),
                'cfop_venda' => static fn (Blueprint $table) => $table->string('cfop_venda', 4)->nullable(),
                'origem_mercadoria' => static fn (Blueprint $table) => $table->string('origem_mercadoria', 1)->nullable(),
                'cst_icms' => static fn (Blueprint $table) => $table->string('cst_icms', 3)->nullable(),
                'csosn' => static fn (Blueprint $table) => $table->string('csosn', 4)->nullable(),
                'unidade_tributavel' => static fn (Blueprint $table) => $table->string('unidade_tributavel', 6)->nullable(),
            ];

            $missing = array_filter(
                $columns,
                static fn (string $column): bool => ! Schema::hasColumn('pecas', $column),
                ARRAY_FILTER_USE_KEY
            );

            if ($missing !== []) {
                Schema::table('pecas', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $definition) {
                        $definition($table);
                    }
                });
            }

            if (! $this->hasIndex('pecas', 'idx_pecas_codigo_barras')) {
                Schema::table('pecas', function (Blueprint $table): void {
                    $table->index('codigo_barras', 'idx_pecas_codigo_barras');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pecas')) {
            if ($this->hasIndex('pecas', 'idx_pecas_codigo_barras')) {
                Schema::table('pecas', function (Blueprint $table): void {
                    $table->dropIndex('idx_pecas_codigo_barras');
                });
            }

            $this->dropColumnsIfPresent('pecas', [
                'codigo_barras', 'unidade', 'ncm', 'cest', 'cfop_venda',
                'origem_mercadoria', 'cst_icms', 'csosn', 'unidade_tributavel',
            ]);
        }

        if (Schema::hasTable('financeiro')) {
            if ($this->hasIndex('financeiro', 'idx_financeiro_venda')) {
                Schema::table('financeiro', function (Blueprint $table): void {
                    $table->dropIndex('idx_financeiro_venda');
                });
            }

            $this->dropColumnsIfPresent('financeiro', ['venda_id']);
        }

        if (Schema::hasTable('movimentacoes')) {
            if ($this->hasIndex('movimentacoes', 'idx_movimentacoes_venda')) {
                Schema::table('movimentacoes', function (Blueprint $table): void {
                    $table->dropIndex('idx_movimentacoes_venda');
                });
            }

            $this->dropColumnsIfPresent('movimentacoes', ['venda_id', 'venda_item_id']);
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumnsIfPresent(string $table, array $columns): void
    {
        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        if ($present === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($present): void {
            $blueprint->dropColumn($present);
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(static fn (object $index): bool => (string) ($index->name ?? '') === $indexName);
        }

        return DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName]) !== [];
    }
};
