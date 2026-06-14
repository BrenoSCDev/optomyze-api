<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxMessage extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'inbox_chat_id',
        'user_id',
        'external_message_id',
        'direction',
        'message_mode',
        'text',
        'type',
        'media_url',
        'media_mime',
        'media_size',
        'latitude',
        'longitude',
        'metadata',
        'reply_to_message_id',
        'reply_to_snapshot',
        'reactions',
        'is_edited',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'latitude'          => 'float',
        'longitude'         => 'float',
        'metadata'          => 'array',
        'reply_to_snapshot' => 'array',
        'reactions'         => 'array',
        'is_edited'         => 'boolean',
        'sent_at'           => 'datetime',
    ];

    public function getCompanyTimezone(): string
    {
        if ($this->relationLoaded('chat') && $this->chat) {
            return $this->chat->getCompanyTimezone();
        }

        return config('app.timezone');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(InboxChat::class, 'inbox_chat_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyToMessage(): BelongsTo
    {
        return $this->belongsTo(InboxMessage::class, 'reply_to_message_id');
    }
}
