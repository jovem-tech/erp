<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;

class LegacyClient extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
    ];

    public function getConnectionName(): ?string
    {
        return app()->environment('testing')
            ? config('database.default')
            : 'sistema_hml';
    }
}
