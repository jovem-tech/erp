<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrecificacaoServicoOverride extends Model
{
    protected $table = 'precificacao_servico_overrides';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'servico_id' => 'integer',
        'custo_hora_produtiva' => 'decimal:4',
        'custos_diretos_total' => 'decimal:4',
        'margem_percentual' => 'decimal:4',
        'taxa_recebimento_percentual' => 'decimal:4',
        'imposto_percentual' => 'decimal:4',
        'tempo_tecnico_horas' => 'decimal:4',
        'risco_percentual' => 'decimal:4',
        'preco_tabela_referencia' => 'decimal:4',
        'custos_fixos_mensais' => 'decimal:4',
        'tecnicos_ativos' => 'decimal:4',
        'horas_produtivas_dia' => 'decimal:4',
        'dias_uteis_mes' => 'decimal:4',
        'consumiveis_valor' => 'decimal:4',
        'tempo_indireto_horas' => 'decimal:4',
        'reserva_garantia_valor' => 'decimal:4',
        'perdas_pequenas_valor' => 'decimal:4',
        'tempo_desmontagem_min' => 'decimal:4',
        'tempo_substituicao_min' => 'decimal:4',
        'tempo_montagem_min' => 'decimal:4',
        'tempo_teste_final_min' => 'decimal:4',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isTableReady(): bool
    {
        try {
            return $this->getConnection()->getSchemaBuilder()->hasTable($this->table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAtivos(): array
    {
        if (! $this->isTableReady()) {
            return [];
        }

        return $this->query()
            ->where('ativo', true)
            ->orderBy('servico_id')
            ->get()
            ->toArray();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getMapaPorServicoId(): array
    {
        $map = [];

        foreach ($this->getAtivos() as $row) {
            $servicoId = (int) ($row['servico_id'] ?? 0);
            if ($servicoId > 0) {
                $map[(string) $servicoId] = $row;
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAtivoByServicoId(int $servicoId): ?array
    {
        if ($servicoId <= 0 || ! $this->isTableReady()) {
            return null;
        }

        $row = $this->query()
            ->where('servico_id', $servicoId)
            ->where('ativo', true)
            ->first();

        return is_array($row) ? $row : null;
    }
}
