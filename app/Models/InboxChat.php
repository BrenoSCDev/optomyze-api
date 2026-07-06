<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InboxChat extends Model
{
    use HasCompanyTimezone;

    /**
     * Supported messaging channels.
     */
    const CHANNEL_WHATSAPP = 'whatsapp';
    const CHANNEL_INSTAGRAM = 'instagram';
    const CHANNEL_MESSENGER = 'messenger';
    const CHANNEL_SMS = 'sms';

    protected $fillable = [
        'company_id',
        'channel',
        'channelable_type',
        'channelable_id',
        'lead_id',
        'external_chat_id',
        'external_contact_id',
        'contact_name',
        'is_group',
        'is_archived',
        'ai_agent_service',
        'last_message_at',
        'profile_pic_url',
    ];

    protected $casts = [
        'is_group' => 'boolean',
        'is_archived' => 'boolean',
        'ai_agent_service' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    protected $appends = [
        'instance',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The channel-specific instance/integration this chat belongs to
     * (e.g. a WhatsAppInstance for the 'whatsapp' channel).
     */
    public function channelable(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class);
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class, 'inbox_chat_id');
    }

    public function activeChatSession(): HasOne
    {
        return $this->hasOne(ChatSession::class, 'inbox_chat_id')
            ->where('status', ChatSession::STATUS_ACTIVE)
            ->latestOfMany();
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(InboxMessage::class)->latestOfMany('sent_at');
    }

    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Backward-compatible accessor exposing the channelable as `instance`
     * for the WhatsApp channel, matching the shape the frontend already
     * expects from the legacy whatsapp_evo_chats `instance` relation.
     */
    public function getInstanceAttribute()
    {
        if ($this->channel === self::CHANNEL_WHATSAPP) {
            return $this->channelable;
        }

        return null;
    }

    /**
     * The AI agent bound to this chat's channel account, if any.
     */
    public function boundAiAgent(): ?AiAgent
    {
        if (!$this->channelable_type || !$this->channelable_id) {
            return null;
        }

        $binding = AiAgentChannelBinding::where('channelable_type', $this->channelable_type)
            ->where('channelable_id', $this->channelable_id)
            ->where('company_id', $this->company_id)
            ->first();

        return $binding?->agent;
    }
}
