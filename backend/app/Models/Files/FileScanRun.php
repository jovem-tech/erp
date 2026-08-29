<?php

namespace App\Models\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FileScanRun extends Model
{
    protected $table = 'file_scan_runs';

    protected $guarded = [];

    protected $casts = [
        'checkpoint_json' => 'array',
        'processed_count' => 'integer',
        'skipped_count' => 'integer',
        'finding_count' => 'integer',
        'failed_count' => 'integer',
        'started_by' => 'integer',
        'started_at' => 'immutable_datetime',
        'heartbeat_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function findings(): HasMany
    {
        return $this->hasMany(FileScanFinding::class, 'scan_run_id');
    }

    /**
     * Uma execucao que terminou bem sem processar nada, sem achado e sem falha
     * nao e' historico: e' ruido.
     *
     * O agendador dispara `file-manager:sync --pending` a cada minuto e
     * `file-manager:sync` a cada 5, cada um gravando uma linha POR ROOT. Medido
     * neste banco: 11.232 linhas por dia — uma a cada 7,7 segundos, 24h por dia
     * —, das quais 186.474 eram `catalog_legacy` com media de 0,0 arquivos
     * processados. A tabela chegou a 383 mil linhas e 373 MB, ocupando 35% do
     * innodb_buffer_pool_size de 1 GB e expulsando do cache as paginas quentes
     * de `os` e `clientes`.
     *
     * Descartar no fim (em vez de criar sob demanda) mantem o `id` disponivel
     * durante a varredura, que os achados precisam para a FK — e por isso a
     * condicao exige finding_count = 0: apagar um run COM achados levaria os
     * achados junto no cascade.
     */
    public function discardIfUneventful(): bool
    {
        $uneventful = (string) $this->status === 'completed'
            && (int) $this->processed_count === 0
            && (int) $this->finding_count === 0
            && (int) $this->failed_count === 0
            && (int) $this->skipped_count === 0
            && $this->findings()->doesntExist();

        if (! $uneventful) {
            return false;
        }

        $this->delete();

        return true;
    }
}
