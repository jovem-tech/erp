<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrecificacaoCategoriaEncargo extends Model
{
    protected $table = 'precificacao_categoria_encargos';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'categoria_id' => 'integer',
        'percentual' => 'decimal:2',
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
    public function getAtivosPorCategoria(int $categoriaId): array
    {
        if (! $this->isTableReady() || $categoriaId <= 0) {
            return [];
        }

        return $this->query()
            ->where('categoria_id', $categoriaId)
            ->where('ativo', true)
            ->orderBy('ordem')
            ->orderBy('id')
            ->get()
            ->toArray();
    }
}
