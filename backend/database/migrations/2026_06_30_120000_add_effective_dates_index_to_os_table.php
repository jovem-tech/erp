<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O dashboard filtra/agrupa OS por COALESCE(data_abertura, data_entrada,
 * status_atualizado_em, updated_at, created_at) (e o equivalente para a data
 * de entrega). Por envolver COALESCE/MONTH/YEAR, o MySQL/MariaDB nao
 * consegue usar nenhum indice existente para essas consultas. Colunas
 * geradas e armazenadas (STORED) replicam o mesmo calculo em tempo de
 * escrita e podem ser indexadas normalmente.
 *
 * A tabela `os` e legada (compartilhada com o sistema-hml) e nao e criada
 * por nenhuma migration deste repositorio - so existe de fato no MySQL local
 * e em producao. Por isso o guard abaixo: em ambiente de teste (sqlite,
 * schema montado por tests/Concerns/BuildsLegacyErpSchema.php) a tabela
 * ainda nao existe quando as migrations rodam, e esta migration vira no-op.
 * As mesmas colunas geradas sao replicadas manualmente nesse trait de teste
 * para manter paridade de comportamento com a query do DashboardSummaryService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('os')) {
            return;
        }

        if (! Schema::hasColumn('os', 'data_abertura_efetiva')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->dateTime('data_abertura_efetiva')
                    ->storedAs('COALESCE(data_abertura, data_entrada, status_atualizado_em, updated_at, created_at)')
                    ->nullable()
                    ->after('data_abertura');
            });
        }

        if (! Schema::hasColumn('os', 'data_entrega_efetiva')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->dateTime('data_entrega_efetiva')
                    ->storedAs('COALESCE(data_entrega, data_conclusao, status_atualizado_em, updated_at, created_at)')
                    ->nullable()
                    ->after('data_entrega');
            });
        }

        if (! $this->hasIndex('os', 'idx_os_data_abertura_efetiva')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->index('data_abertura_efetiva', 'idx_os_data_abertura_efetiva');
            });
        }

        if (! $this->hasIndex('os', 'idx_os_data_entrega_efetiva')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->index('data_entrega_efetiva', 'idx_os_data_entrega_efetiva');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('os')) {
            return;
        }

        if ($this->hasIndex('os', 'idx_os_data_abertura_efetiva')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->dropIndex('idx_os_data_abertura_efetiva');
            });
        }

        if ($this->hasIndex('os', 'idx_os_data_entrega_efetiva')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->dropIndex('idx_os_data_entrega_efetiva');
            });
        }

        Schema::table('os', function (Blueprint $table): void {
            if (Schema::hasColumn('os', 'data_abertura_efetiva')) {
                $table->dropColumn('data_abertura_efetiva');
            }

            if (Schema::hasColumn('os', 'data_entrega_efetiva')) {
                $table->dropColumn('data_entrega_efetiva');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $rows = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$indexName]);

        return $rows !== [];
    }
};
