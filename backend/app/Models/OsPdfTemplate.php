<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OsPdfTemplate extends Model
{
    protected $table = 'os_pdf_templates';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'ordem' => 'integer',
        'ativo' => 'boolean',
    ];
}
