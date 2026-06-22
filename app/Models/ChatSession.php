<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ChatSession extends Model
{
    use HasCompanyTimezone;

    const STATUS_ACTIVE = 'active';
    const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'company_id',
        'inbox_chat_id',
        'lead_id',
        'channel',
        'channelable_type',
        'channelable_id',
        'started_at',
        'first_message_at',
        'ended_at',
        'status',
        'closing_reason_id',
        'closing_notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'first_message_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(InboxChat::class, 'inbox_chat_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Snapshot of the connected account/instance the session started on.
     */
    public function channelable(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class);
    }

    public function closingReason(): BelongsTo
    {
        return $this->belongsTo(ClosingReason::class);
    }

    public function getDurationSecondsAttribute(): int
    {
        $end = $this->ended_at ?? now();

        // Carbon 3 defaults diffInSeconds() to a signed value (negative when
        // called as later->diffInSeconds(earlier)) — abs() makes this safe
        // regardless of call order.
        return (int) abs($end->diffInSeconds($this->started_at));
    }
}
