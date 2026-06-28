<?php

namespace App\Models\Chat;

use App\Support\Channels\ChannelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inbox extends Model
{
    protected $connection = 'chat';

    protected $table = 'caixas_entrada';

    protected $guarded = [];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'conta_id', 'id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'caixa_entrada_id', 'id');
    }

    public function contactInboxes(): HasMany
    {
        return $this->hasMany(ContactInbox::class, 'caixa_entrada_id', 'id');
    }

    /**
     * Resolve o model concreto do canal (ex.: Channel\Whatsapp) via o registry,
     * em vez de um morphTo() sobre nome de classe cru.
     */
    public function channel(): ?Model
    {
        return app(ChannelRegistry::class)->resolve($this->channel_type, $this->channel_id);
    }
}
