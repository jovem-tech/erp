<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = ['api_user_id', 'desktop_theme'];

    protected $casts = [
        'api_user_id' => 'integer',
    ];
}
