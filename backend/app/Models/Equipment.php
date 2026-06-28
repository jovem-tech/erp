<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    protected $table = 'equipamentos';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'cliente_id' => 'integer',
        'tipo_id' => 'integer',
        'marca_id' => 'integer',
        'modelo_id' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id', 'id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'tipo_id', 'id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(EquipmentBrand::class, 'marca_id', 'id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(EquipmentModel::class, 'modelo_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'equipamento_id', 'id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(EquipmentPhoto::class, 'equipamento_id', 'id');
    }
}
