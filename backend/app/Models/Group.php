<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $table = 'grupos';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sistema' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'grupo_id', 'id');
    }

    public function groupPermissions(): HasMany
    {
        return $this->hasMany(GroupPermission::class, 'grupo_id', 'id');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'grupo_permissoes', 'grupo_id', 'modulo_id')
            ->withPivot('permissao_id')
            ->withTimestamps(false);
    }
}
