<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything a public booking page needs beyond the scheduling rules the
 * calendar phase already laid down.
 *
 * The earlier migration created this table with availability, buffers, limits
 * and branding in anticipation of exactly this feature; what it could not
 * anticipate was the page's own copy, the form it collects, and whether
 * availability is enforced at all. Those are added here rather than folded
 * into the existing `branding` JSON because they are queried and validated
 * individually, and a column that means one thing is easier to reason about
 * than a bag that means six.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('calendar_booking_settings', function (Blueprint $table) {
            // Public page copy. Falls back to the calendar's own name when null,
            // so a page is publishable before anyone writes marketing text.
            $table->string('title')->nullable()->after('is_published');
            $table->string('subtitle')->nullable()->after('title');
            $table->text('description')->nullable()->after('subtitle');

            /**
             * smart — offer only slots that do not collide with existing
             *         appointments on this calendar.
             * open  — offer every slot inside working hours and let bookings
             *         overlap. For businesses that triage by hand.
             *
             * Tasks never restrict availability in either mode: a task with a
             * due date is a reminder, not an occupied hour.
             */
            $table->string('availability_mode', 20)->default('smart')->after('description');

            // The grid slots are offered on (15/20/30/60). Distinct from
            // slot_duration_minutes: a 60-minute meeting can start every 30.
            $table->unsignedSmallInteger('slot_increment_minutes')->default(30)->after('slot_duration_minutes');

            // The booking form, same shape as the form widget's `fields`.
            $table->json('form_fields')->nullable()->after('booking_defaults');

            // Post-booking copy, shown on the confirmation step.
            $table->string('confirmation_title')->nullable()->after('form_fields');
            $table->text('confirmation_message')->nullable()->after('confirmation_title');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_booking_settings', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'subtitle',
                'description',
                'availability_mode',
                'slot_increment_minutes',
                'form_fields',
                'confirmation_title',
                'confirmation_message',
            ]);
        });
    }
};
