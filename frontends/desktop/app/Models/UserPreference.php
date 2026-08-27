<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = ['api_user_id', 'desktop_theme', 'navigation_mode', 'favorites'];

    protected $casts = [
        'api_user_id' => 'integer',
        'favorites' => 'array',
    ];
}
