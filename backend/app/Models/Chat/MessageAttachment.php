<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    protected $connection = 'chat';

    protected $table = 'mensagem_anexos';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public const TYPE_IMAGE = 'image';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_VIDEO = 'video';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_UNKNOWN = 'unknown';

    public const TRANSFER_AVAILABLE = 'available';

    public const TRANSFER_FAILED = 'failed';

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'mensagem_id', 'id');
    }
}
