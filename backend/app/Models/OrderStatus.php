<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    protected $table = 'os_status';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'ordem_fluxo' => 'integer',
        'status_final' => 'boolean',
        'status_pausa' => 'boolean',
        'gera_evento_crm' => 'boolean',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function activeCodes(): array
    {
        return static::query()
            ->where('ativo', 1)
            ->orderBy('ordem_fluxo')
            ->pluck('codigo')
            ->map(static fn ($code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->values()
            ->all();
    }

    public static function activeByCode(string $code): ?self
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return static::query()
            ->where('codigo', $code)
            ->where('ativo', 1)
            ->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ativo', 1);
    }
}
