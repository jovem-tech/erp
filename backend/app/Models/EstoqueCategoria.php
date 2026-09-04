<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstoqueCategoria extends Model
{
    protected $table = 'estoque_categorias';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'tipo_equipamento_id' => 'integer',
        'ativo' => 'boolean',
        'ordem' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tipoEquipamento(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'tipo_equipamento_id', 'id');
    }

    public function subcategorias(): HasMany
    {
        return $this->hasMany(EstoqueSubcategoria::class, 'categoria_id');
    }

    public function scopeAtiva(Builder $query): Builder
    {
        return $query->where('ativo', 1);
    }

    /**
     * @return array<int, array{id: int, nome: string, tipo_equipamento_id: int}>
     */
    public static function activeOptions(?int $tipoEquipamentoId = null): array
    {
        return self::query()
            ->ativa()
            ->when($tipoEquipamentoId !== null, fn (Builder $query): Builder => $query->where('tipo_equipamento_id', $tipoEquipamentoId))
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get(['id', 'nome', 'tipo_equipamento_id'])
            ->map(static fn (self $categoria): array => [
                'id' => (int) $categoria->id,
                'nome' => (string) $categoria->nome,
                'tipo_equipamento_id' => (int) $categoria->tipo_equipamento_id,
            ])
            ->values()
            ->all();
    }
}
