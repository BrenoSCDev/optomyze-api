<?php

namespace App\Models;

use App\Support\PhoneNormalizer;
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
    /** Live chat from a website chat widget (channelable = Widget). */
    const CHANNEL_WEBSITE = 'website';

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
        'is_favorite',
        'ai_agent_service',
        'last_message_at',
        'profile_pic_url',
    ];

    protected $casts = [
        'is_group' => 'boolean',
        'is_archived' => 'boolean',
        'is_favorite' => 'boolean',
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

    /**
     * Inbound messages nobody has opened the conversation to see yet.
     *
     * Read state lives on inbox_messages.status, the same enum that already
     * carries outbound delivery receipts — an inbound message is unread until
     * it reaches 'read'. Reusing it keeps one notion of "seen" per message
     * rather than one per direction.
     */
    public function unreadMessages(): HasMany
    {
        return $this->messages()
            ->where('direction', 'incoming')
            ->where('status', '!=', InboxMessage::STATUS_READ);
    }

    /** Adds `unread_count` without loading any messages. */
    public function scopeWithUnreadCount($query)
    {
        return $query->withCount(['messages as unread_count' => fn ($q) => $q
            ->where('direction', 'incoming')
            ->where('status', '!=', InboxMessage::STATUS_READ)]);
    }

    /**
     * Narrows to fully-read or has-unread conversations.
     * Anything other than 'unread'/'read' leaves the query untouched.
     */
    public function scopeReadState($query, ?string $state)
    {
        $hasUnread = fn ($q) => $q
            ->where('direction', 'incoming')
            ->where('status', '!=', InboxMessage::STATUS_READ);

        return match ($state) {
            'unread' => $query->whereHas('messages', $hasUnread),
            'read'   => $query->whereDoesntHave('messages', $hasUnread),
            default  => $query,
        };
    }

    /**
     * Marks every inbound message as read.
     *
     * Returns how many actually changed, so callers can skip broadcasting
     * when opening an already-read conversation — otherwise every click would
     * push a pointless update to everyone with the inbox open.
     */
    public function markInboundRead(): int
    {
        return $this->unreadMessages()->update(['status' => InboxMessage::STATUS_READ]);
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
     * Find the WhatsApp InboxChat for a phone number, matching across the
     * Brazilian 8-/9-digit format variants (see PhoneNormalizer::variants).
     *
     * WhatsApp delivers the remoteJid using whichever format the contact's
     * account is registered under (often the old 8-digit form), while a lead
     * created from a web form typically stores the new 9-digit form. An exact
     * string match on external_chat_id therefore misses the existing chat and
     * fabricates a duplicate. Matching across variants — mirroring
     * Lead::findByPhone — keeps inbound and outbound on a single chat.
     */
    public static function findWhatsApp(int $companyId, WhatsAppInstance $instance, string $phone): ?self
    {
        $digits = PhoneNormalizer::normalize($phone);
        if ($digits === '') {
            return null;
        }

        $jidVariants = array_map(
            fn (string $d) => $d . '@s.whatsapp.net',
            PhoneNormalizer::variants($digits)
        );

        return static::where([
            'company_id'       => $companyId,
            'channel'          => self::CHANNEL_WHATSAPP,
            'channelable_type' => WhatsAppInstance::class,
            'channelable_id'   => $instance->id,
        ])
            ->whereIn('external_chat_id', $jidVariants)
            ->first();
    }

    /**
     * Find-or-create the WhatsApp InboxChat for a phone number. Existing chats
     * are matched across Brazilian format variants (see findWhatsApp); a new
     * chat, when needed, is created under the normalized number. $createValues
     * (lead_id, last_message_at, …) are applied only on creation.
     */
    public static function firstOrCreateWhatsApp(
        int $companyId,
        WhatsAppInstance $instance,
        string $phone,
        array $createValues = []
    ): self {
        $existing = self::findWhatsApp($companyId, $instance, $phone);
        if ($existing) {
            return $existing;
        }

        $digits = PhoneNormalizer::normalize($phone);

        return self::create(array_merge([
            'company_id'          => $companyId,
            'channel'             => self::CHANNEL_WHATSAPP,
            'channelable_type'    => WhatsAppInstance::class,
            'channelable_id'      => $instance->id,
            'external_chat_id'    => $digits . '@s.whatsapp.net',
            'external_contact_id' => $digits,
            'is_group'            => false,
        ], $createValues));
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
