<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $connection = 'chat';

    protected $table = 'contas_atendimento';

    protected $guarded = [];

    protected $casts = [
        'proximo_display_id' => 'integer',
    ];

    public function inboxes(): HasMany
    {
        return $this->hasMany(Inbox::class, 'conta_id', 'id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'conta_id', 'id');
    }

    /**
     * Reserva o proximo display_id sequencial desta conta.
     * MUST ser chamado dentro de uma transacao com lockForUpdate() na mesma linha
     * (ver ConversationFactory/IncomingMessageService).
     */
    public function reserveNextDisplayId(): int
    {
        $displayId = (int) $this->proximo_display_id;
        $this->proximo_display_id = $displayId + 1;
        $this->save();

        return $displayId;
    }
}
