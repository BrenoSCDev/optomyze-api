<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PER-CALENDAR EXTERNAL SYNC AND PUBLIC BOOKING
 *
 * Neither feature ships in this phase. Both tables exist now because the shape
 * of the data is what dictates whether they can be added later without moving
 * anything: synchronisation is configured *per calendar*, never per company,
 * and a booking page belongs to one calendar.
 *
 * `calendar_integrations` is a table rather than columns on `calendars`
 * because one calendar may end up connected to more than one provider (read
 * from Google, publish to Outlook), and because credentials should be easy to
 * revoke by deleting a row.
 *
 * `calendar_booking_settings` is 1:1 with a calendar and separate for the
 * opposite reason: it is a wide block of configuration that only matters once
 * someone actually publishes a booking page.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            // Denormalised on purpose: every tenant-scoped query filters on it,
            // and it saves a join on the sync worker's hot path.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // google | outlook | calendly | apple | ics | ...
            $table->string('provider', 40);

            // The calendar's id on the provider's side, plus whatever else that
            // provider needs (account email, resource id, webhook channel).
            $table->string('external_calendar_id')->nullable();
            $table->json('provider_config')->nullable();

            // Tokens live in their own column so they can be encrypted at the
            // model layer and never leak into a config dump.
            $table->text('credentials')->nullable();

            // inbound | outbound | two_way
            $table->string('sync_direction', 20)->default('two_way');

            // idle | syncing | error | disconnected
            $table->string('sync_status', 20)->default('idle');
            $table->text('sync_error')->nullable();

            // Provider cursor for incremental sync (Google syncToken, Outlook
            // deltaLink). Opaque to us.
            $table->string('sync_cursor')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One connection per provider per calendar.
            $table->unique(['calendar_id', 'provider']);
            $table->index(['company_id', 'sync_status']);
        });

        Schema::create('calendar_booking_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // The public URL segment. Unique across the install because booking
            // pages are served without a tenant in the path.
            $table->string('public_slug')->nullable()->unique();
            $table->boolean('is_published')->default(false);

            // Weekly availability: { mon: [{start, end}], ... }. JSON because
            // the shape is a schedule, not something to query across rows.
            $table->json('working_hours')->nullable();
            // One-off overrides: holidays, blackout days.
            $table->json('date_overrides')->nullable();

            $table->unsignedSmallInteger('slot_duration_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            // How far ahead someone may book, and the shortest notice accepted.
            $table->unsignedSmallInteger('min_notice_minutes')->default(60);
            $table->unsignedSmallInteger('max_days_in_advance')->default(60);
            $table->unsignedSmallInteger('max_bookings_per_day')->nullable();

            // manual | automatic — whether a booking is confirmed on the spot.
            $table->string('confirmation_mode', 20)->default('automatic');
            $table->boolean('requires_phone')->default(false);
            $table->string('timezone', 100)->nullable();

            // Logo, colours, intro copy for the public page.
            $table->json('branding')->nullable();
            // Where a booking lands: appointment type, funnel, assignee.
            $table->json('booking_defaults')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_booking_settings');
        Schema::dropIfExists('calendar_integrations');
    }
};
