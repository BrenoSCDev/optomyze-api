<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing company a default calendar and move its scheduling data
 * onto it.
 *
 * IDEMPOTENT BY CONSTRUCTION. Re-running this must not produce a second
 * default calendar, so nothing here inserts blindly:
 *
 *   - the insert skips any company that already has a default calendar
 *   - the two updates only touch rows where calendar_id is still null, so a
 *     record someone has since moved to another calendar is left alone
 *
 * Written in raw SQL rather than through Eloquent so the Company model's
 * `created` hook (which creates a calendar for *new* companies) cannot fire
 * here and race this backfill, and so it does not break if the models change
 * shape later.
 *
 * Nothing is deleted or overwritten: existing appointments and tasks keep
 * their owner, timestamps and relationships and simply gain a calendar.
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();

        // Soft-deleted companies are included: their data still exists, and a
        // restored company should not come back missing its calendar.
        $companies = DB::table('companies')
            ->leftJoin('calendars', function ($join) {
                $join->on('calendars.company_id', '=', 'companies.id')
                    ->where('calendars.is_default', '=', true);
            })
            ->whereNull('calendars.id')
            ->select('companies.id', 'companies.name', 'companies.timezone')
            ->get();

        foreach ($companies->chunk(200) as $chunk) {
            DB::table('calendars')->insert(
                $chunk->map(fn ($company) => [
                    'name'        => 'Company Calendar',
                    'description' => 'The default calendar for ' . $company->name . '.',
                    'company_id'  => $company->id,
                    'color'       => '#3B82F6',
                    'type'        => 'company',
                    'visibility'  => 'company',
                    'timezone'    => $company->timezone,
                    'is_default'  => true,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ])->all()
            );
        }

        // Map each company to its default calendar in one pass, then file the
        // unfiled records. `whereNull` is what makes the reruns harmless.
        $defaults = DB::table('calendars')
            ->where('is_default', true)
            ->pluck('id', 'company_id');

        foreach ($defaults as $companyId => $calendarId) {
            DB::table('appointments')
                ->where('company_id', $companyId)
                ->whereNull('calendar_id')
                ->update(['calendar_id' => $calendarId]);

            DB::table('tasks')
                ->where('company_id', $companyId)
                ->whereNull('calendar_id')
                ->update(['calendar_id' => $calendarId]);
        }
    }

    public function down(): void
    {
        // Unfile the records first so the foreign keys let go, then drop only
        // the calendars this migration could have created. A calendar someone
        // has since created by hand is left alone.
        DB::table('appointments')->update(['calendar_id' => null]);
        DB::table('tasks')->update(['calendar_id' => null]);

        DB::table('calendars')->where('is_default', true)->delete();
    }
};
