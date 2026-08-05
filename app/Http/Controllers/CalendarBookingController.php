<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\AppointmentType;
use App\Models\CalendarBookingSetting;
use App\Models\Funnel;
use App\Models\Stage;
use App\Models\User;
use App\Services\Booking\BookingAvailability;
use App\Services\Booking\BookingFormSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * BOOKING CONFIGURATION (authenticated)
 *
 * Everything the owner of a calendar configures about its public page: the
 * copy and branding, the scheduling rules, the form, and whether the page is
 * live at all. Driven entirely from the Calendar module — there is no separate
 * settings area for this.
 *
 * The settings row is created on demand, so a calendar has no booking
 * configuration until someone opens the panel, and the row's existence is not
 * what makes a page public — `is_published` is.
 */
class CalendarBookingController extends Controller
{
    public function show(Calendar $calendar): JsonResponse
    {
        $this->assertAccess($calendar);

        return response()->json(['data' => $this->settingsFor($calendar)]);
    }

    public function update(Request $request, Calendar $calendar): JsonResponse
    {
        $this->assertAccess($calendar);

        $settings = $this->settingsFor($calendar);

        $data = $request->validate([
            'is_published'           => 'sometimes|boolean',
            'title'                  => 'sometimes|nullable|string|max:120',
            'subtitle'               => 'sometimes|nullable|string|max:200',
            'description'            => 'sometimes|nullable|string|max:2000',

            'availability_mode'      => ['sometimes', Rule::in(CalendarBookingSetting::AVAILABILITY_MODES)],
            'slot_duration_minutes'  => 'sometimes|integer|min:5|max:480',
            'slot_increment_minutes' => 'sometimes|integer|min:5|max:480',
            'buffer_before_minutes'  => 'sometimes|integer|min:0|max:240',
            'buffer_after_minutes'   => 'sometimes|integer|min:0|max:240',
            'min_notice_minutes'     => 'sometimes|integer|min:0|max:20160',
            'max_days_in_advance'    => 'sometimes|integer|min:1|max:365',
            'max_bookings_per_day'   => 'sometimes|nullable|integer|min:1|max:100',
            'confirmation_mode'      => ['sometimes', Rule::in(CalendarBookingSetting::CONFIRMATION_MODES)],
            'requires_phone'         => 'sometimes|boolean',
            'timezone'               => 'sometimes|nullable|string|max:100',

            // Weekly availability. A day with an empty array is a closed day,
            // which is different from a day that is absent entirely only in
            // that it is explicit — both mean no slots.
            'working_hours'          => 'sometimes|nullable|array',
            'working_hours.*'        => 'array',
            'working_hours.*.*.start' => 'required_with:working_hours.*.*|string|regex:/^\d{1,2}:\d{2}$/',
            'working_hours.*.*.end'   => 'required_with:working_hours.*.*|string|regex:/^\d{1,2}:\d{2}$/',
            'date_overrides'         => 'sometimes|nullable|array',

            'branding'               => 'sometimes|nullable|array',
            'branding.primary_color' => 'sometimes|nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'branding.button_color'  => 'sometimes|nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'branding.background'    => 'sometimes|nullable|string|max:40',
            'branding.show_logo'     => 'sometimes|boolean',

            // Where a booking lands in the CRM: the funnel and stage its lead
            // is filed in, who owns it, and what kind of appointment it is.
            // Same mapping the form widget offers, for the same reasons.
            'booking_defaults'              => 'sometimes|nullable|array',
            'booking_defaults.funnel_id'    => 'sometimes|nullable|integer',
            'booking_defaults.stage_id'     => 'sometimes|nullable|integer',
            'booking_defaults.appointment_type_id' => 'sometimes|nullable|integer',
            'booking_defaults.assignment_mode'     => ['sometimes', Rule::in(['queue', 'user', 'unassigned'])],
            'booking_defaults.assigned_user_id'    => 'sometimes|nullable|integer',

            'confirmation_title'     => 'sometimes|nullable|string|max:120',
            'confirmation_message'   => 'sometimes|nullable|string|max:2000',

            ...BookingFormSchema::definitionRules(),
        ]);

        // Everything a booking can point at has to belong to this company —
        // `exists:` alone would let a caller file another tenant's leads.
        if (array_key_exists('booking_defaults', $data)) {
            if ($error = $this->rejectForeignDefaults($calendar, $data['booking_defaults'] ?? [])) {
                return response()->json(['message' => $error], 422);
            }
        }

        // A page cannot go live without a URL to live at.
        if (($data['is_published'] ?? false) && !$settings->public_slug) {
            $data['public_slug'] = $this->uniqueSlug($calendar);
        }

        $settings->update($data);

        return response()->json(['data' => $settings->fresh()]);
    }

    /**
     * Mint a fresh public URL.
     *
     * Regenerating deliberately breaks every link already shared — that is the
     * point of the action, and the only way to retire a URL that leaked.
     */
    public function regenerateSlug(Calendar $calendar): JsonResponse
    {
        $this->assertAccess($calendar);

        $settings = $this->settingsFor($calendar);
        $settings->update(['public_slug' => $this->uniqueSlug($calendar)]);

        return response()->json(['data' => $settings->fresh()]);
    }

    /**
     * The slots the public page would currently offer.
     *
     * The same computation the public endpoint runs, so the owner's preview
     * cannot disagree with what a visitor sees.
     */
    public function previewAvailability(Request $request, Calendar $calendar): JsonResponse
    {
        $this->assertAccess($calendar);

        $validated = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $settings = $this->settingsFor($calendar);
        $settings->setRelation('calendar', $calendar);

        return response()->json([
            'data' => BookingAvailability::forRange($settings, $validated['from'], $validated['to']),
        ]);
    }

    /* ---------------- helpers ---------------- */

    /**
     * Reject CRM targets that belong to another company.
     *
     * A booking page is configured by one company but writes leads and
     * appointments; a funnel, stage, owner or appointment type from elsewhere
     * would quietly file a stranger's records into someone else's pipeline.
     * Returns a message when something is wrong, null when it all checks out.
     */
    private function rejectForeignDefaults(Calendar $calendar, array $defaults): ?string
    {
        $companyId = $calendar->company_id;

        $funnelId = $defaults['funnel_id'] ?? null;
        if ($funnelId && !Funnel::where('id', $funnelId)->where('company_id', $companyId)->exists()) {
            return 'Funnel not found in this company.';
        }

        // A stage only means anything inside its own funnel, so it is checked
        // against the chosen funnel, not merely the company.
        $stageId = $defaults['stage_id'] ?? null;
        if ($stageId) {
            $stageOk = Stage::where('id', $stageId)
                ->when($funnelId, fn ($q) => $q->where('funnel_id', $funnelId))
                ->whereHas('funnel', fn ($q) => $q->where('company_id', $companyId))
                ->exists();

            if (!$stageOk) {
                return 'Stage not found in the selected funnel.';
            }
        }

        $userId = $defaults['assigned_user_id'] ?? null;
        if ($userId && !User::where('id', $userId)->where('company_id', $companyId)->exists()) {
            return 'User not found in this company.';
        }

        $typeId = $defaults['appointment_type_id'] ?? null;
        if ($typeId && !AppointmentType::where('id', $typeId)->where('company_id', $companyId)->exists()) {
            return 'Appointment type not found in this company.';
        }

        return null;
    }

    /**
     * This calendar's settings, created with usable defaults on first open so
     * the panel never has to render an empty state.
     */
    private function settingsFor(Calendar $calendar): CalendarBookingSetting
    {
        return CalendarBookingSetting::firstOrCreate(
            ['calendar_id' => $calendar->id],
            [
                'company_id'    => $calendar->company_id,
                'title'         => $calendar->name,
                'timezone'      => $calendar->timezone,
                'working_hours' => BookingAvailability::defaultWorkingHours(),
                'form_fields'   => BookingFormSchema::defaultFields(),
            ]
        );
    }

    /**
     * A readable, unguessable slug.
     *
     * The calendar name gives it meaning; the random suffix stops a page being
     * found by trying company names, since these URLs are unauthenticated.
     */
    private function uniqueSlug(Calendar $calendar): string
    {
        $base = Str::slug($calendar->name) ?: 'booking';

        do {
            $slug = $base . '-' . Str::lower(Str::random(8));
        } while (CalendarBookingSetting::where('public_slug', $slug)->exists());

        return $slug;
    }

    private function assertAccess(Calendar $calendar): void
    {
        $user = Auth::user();

        abort_if(!$user?->company_id, 403, 'User does not belong to a company.');
        abort_if($calendar->company_id !== $user->company_id, 403, 'Unauthorized access to this calendar.');

        $visible = Calendar::whereKey($calendar->id)->visibleToUser($user)->exists();

        abort_if(!$visible, 403, 'Unauthorized access to this calendar.');
    }
}
