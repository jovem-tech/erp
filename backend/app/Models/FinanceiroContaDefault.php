<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceiroContaDefault extends Model
{
    protected $table = 'financeiro_conta_defaults';

    protected $guarded = [];

    protected $casts = [
        'conta_financeira_id' => 'integer',
    ];

    public function conta(): BelongsTo
    {
        return $this->belongsTo(FinanceiroConta::class, 'conta_financeira_id');
    }
}
