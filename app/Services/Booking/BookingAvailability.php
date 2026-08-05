<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\CalendarBookingSetting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Which slots a public booking page may offer.
 *
 * The rules, in the order they narrow the day:
 *
 *   1. the weekday must appear in `working_hours`, and a `date_overrides`
 *      entry for that date wins over the weekly pattern
 *   2. slots start every `slot_increment_minutes` and last
 *      `slot_duration_minutes` — a 60-minute meeting can start every 30
 *   3. the whole meeting, plus its buffers, must fit inside the window
 *   4. it must be at least `min_notice_minutes` away and no further than
 *      `max_days_in_advance`
 *   5. the day must not already hold `max_bookings_per_day` bookings
 *   6. in `smart` mode it must not collide with an existing appointment
 *
 * **Tasks are never consulted.** A task with a due date is a reminder, not an
 * occupied hour, and letting them block bookings would empty a busy team's
 * calendar page. Only appointments on this calendar restrict availability.
 */
class BookingAvailability
{
    /** Weekday keys used inside `working_hours`. */
    public const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /** A sensible starting week: weekdays, 9–5. */
    public static function defaultWorkingHours(): array
    {
        $window = [['start' => '09:00', 'end' => '17:00']];

        return [
            'sun' => [],
            'mon' => $window,
            'tue' => $window,
            'wed' => $window,
            'thu' => $window,
            'fri' => $window,
            'sat' => [],
        ];
    }

    /**
     * Bookable slots for a single date, as `H:i` strings in the page timezone.
     *
     * Returns an empty list rather than throwing for a closed or out-of-range
     * day: "no times here" is a normal answer for a booking page.
     */
    public static function forDate(CalendarBookingSetting $settings, string $date): array
    {
        $timezone = $settings->timezone ?: ($settings->calendar->timezone ?? config('app.timezone', 'UTC'));

        try {
            $day = CarbonImmutable::parse($date, $timezone)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        $now = CarbonImmutable::now($timezone);

        // Outside the booking window entirely.
        if ($day->lt($now->startOfDay()) || $day->gt($now->addDays((int) $settings->max_days_in_advance)->endOfDay())) {
            return [];
        }

        $windows = self::windowsFor($settings, $day);
        if (!$windows) {
            return [];
        }

        $duration  = max(5, (int) $settings->slot_duration_minutes);
        $increment = max(5, (int) $settings->slot_increment_minutes ?: $duration);
        $before    = max(0, (int) $settings->buffer_before_minutes);
        $after     = max(0, (int) $settings->buffer_after_minutes);
        $earliest  = $now->addMinutes(max(0, (int) $settings->min_notice_minutes));

        $taken = self::bookedRanges($settings, $day, $timezone);

        // A full day already: no need to walk the windows.
        if ($settings->max_bookings_per_day !== null && count($taken) >= (int) $settings->max_bookings_per_day) {
            return [];
        }

        $slots = [];

        foreach ($windows as $window) {
            $open  = self::atTime($day, $window['start'] ?? '09:00');
            $close = self::atTime($day, $window['end'] ?? '17:00');

            if (!$open || !$close || $close->lte($open)) {
                continue;
            }

            for ($start = $open; $start->addMinutes($duration)->lte($close); $start = $start->addMinutes($increment)) {
                $end = $start->addMinutes($duration);

                if ($start->lt($earliest)) {
                    continue;
                }

                // Buffers extend the span this booking would occupy, so two
                // meetings cannot be scheduled back to back when padding is set.
                if (self::collides($start->subMinutes($before), $end->addMinutes($after), $taken)) {
                    continue;
                }

                $slots[] = $start->format('H:i');
            }
        }

        return array_values(array_unique($slots));
    }

    /**
     * Slots for each date in a range, keyed by Y-m-d. The page asks for a whole
     * month at once so the date picker can grey out closed days immediately
     * rather than probing per click.
     */
    public static function forRange(CalendarBookingSetting $settings, string $from, string $to, int $maxDays = 62): array
    {
        $timezone = $settings->timezone ?: ($settings->calendar->timezone ?? config('app.timezone', 'UTC'));

        try {
            $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
            $end   = CarbonImmutable::parse($to, $timezone)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        if ($end->lt($start)) {
            return [];
        }

        $days = [];
        $cursor = $start;

        for ($i = 0; $i < $maxDays && $cursor->lte($end); $i++) {
            $key = $cursor->format('Y-m-d');
            $days[$key] = self::forDate($settings, $key);
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * Whether a specific start time is still bookable.
     *
     * Re-checked at submission because a slot list can be minutes old by the
     * time someone finishes the form, and two visitors can hold the same slot
     * on screen at once.
     */
    public static function isBookable(CalendarBookingSetting $settings, Carbon $start): bool
    {
        // Open scheduling accepts any time the page offered; overlaps are the
        // point of the mode.
        if ($settings->availability_mode === 'open') {
            return true;
        }

        $timezone = $settings->timezone ?: ($settings->calendar->timezone ?? config('app.timezone', 'UTC'));
        $local    = $start->copy()->setTimezone($timezone);

        return in_array($local->format('H:i'), self::forDate($settings, $local->format('Y-m-d')), true);
    }

    /* ---------------- internals ---------------- */

    /** The open windows for one date: an override if present, else the weekday. */
    private static function windowsFor(CalendarBookingSetting $settings, CarbonImmutable $day): array
    {
        $overrides = $settings->date_overrides ?? [];
        $key = $day->format('Y-m-d');

        if (array_key_exists($key, $overrides)) {
            // An explicit empty override is a closed day (a holiday), not a
            // missing entry — so it short-circuits the weekly pattern.
            return is_array($overrides[$key]) ? $overrides[$key] : [];
        }

        $hours = $settings->working_hours ?: self::defaultWorkingHours();

        return $hours[self::DAYS[(int) $day->dayOfWeek]] ?? [];
    }

    /**
     * Appointment spans already occupying this date on this calendar.
     *
     * Cancelled and no-show appointments free their slot again — holding time
     * for a meeting that will not happen is exactly what a booking page should
     * avoid. In `open` mode nothing is treated as taken.
     */
    private static function bookedRanges(CalendarBookingSetting $settings, CarbonImmutable $day, string $timezone): array
    {
        if ($settings->availability_mode === 'open') {
            return [];
        }

        $appointments = Appointment::query()
            ->where('calendar_id', $settings->calendar_id)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            /*
             * Bounds in the application timezone, because that is the wall
             * clock the column stores — comparing against UTC bounds would
             * silently shift the window by the app's offset.
             *
             * Padded a day either side so a booking that spills over midnight
             * in another zone is still seen.
             */
            ->whereBetween('scheduled_at', [
                $day->subDay()->startOfDay()->setTimezone(config('app.timezone', 'UTC')),
                $day->addDays(2)->startOfDay()->setTimezone(config('app.timezone', 'UTC')),
            ])
            ->get(['scheduled_at', 'end_at']);

        $duration = max(5, (int) $settings->slot_duration_minutes);

        return $appointments->map(function (Appointment $appointment) use ($duration, $timezone) {
            $start = $appointment->scheduled_at->copy()->setTimezone($timezone);
            // An appointment without an explicit end occupies one slot length.
            $end = $appointment->end_at
                ? $appointment->end_at->copy()->setTimezone($timezone)
                : $start->copy()->addMinutes($duration);

            return [$start, $end];
        })->all();
    }

    private static function collides(CarbonImmutable $start, CarbonImmutable $end, array $taken): bool
    {
        foreach ($taken as [$busyStart, $busyEnd]) {
            // Touching endpoints do not overlap: 10:00–10:30 and 10:30–11:00
            // are both bookable.
            if ($start->lt($busyEnd) && $end->gt($busyStart)) {
                return true;
            }
        }

        return false;
    }

    private static function atTime(CarbonImmutable $day, string $time): ?CarbonImmutable
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $day->setTime($hour, $minute);
    }
}
