<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * File any appointment still missing a calendar onto its company's default.
 *
 * 2026_08_03_000007 already did this once, but POST /v1/lead-appointments kept
 * creating appointments with a null calendar_id afterwards: it was the one
 * write path that never learned about calendars. Those rows exist and are
 * invisible in every calendar view, which filters by calendar_id.
 *
 * The controller now resolves a calendar on write, so this is a one-off sweep
 * of what it missed rather than a recurring repair.
 *
 * Idempotent for the same reason as the original backfill: only rows where
 * calendar_id is still null are touched, so an appointment somebody has since
 * moved to another calendar is left where they put it. Raw SQL keeps model
 * hooks out of it.
 */
return new class extends Migration {
    public function up(): void
    {
        $defaults = DB::table('calendars')
            ->where('is_default', true)
            ->pluck('id', 'company_id');

        foreach ($defaults as $companyId => $calendarId) {
            DB::table('appointments')
                ->where('company_id', $companyId)
                ->whereNull('calendar_id')
                ->update(['calendar_id' => $calendarId]);
        }
    }

    public function down(): void
    {
        // Deliberately empty. There is no way to tell which appointments this
        // migration filed from those the original backfill or the UI filed, and
        // unfiling all of them would undo more than this migration did.
    }
};
