<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionSecuritySetting extends Model
{
    protected $fillable = [
        'idle_timeout_minutes',
        'remember_me_enabled',
        'remember_me_lifetime_days',
        'warn_on_close',
    ];

    protected $casts = [
        'idle_timeout_minutes' => 'integer',
        'remember_me_enabled' => 'boolean',
        'remember_me_lifetime_days' => 'integer',
        'warn_on_close' => 'boolean',
    ];
}
