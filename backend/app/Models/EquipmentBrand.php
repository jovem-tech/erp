<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentBrand extends Model
{
    protected $table = 'equipamentos_marcas';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'ativo' => 'boolean',
    ];

    public function models(): HasMany
    {
        return $this->hasMany(EquipmentModel::class, 'marca_id', 'id');
    }
}
