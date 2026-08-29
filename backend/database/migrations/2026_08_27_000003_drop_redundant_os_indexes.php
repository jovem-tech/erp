<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove indices redundantes de `os`.
 *
 * A tabela tinha 20 indices para 3.642 linhas, com INDEX_LENGTH (2,6 MB) MAIOR
 * que DATA_LENGTH (1,5 MB). Cada INSERT/UPDATE de OS mantinha 20 arvores B-tree
 * — exatamente o caminho de escrita que a operacao intensa mais exercita.
 *
 * Os seis abaixo sao redundantes pela regra do prefixo mais a esquerda: toda
 * consulta que usaria o indice curto pode usar o longo, que ja existe. Isso e'
 * uma garantia ESTRUTURAL do InnoDB, nao uma inferencia sobre o trafego — por
 * isso a remocao nao depende de estatistica de uso (que, neste servidor, o
 * usuario da aplicacao nem tem permissao para ler em performance_schema).
 *
 * `down()` recria todos, entao a mudanca e' reversivel sem perda.
 */
return new class extends Migration
{
    /**
     * indice redundante => indice que ja o cobre
     *
     * @var array<string, string>
     */
    private const REDUNDANT = [
        'idx_numero' => 'numero_os (UNIQUE, mesma coluna)',
        'idx_status' => 'idx_os_status_data_abertura_id (status, ...)',
        'idx_os_status' => 'idx_os_status_data_abertura_id (status, ...)',
        'idx_tecnico' => 'idx_os_tecnico_data_abertura_id (tecnico_id, ...)',
        'idx_os_tecnico_id' => 'idx_os_tecnico_data_abertura_id (tecnico_id, ...)',
        'idx_os_data_abertura' => 'idx_os_data_abertura_id (data_abertura, id)',
        'idx_os_valor_final' => 'idx_os_valor_final_id (valor_final, id)',
        'idx_os_estado_fluxo' => 'idx_os_estado_fluxo_data_abertura_id (estado_fluxo, ...)',
    ];

    /**
     * @var array<string, string>
     */
    private const RECREATE = [
        'idx_numero' => 'numero_os',
        'idx_status' => 'status',
        'idx_os_status' => 'status',
        'idx_tecnico' => 'tecnico_id',
        'idx_os_tecnico_id' => 'tecnico_id',
        'idx_os_data_abertura' => 'data_abertura',
        'idx_os_valor_final' => 'valor_final',
        'idx_os_estado_fluxo' => 'estado_fluxo',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('os') || DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (array_keys(self::REDUNDANT) as $index) {
            if (! $this->indexExists($index)) {
                continue;
            }

            // O indice que cobre este precisa existir ANTES de soltar o curto —
            // sem essa guarda, um banco onde o composto nunca foi criado
            // terminaria a migration sem cobertura nenhuma para a coluna.
            if (! $this->coveringIndexExists($index)) {
                continue;
            }

            DB::statement('ALTER TABLE `os` DROP INDEX `'.$index.'`');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('os') || DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::RECREATE as $index => $column) {
            if ($this->indexExists($index)) {
                continue;
            }

            DB::statement('ALTER TABLE `os` ADD INDEX `'.$index.'` (`'.$column.'`)');
        }
    }

    private function indexExists(string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'os')
            ->where('index_name', $index)
            ->exists();
    }

    private function coveringIndexExists(string $index): bool
    {
        $column = self::RECREATE[$index] ?? null;

        if ($column === null) {
            return false;
        }

        // Existe outro indice cuja PRIMEIRA coluna e' a mesma? Entao ele cobre.
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'os')
            ->where('seq_in_index', 1)
            ->where('column_name', $column)
            ->where('index_name', '!=', $index)
            ->exists();
    }
};
