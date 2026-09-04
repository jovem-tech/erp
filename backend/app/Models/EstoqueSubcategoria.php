<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstoqueSubcategoria extends Model
{
    protected $table = 'estoque_subcategorias';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'categoria_id' => 'integer',
        'ativo' => 'boolean',
        'ordem' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(EstoqueCategoria::class, 'categoria_id', 'id');
    }

    public function scopeAtiva(Builder $query): Builder
    {
        return $query->where('ativo', 1);
    }

    /**
     * @return array<int, array{id: int, nome: string, categoria_id: int}>
     */
    public static function activeOptions(?int $categoriaId = null): array
    {
        return self::query()
            ->ativa()
            ->when($categoriaId !== null, fn (Builder $query): Builder => $query->where('categoria_id', $categoriaId))
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get(['id', 'nome', 'categoria_id'])
            ->map(static fn (self $subcategoria): array => [
                'id' => (int) $subcategoria->id,
                'nome' => (string) $subcategoria->nome,
                'categoria_id' => (int) $subcategoria->categoria_id,
            ])
            ->values()
            ->all();
    }
}
