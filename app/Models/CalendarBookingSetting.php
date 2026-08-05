<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Public booking configuration for one calendar.
 *
 * Booking pages are not implemented in this phase. The row is what a future
 * public scheduler reads to work out which slots to offer: the weekly
 * availability, how long a slot is, how much padding sits either side, how far
 * ahead someone may book, and what happens when they do.
 *
 * 1:1 with a calendar and separate from it because it only exists once someone
 * publishes a page — most calendars will never have a row here.
 */
class CalendarBookingSetting extends Model
{
    public const CONFIRMATION_MODES = ['automatic', 'manual'];

    /**
     * smart — only slots free of existing appointments on this calendar
     * open  — every slot inside working hours; overlaps allowed on purpose
     */
    public const AVAILABILITY_MODES = ['smart', 'open'];

    protected $fillable = [
        'calendar_id',
        'company_id',
        'public_slug',
        'is_published',
        'title',
        'subtitle',
        'description',
        'availability_mode',
        'slot_increment_minutes',
        'form_fields',
        'confirmation_title',
        'confirmation_message',
        'working_hours',
        'date_overrides',
        'slot_duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'min_notice_minutes',
        'max_days_in_advance',
        'max_bookings_per_day',
        'confirmation_mode',
        'requires_phone',
        'timezone',
        'branding',
        'booking_defaults',
    ];

    protected $casts = [
        'is_published'     => 'boolean',
        'form_fields'      => 'array',
        'requires_phone'   => 'boolean',
        'working_hours'    => 'array',
        'date_overrides'   => 'array',
        'branding'         => 'array',
        'booking_defaults' => 'array',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
