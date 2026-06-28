<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $table = 'modulos';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'ativo' => 'boolean',
        'ordem_menu' => 'integer',
    ];

    public function groupPermissions(): HasMany
    {
        return $this->hasMany(GroupPermission::class, 'modulo_id', 'id');
    }
}
