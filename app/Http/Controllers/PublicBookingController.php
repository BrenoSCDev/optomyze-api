<?php

namespace App\Http\Controllers;

use App\Models\CalendarBookingSetting;
use App\Services\Booking\BookingAvailability;
use App\Services\Booking\BookingFormSchema;
use App\Services\Booking\PublicBookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * PUBLIC BOOKING (unauthenticated)
 *
 * The three calls a booking page makes: what am I, when are you free, and book
 * this slot. No session, no token — the slug is the only credential, which is
 * why it carries a random suffix and why every response here is deliberately
 * narrow.
 *
 * What is *not* exposed matters as much as what is: no company id, no calendar
 * id, no user names, no existing appointments — only that a time is free.
 * Anything more would make these URLs a directory of a company's schedule.
 */
class PublicBookingController extends Controller
{
    /** The page's own configuration: copy, branding, form, and the rules. */
    public function show(string $slug): JsonResponse
    {
        $settings = $this->publishedOrFail($slug);

        return response()->json(['data' => $this->publicPayload($settings)]);
    }

    /** Free slots across a date range, as `H:i` strings in the page timezone. */
    public function availability(Request $request, string $slug): JsonResponse
    {
        $settings = $this->publishedOrFail($slug);

        $validated = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        return response()->json([
            'data' => [
                'timezone' => $this->timezone($settings),
                'days'     => BookingAvailability::forRange($settings, $validated['from'], $validated['to']),
            ],
        ]);
    }

    /**
     * Take a booking.
     *
     * Rate limited by slug and IP: these endpoints are open to the internet,
     * and without a limit a booking page is a way to fill a company's calendar
     * and contact list with junk.
     */
    public function store(Request $request, PublicBookingService $bookings, string $slug): JsonResponse
    {
        $settings = $this->publishedOrFail($slug);

        $key = 'booking:' . $slug . ':' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json([
                'message' => 'Too many booking attempts. Please try again shortly.',
            ], 429);
        }

        RateLimiter::hit($key, 600);

        $fields = BookingFormSchema::enabledFields($settings->form_fields);

        $validated = $request->validate([
            'starts_at' => 'required|date',
            'answers'   => 'required|array',
            ...BookingFormSchema::submissionRules($fields),
        ]);

        $timezone = $this->timezone($settings);

        try {
            // Interpreted in the page's timezone unless the client sent an
            // offset, so "10:00" means 10:00 where the business is.
            $startsAt = Carbon::parse($validated['starts_at'], $timezone);
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid start time.'], 422);
        }

        if ($startsAt->isPast()) {
            return response()->json(['message' => 'That time has already passed.'], 422);
        }

        // Re-checked here, not trusted from the slot list: the page may have
        // been open for an hour, and two visitors can hold the same slot.
        if (!BookingAvailability::isBookable($settings, $startsAt)) {
            return response()->json([
                'message' => 'That time is no longer available. Please choose another slot.',
            ], 409);
        }

        $result = $bookings->book($settings, $startsAt, $validated['answers']);

        return response()->json([
            'data' => [
                // No ids of any kind: the visitor gets back what they need to
                // recognise their booking, nothing that describes the CRM.
                'starts_at'            => $result['appointment']->scheduled_at->toIso8601String(),
                'ends_at'              => $result['appointment']->end_at?->toIso8601String(),
                'timezone'             => $result['appointment']->timezone,
                'confirmation_title'   => $settings->confirmation_title ?: 'You are booked',
                'confirmation_message' => $settings->confirmation_message
                    ?: 'Thank you. Your appointment is confirmed and we have sent the details to your email.',
            ],
        ], 201);
    }

    /* ---------------- helpers ---------------- */

    /**
     * A published page, or a 404.
     *
     * An unpublished or unknown slug both answer 404 — telling the difference
     * would confirm that a page exists, which is not something an anonymous
     * caller should be able to learn.
     */
    private function publishedOrFail(string $slug): CalendarBookingSetting
    {
        $settings = CalendarBookingSetting::with('calendar')
            ->where('public_slug', $slug)
            ->where('is_published', true)
            ->first();

        abort_if(!$settings, 404, 'Booking page not found.');

        // A calendar that was archived or deleted takes its page down with it,
        // regardless of what the booking row still says.
        abort_if(!$settings->calendar || !$settings->calendar->is_active, 404, 'Booking page not found.');

        return $settings;
    }

    /**
     * A fetchable URL for the company logo.
     *
     * Logos live on the private disk, which the CRM reads through an
     * authenticated route — no use to a stranger's browser. A relative signed
     * URL works without a session and is scheme/host agnostic, which matters
     * behind the TLS-terminating proxy. Long-lived because a booking page can
     * sit open, and because the only thing it grants is one company logo.
     */
    private function signedLogoUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'files.signed',
            now()->addDays(7),
            ['path' => $path],
            false
        );
    }

    private function timezone(CalendarBookingSetting $settings): string
    {
        return $settings->timezone
            ?: ($settings->calendar->timezone ?: config('app.timezone', 'UTC'));
    }

    /** Exactly what the page needs to render itself, and nothing else. */
    private function publicPayload(CalendarBookingSetting $settings): array
    {
        $calendar = $settings->calendar;
        $company  = $calendar->company;

        return [
            'slug'        => $settings->public_slug,
            'title'       => $settings->title ?: $calendar->name,
            'subtitle'    => $settings->subtitle,
            'description' => $settings->description,

            'company' => [
                'name' => $company?->name,
                'logo' => $this->signedLogoUrl($company?->logo_path),
            ],

            'branding' => array_merge([
                'primary_color' => $calendar->color ?: '#3B82F6',
                'button_color'  => $calendar->color ?: '#3B82F6',
                'background'    => 'default',
                'show_logo'     => true,
            ], $settings->branding ?? []),

            'schedule' => [
                'timezone'          => $this->timezone($settings),
                'duration_minutes'  => (int) $settings->slot_duration_minutes,
                'availability_mode' => $settings->availability_mode,
                'max_days_in_advance' => (int) $settings->max_days_in_advance,
                'min_notice_minutes'  => (int) $settings->min_notice_minutes,
                'increment_minutes'   => (int) $settings->slot_increment_minutes,
            ],

            'fields' => BookingFormSchema::enabledFields($settings->form_fields),

            'confirmation' => [
                'title'   => $settings->confirmation_title,
                'message' => $settings->confirmation_message,
            ],

        ];
    }
}
