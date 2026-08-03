<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A calendar's connection to an external provider.
 *
 * Synchronisation is configured per calendar, never per company: a company may
 * well sync its sales calendar to Google while leaving an internal one alone.
 *
 * No sync runs yet. This model exists so the connection, its direction, its
 * cursor and its health all have a home the day a provider is implemented —
 * and so credentials have one encrypted column rather than being scattered.
 */
class CalendarIntegration extends Model
{
    public const PROVIDERS  = ['google', 'outlook', 'calendly', 'apple', 'ics'];
    public const DIRECTIONS = ['inbound', 'outbound', 'two_way'];
    public const STATUSES   = ['idle', 'syncing', 'error', 'disconnected'];

    protected $fillable = [
        'calendar_id',
        'company_id',
        'provider',
        'external_calendar_id',
        'provider_config',
        'credentials',
        'sync_direction',
        'sync_status',
        'sync_error',
        'sync_cursor',
        'token_expires_at',
        'last_synced_at',
        'is_active',
    ];

    protected $casts = [
        'provider_config'  => 'array',
        // Encrypted at rest: OAuth tokens must not be readable from a database
        // dump or a stray log line.
        'credentials'      => 'encrypted:array',
        'token_expires_at' => 'datetime',
        'last_synced_at'   => 'datetime',
        'is_active'        => 'boolean',
    ];

    /** Never serialise tokens to an API response. */
    protected $hidden = ['credentials'];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
