<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $connection = 'chat';

    protected $table = 'mensagens';

    protected $guarded = [];

    protected $casts = [
        'content_attributes' => 'array',
    ];

    public const CONTENT_TYPE_TEXT = 'text';

    public const CONTENT_TYPE_IMAGE = 'image';

    public const CONTENT_TYPE_AUDIO = 'audio';

    public const CONTENT_TYPE_VIDEO = 'video';

    public const CONTENT_TYPE_DOCUMENT = 'document';

    public const CONTENT_TYPE_MIXED = 'mixed';

    public const CONTENT_TYPE_UNKNOWN = 'unknown';

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversa_id', 'id');
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class, 'caixa_entrada_id', 'id');
    }

    public function senderContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sender_id', 'id');
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id', 'id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'mensagem_id', 'id')->orderBy('id');
    }

    public function isIncoming(): bool
    {
        return $this->message_type === 'incoming';
    }
}
