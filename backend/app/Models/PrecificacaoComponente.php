<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrecificacaoComponente extends Model
{
    protected $table = 'precificacao_componentes';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'valor' => 'decimal:4',
        'ativo' => 'boolean',
        'ordem' => 'integer',
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
    public function getAtivosPorGrupo(string $grupo, ?string $tipoValor = null): array
    {
        if (! $this->isTableReady()) {
            return [];
        }

        $query = $this->query()
            ->where('grupo', $grupo)
            ->where('ativo', true)
            ->orderBy('ordem')
            ->orderBy('id');

        if ($tipoValor !== null) {
            $query->where('tipo_valor', $tipoValor);
        }

        return $query->get()->toArray();
    }

    public function somarValorAtivoPorGrupo(string $grupo, ?string $tipoValor = null): float
    {
        if (! $this->isTableReady()) {
            return 0.0;
        }

        $query = $this->query()
            ->selectRaw('COALESCE(SUM(valor), 0) as total')
            ->where('grupo', $grupo)
            ->where('ativo', true);

        if ($tipoValor !== null) {
            $query->where('tipo_valor', $tipoValor);
        }

        $row = $query->first();

        return (float) ($row?->total ?? 0);
    }
}
