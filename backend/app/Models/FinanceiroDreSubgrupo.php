<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceiroDreSubgrupo extends Model
{
    protected $table = 'financeiro_dre_subgrupos';

    protected $guarded = [];

    protected $casts = [
        'grupo_id' => 'integer',
        'ordem_exibicao' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(FinanceiroDreGrupo::class, 'grupo_id', 'id');
    }
}
