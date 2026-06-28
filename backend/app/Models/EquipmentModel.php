<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentModel extends Model
{
    protected $table = 'equipamentos_modelos';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'marca_id' => 'integer',
        'ativo' => 'boolean',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(EquipmentBrand::class, 'marca_id', 'id');
    }
}
