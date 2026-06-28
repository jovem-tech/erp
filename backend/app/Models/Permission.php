<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    protected $table = 'permissoes';

    public $timestamps = false;

    protected $guarded = [];

    public function groupPermissions(): HasMany
    {
        return $this->hasMany(GroupPermission::class, 'permissao_id', 'id');
    }
}
