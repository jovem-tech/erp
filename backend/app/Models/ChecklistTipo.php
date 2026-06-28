<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistTipo extends Model
{
    protected $table = 'checklist_tipos';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'ativo' => 'boolean',
    ];
}
