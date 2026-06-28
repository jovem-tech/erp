<?php

namespace App\Models\Chat;

use App\Models\Legacy\LegacyClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $connection = 'chat';

    protected $table = 'contatos';

    protected $guarded = [];

    protected $casts = [
        'custom_attributes' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'conta_id', 'id');
    }

    public function contactInboxes(): HasMany
    {
        return $this->hasMany(ContactInbox::class, 'contato_id', 'id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'contato_id', 'id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(LegacyClient::class, 'cliente_id', 'id');
    }
}
