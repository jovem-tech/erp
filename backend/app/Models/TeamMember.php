<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    protected $table = 'equipe_membros';

    protected $fillable = [
        'nome',
        'email',
        'telefone',
        'cargo',
        'usuario_id',
        'atua_tecnico',
        'atua_vendas',
        'atua_administrativo',
        'ativo',
        'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'usuario_id' => 'integer',
            'atua_tecnico' => 'boolean',
            'atua_vendas' => 'boolean',
            'atua_administrativo' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id', 'id');
    }

    public function scopeRole(Builder $query, string $role): Builder
    {
        return match (trim(mb_strtolower($role))) {
            'tecnico' => $query->where('atua_tecnico', true),
            'vendas', 'vendedor' => $query->where('atua_vendas', true),
            'administrativo', 'admin' => $query->where('atua_administrativo', true),
            default => $query,
        };
    }

    public function scopeAssignableOrders(Builder $query): Builder
    {
        return $query
            ->where('ativo', true)
            ->where('atua_tecnico', true)
            ->whereNotNull('usuario_id')
            ->whereHas('user', static fn (Builder $builder): Builder => $builder->where('ativo', true));
    }
}
