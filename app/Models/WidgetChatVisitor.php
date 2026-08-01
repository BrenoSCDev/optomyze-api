<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A website-chat visitor. The browser holds `token` (secret); the CRM shows
 * `visitor_id` (public). Both are minted here so neither is guessable.
 */
class WidgetChatVisitor extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'widget_id',
        'company_id',
        'lead_id',
        'inbox_chat_id',
        'visitor_id',
        'token',
        'name',
        'email',
        'phone',
        'identified',
        'page_url',
        'ip_address',
        'user_agent',
        'last_seen_at',
    ];

    protected $casts = [
        'identified' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    protected $hidden = ['token'];

    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(InboxChat::class, 'inbox_chat_id');
    }

    public static function newVisitorId(): string
    {
        do {
            $visitorId = 'v_' . Str::lower(Str::random(20));
        } while (self::where('visitor_id', $visitorId)->exists());

        return $visitorId;
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    /** Display name for the CRM inbox before the visitor identifies. */
    public function displayName(): string
    {
        return $this->name ?: 'Website visitor';
    }
}
