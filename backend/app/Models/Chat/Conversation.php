<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $connection = 'chat';

    protected $table = 'conversas';

    protected $guarded = [];

    protected $casts = [
        'custom_attributes' => 'array',
        'last_activity_at' => 'datetime',
    ];

    public const STATUS_LABELS = [
        'open' => 'Aberta',
        'resolved' => 'Resolvida',
        'pending' => 'Pendente',
        'snoozed' => 'Em espera',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'conta_id', 'id');
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class, 'caixa_entrada_id', 'id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contato_id', 'id');
    }

    public function contactInbox(): BelongsTo
    {
        return $this->belongsTo(ContactInbox::class, 'contato_caixa_entrada_id', 'id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversa_id', 'id')->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversa_id', 'id')->latestOfMany('id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
