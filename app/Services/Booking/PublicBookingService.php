<?php

namespace App\Services\Booking;

use App\Events\AppointmentLiveUpdate;
use App\Jobs\FireIntegrationWebhook;
use App\Models\Appointment;
use App\Models\CalendarBookingSetting;
use App\Models\Lead;
use App\Models\LeadCustomField;
use App\Models\Record;
use App\Services\NotificationService;
use App\Services\LeadQueueAssignmentService;
use App\Events\LeadLiveUpdate;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a public booking submission into CRM records.
 *
 * One transaction produces the lead (new or matched) and the appointment, so a
 * visitor can never end up with a contact and no meeting, or the reverse.
 * Everything that reaches outward — broadcasts, notifications, webhooks — is
 * fired after the commit, because a listener must never observe a booking that
 * a later failure rolls back.
 *
 * Lead matching reuses the rule the form widget already applies: same company,
 * same email or a known variant of the same phone number. Someone who books
 * twice is one contact with two appointments, not two contacts.
 */
class PublicBookingService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * @param  array  $answers  Submitted form values, keyed by field key.
     * @return array{lead: Lead, appointment: Appointment, created_lead: bool}
     */
    public function book(CalendarBookingSetting $settings, Carbon $startsAt, array $answers): array
    {
        $calendar = $settings->calendar;
        $fields   = BookingFormSchema::enabledFields($settings->form_fields);

        [$leadValues, $extra] = BookingFormSchema::splitAnswers($fields, $answers);

        $email = $leadValues['email'] ?? null;
        $phone = $leadValues['phone'] ?? null;

        $duration = max(5, (int) $settings->slot_duration_minutes);

        /*
         * Persisted in the application timezone.
         *
         * Eloquent stores a datetime by formatting the Carbon instance in
         * whatever timezone it happens to carry, and reads it back by parsing
         * in the app timezone. Handing it a UTC instance while the app runs on
         * another zone therefore round-trips to a different moment — the
         * booking would land hours away from the slot the visitor picked.
         * Converting here keeps one convention: the column holds app-timezone
         * wall clock, exactly as the rest of the CRM writes it.
         */
        $appTimezone = config('app.timezone', 'UTC');
        $startsAt    = $startsAt->copy()->setTimezone($appTimezone);
        $endsAt      = $startsAt->copy()->addMinutes($duration);

        [$lead, $appointment, $leadWasCreated] = DB::transaction(function () use (
            $settings, $calendar, $fields, $leadValues, $extra, $email, $phone, $startsAt, $endsAt
        ) {
            $existing = $this->findExistingLead($calendar->company_id, $email, $phone);

            $lead = $existing ?: $this->createLead($settings, $leadValues, $email, $phone);

            $this->storeExtraAnswers($lead, $extra, $fields);

            $appointment = Appointment::create([
                'company_id'          => $calendar->company_id,
                'calendar_id'         => $calendar->id,
                'lead_id'             => $lead->id,
                'appointment_type_id' => Arr::get($settings->booking_defaults ?? [], 'appointment_type_id'),
                // Deliberately unassigned: nobody in the CRM claimed this, and
                // a booking page has no way to know whose it should be. The
                // calendar's owner is the natural future default.
                'user_id'             => $calendar->owner_id,
                'title'               => $this->title($settings, $lead),
                'scheduled_at'        => $startsAt,
                'end_at'              => $endsAt,
                'timezone'            => $settings->timezone
                    ?: ($calendar->timezone ?: config('app.timezone', 'UTC')),
                'notes'               => $leadValues['notes'] ?? null,
                // Pending approval is configured but not yet acted on anywhere;
                // 'scheduled' keeps the appointment visible in the meantime.
                'status'              => $settings->confirmation_mode === 'manual' ? 'draft' : 'scheduled',
            ]);

            return [$lead, $appointment, $existing === null];
        });

        $this->announce($settings, $lead, $appointment, $leadWasCreated);

        return [
            'lead'         => $lead,
            'appointment'  => $appointment,
            'created_lead' => $leadWasCreated,
        ];
    }

    /* ---------------- internals ---------------- */

    private function createLead(CalendarBookingSetting $settings, array $values, ?string $email, ?string $phone): Lead
    {
        // A form may collect either a single "name" or first/last separately.
        if (!empty($values['first_name']) || !empty($values['last_name'])) {
            $first = $values['first_name'] ?? 'Booking';
            $last  = $values['last_name'] ?? null;
        } else {
            [$first, $last] = BookingFormSchema::splitName((string) ($values['name'] ?? ''));
        }

        $defaults = $settings->booking_defaults ?? [];

        $lead = Lead::create([
            'company_id' => $settings->company_id,
            // Optional: an unfiled contact is reachable from Contacts, so a
            // booking page works before anyone picks a funnel for it.
            'funnel_id'  => Arr::get($defaults, 'funnel_id'),
            'stage_id'   => Arr::get($defaults, 'stage_id'),
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email,
            'phone'      => $phone,
            ...BookingFormSchema::leadSource(),
        ]);

        $this->assignOwner($lead, $defaults);

        return $lead;
    }

    /**
     * Who owns a lead that came from this page.
     *
     *   user       — a named person, verified as this company's when saved
     *   unassigned — deliberately nobody, for teams that triage by hand
     *   queue      — the funnel's distribution rotation (the default, and what
     *                every other lead-creating path already does)
     *
     * The queue is a no-op when the funnel has none, so the default is safe
     * for a page with no funnel configured at all.
     */
    private function assignOwner(Lead $lead, array $defaults): void
    {
        $mode = Arr::get($defaults, 'assignment_mode', 'queue');

        if ($mode === 'unassigned') {
            return;
        }

        if ($mode === 'user') {
            $userId = Arr::get($defaults, 'assigned_user_id');

            // Re-checked at booking time, not just when configured: the user
            // may have moved company or been deleted since.
            $owner = $userId
                ? \App\Models\User::where('id', $userId)->where('company_id', $lead->company_id)->first()
                : null;

            if ($owner) {
                $lead->update(['assigned_to' => $owner->id]);
                return;
            }

            // A stale target falls through to the queue rather than silently
            // leaving the lead ownerless.
        }

        LeadQueueAssignmentService::assign($lead);
    }

    /**
     * Non-lead answers land in `lead_custom_fields`, the same store the form
     * widget writes to — so a value collected by a booking page and one
     * collected by a form appear identically on the contact.
     */
    private function storeExtraAnswers(Lead $lead, array $extra, array $fields): void
    {
        if (!$extra) {
            return;
        }

        $typeOf = collect($fields)->keyBy('key');

        foreach ($extra as $key => $entry) {
            $value = $entry['value'];

            if ($value === null || $value === '') {
                continue;
            }

            try {
                LeadCustomField::updateOrCreate(
                    [
                        'company_id' => $lead->company_id,
                        'lead_id'    => $lead->id,
                        'field_key'  => $key,
                        'type'       => 'lead',
                    ],
                    [
                        'field_type'  => match ($typeOf[$key]['type'] ?? 'text') {
                            'number'   => 'number',
                            'checkbox' => 'boolean',
                            'date'     => 'date',
                            default    => 'text',
                        },
                        'field_value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                    ]
                );
            } catch (\Throwable $e) {
                // A custom field that cannot be stored must not cost the
                // visitor their booking.
                Log::warning('Booking: failed to store custom answer', [
                    'lead_id' => $lead->id,
                    'key'     => $key,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    private function title(CalendarBookingSetting $settings, Lead $lead): string
    {
        $name = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '')) ?: 'Booking';
        $label = $settings->title ?: ($settings->calendar->name ?? 'Booking');

        return mb_substr("{$label} — {$name}", 0, 255);
    }

    /**
     * Everything outward-facing, after the commit.
     *
     * Each is wrapped: a booking that is already saved must not fail because a
     * webhook endpoint is down or a broadcast connection dropped.
     */
    private function announce(CalendarBookingSetting $settings, Lead $lead, Appointment $appointment, bool $leadWasCreated): void
    {
        $appointment->load(['lead:id,company_id,first_name,last_name,funnel_id,assigned_to,avatar_key', 'appointmentType', 'user:id,name,email']);

        try {
            if ($leadWasCreated) {
                Record::createLeadCreation(
                    $lead,
                    null,
                    Record::SOURCE_WEBHOOK,
                    ['calendarName' => $settings->calendar->name],
                    'via_booking_page'
                );

                broadcast(new LeadLiveUpdate('created', $lead));
            }

            // Puts the booking on the lead timeline and in Record Logs, which
            // is what makes it visible in reporting alongside manual bookings.
            Record::createAppointmentCreated($appointment, $lead, null);

            broadcast(new AppointmentLiveUpdate('created', $appointment));
        } catch (\Throwable $e) {
            Log::warning('Booking: post-commit announcements failed', ['error' => $e->getMessage()]);
        }

        try {
            $this->notifications->dispatch('appointment_scheduled', $appointment->company_id, [
                'lead_owner_id'      => $lead->assigned_to,
                'actor_name'         => null,
                'lead'               => ['id' => $lead->id, 'name' => trim("{$lead->first_name} {$lead->last_name}")],
                'appointment'        => [
                    'title'         => $appointment->title,
                    'scheduled_at'  => $appointment->scheduled_at
                        ->setTimezone($appointment->timezone ?? 'UTC')
                        ->format('d/m/Y \a\t H:i'),
                    'assigned_user' => $appointment->user?->name,
                ],
                '_appointment_model' => $appointment,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Booking: notification failed', ['error' => $e->getMessage()]);
        }

        try {
            // The hook future automation workflows listen on. Named for the
            // event, not the mechanism, so 'appointment_booked' can gain
            // rescheduled/cancelled siblings without changing shape.
            FireIntegrationWebhook::dispatch('appointment_booked', [
                'id'           => $appointment->id,
                'company_id'   => $appointment->company_id,
                'calendar_id'  => $appointment->calendar_id,
                'lead_id'      => $lead->id,
                'title'        => $appointment->title,
                'scheduled_at' => $appointment->scheduled_at?->toISOString(),
                'end_at'       => $appointment->end_at?->toISOString(),
                'timezone'     => $appointment->timezone,
                'status'       => $appointment->status,
                'source'       => 'booking_page',
                'created_at'   => $appointment->created_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Booking: webhook dispatch failed', ['error' => $e->getMessage()]);
        }
    }

    /** Same matching rule the form widget uses: email, or a phone variant. */
    private function findExistingLead(int $companyId, ?string $email, ?string $phone): ?Lead
    {
        $digits   = $phone ? \App\Support\PhoneNormalizer::normalize($phone) : null;
        $variants = $digits ? \App\Support\PhoneNormalizer::variants($digits) : null;

        if (!$email && !$variants) {
            return null;
        }

        return Lead::where('company_id', $companyId)
            ->where(function ($query) use ($email, $variants) {
                if ($email) {
                    $query->orWhere('email', $email);
                }
                if ($variants) {
                    $query->orWhereIn('phone', $variants);
                }
            })
            ->first();
    }
}
