<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxMessage extends Model
{
    use HasCompanyTimezone;

    // Message origin — how the message entered the CRM.
    public const SOURCE_CONTACT = 'contact';      // inbound from the customer
    public const SOURCE_USER = 'user';            // human agent via the CRM
    public const SOURCE_AI_AGENT = 'ai_agent';    // native AI agent
    public const SOURCE_AUTOMATION = 'automation'; // CRM system (welcome/closing/...)
    public const SOURCE_EXTERNAL = 'external';     // sent from the connected account outside the CRM

    /**
     * Delivery states, shared by both directions.
     *
     * Outbound: how far the message got towards the contact (WhatsApp and
     * Instagram report these back). Inbound: 'read' means a CRM user opened
     * the conversation. One column, one meaning — "the other side has seen
     * this" — rather than a separate flag per direction.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    public const DIRECTION_INCOMING = 'incoming';
    public const DIRECTION_OUTGOING = 'outgoing';

    protected $fillable = [
        'inbox_chat_id',
        'chat_session_id',
        'user_id',
        'ai_agent_id',
        'source',
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
        'transcription',
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

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiAgent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class);
    }

    public function replyToMessage(): BelongsTo
    {
        return $this->belongsTo(InboxMessage::class, 'reply_to_message_id');
    }
}
