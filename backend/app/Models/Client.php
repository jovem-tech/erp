<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'cliente_id', 'id');
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class, 'cliente_id', 'id');
    }
}
