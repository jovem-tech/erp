<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceiroDreGrupo extends Model
{
    protected $table = 'financeiro_dre_grupos';

    protected $guarded = [];

    protected $casts = [
        'ordem_exibicao' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function subgrupos(): HasMany
    {
        return $this->hasMany(FinanceiroDreSubgrupo::class, 'grupo_id', 'id');
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(FinanceiroCategoria::class, 'dre_grupo_id', 'id');
    }
}
