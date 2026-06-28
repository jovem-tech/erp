<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactInbox extends Model
{
    protected $connection = 'chat';

    protected $table = 'contatos_caixas_entrada';

    protected $guarded = [];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'conta_id', 'id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contato_id', 'id');
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class, 'caixa_entrada_id', 'id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'contato_caixa_entrada_id', 'id');
    }
}
