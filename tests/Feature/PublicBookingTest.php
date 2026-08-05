<?php

use App\Models\Appointment;
use App\Models\Calendar;
use App\Models\CalendarBookingSetting;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\Booking\BookingAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public booking pages: availability, the booking transaction, and the
 * boundary between an anonymous visitor and the CRM behind it.
 */
uses(RefreshDatabase::class);

function bookingCompany(string $name = 'Booking Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
        'timezone'  => 'UTC',
    ]);
}

function bookingUser(Company $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'booking-' . $company->id . '-' . uniqid() . '@example.test',
        'role'       => 'admin',
    ]);
}

/** A published page, open every day 09:00–17:00 in 30-minute slots. */
function publishedPage(Company $company, array $overrides = []): CalendarBookingSetting
{
    $calendar = Calendar::defaultFor($company->id);

    $everyDay = ['start' => '09:00', 'end' => '17:00'];

    return CalendarBookingSetting::create(array_merge([
        'calendar_id'            => $calendar->id,
        'company_id'             => $company->id,
        'public_slug'            => 'demo-' . uniqid(),
        'is_published'           => true,
        'title'                  => 'Intro call',
        'timezone'               => 'UTC',
        'slot_duration_minutes'  => 30,
        'slot_increment_minutes' => 30,
        'min_notice_minutes'     => 0,
        'max_days_in_advance'    => 60,
        'working_hours'          => [
            'sun' => [$everyDay], 'mon' => [$everyDay], 'tue' => [$everyDay],
            'wed' => [$everyDay], 'thu' => [$everyDay], 'fri' => [$everyDay],
            'sat' => [$everyDay],
        ],
        'form_fields'            => \App\Services\Booking\BookingFormSchema::defaultFields(),
    ], $overrides));
}

/** A date far enough ahead that "today" and notice windows never interfere. */
function bookingDate(): string
{
    return now()->addDays(7)->format('Y-m-d');
}

/**
 * An appointment occupying a slot on the page's calendar.
 *
 * Times are given in the page timezone (UTC here) and converted to the app
 * timezone before storing, matching how the booking service persists — the
 * database column holds app-timezone wall clock.
 */
function occupySlot(Company $company, CalendarBookingSetting $page, string $date, string $from, string $to, string $status = 'scheduled'): Appointment
{
    $appTz = config('app.timezone', 'UTC');

    return Appointment::create([
        'company_id'   => $company->id,
        'calendar_id'  => $page->calendar_id,
        'title'        => 'Fixture',
        'scheduled_at' => \Carbon\Carbon::parse("{$date} {$from}", 'UTC')->setTimezone($appTz),
        'end_at'       => \Carbon\Carbon::parse("{$date} {$to}", 'UTC')->setTimezone($appTz),
        'timezone'     => 'UTC',
        'status'       => $status,
    ]);
}

beforeEach(fn () => RateLimiter::clear('booking:test'));

/* ---------------- the public page ---------------- */

test('a published page exposes its configuration without any CRM ids', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);

    $res = $this->getJson("/api/public/booking/{$page->public_slug}");

    $res->assertOk();
    expect($res->json('data.title'))->toBe('Intro call')
        ->and($res->json('data.fields'))->not->toBeEmpty()
        // An anonymous visitor must not learn the CRM's internal shape.
        ->and($res->json('data'))->not->toHaveKey('company_id')
        ->and($res->json('data'))->not->toHaveKey('calendar_id');
});

test('an unpublished page is indistinguishable from one that does not exist', function () {
    $company = bookingCompany();
    $page    = publishedPage($company, ['is_published' => false]);

    $this->getJson("/api/public/booking/{$page->public_slug}")->assertNotFound();
    $this->getJson('/api/public/booking/never-existed')->assertNotFound();
});

test('archiving the calendar takes its booking page down', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);

    // The default calendar cannot be archived, so use a second one.
    $calendar = Calendar::create(['company_id' => $company->id, 'name' => 'Team']);
    $page->update(['calendar_id' => $calendar->id]);

    $this->getJson("/api/public/booking/{$page->public_slug}")->assertOk();

    $calendar->update(['is_active' => false]);

    $this->getJson("/api/public/booking/{$page->public_slug}")->assertNotFound();
});

/* ---------------- availability ---------------- */

test('availability offers slots across the working window', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);
    $date    = bookingDate();

    $res = $this->getJson("/api/public/booking/{$page->public_slug}/availability?from={$date}&to={$date}");

    $res->assertOk();
    $slots = $res->json("data.days.{$date}");

    // 09:00 to 17:00, 30-minute slots, last one starting 16:30.
    expect($slots)->toHaveCount(16)
        ->and($slots[0])->toBe('09:00')
        ->and(end($slots))->toBe('16:30');
});

test('an existing appointment removes its slot in smart mode', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);
    $date    = bookingDate();

    occupySlot($company, $page, $date, '10:00', '10:30', 'scheduled');

    $slots = $this->getJson("/api/public/booking/{$page->public_slug}/availability?from={$date}&to={$date}")
        ->json("data.days.{$date}");

    expect($slots)->not->toContain('10:00')
        // Neighbours are untouched: touching endpoints do not overlap.
        ->and($slots)->toContain('09:30')
        ->and($slots)->toContain('10:30');
});

test('a task on the same day never blocks a slot', function () {
    $company = bookingCompany();
    $user    = bookingUser($company);
    $page    = publishedPage($company);
    $date    = bookingDate();

    // The explicit rule: tasks are reminders, not occupied hours.
    Task::create([
        'company_id'  => $company->id,
        'calendar_id' => $page->calendar_id,
        'title'       => 'Prepare deck',
        'due_date'    => $date . ' 10:00:00',
        'created_by'  => $user->id,
    ]);

    $slots = $this->getJson("/api/public/booking/{$page->public_slug}/availability?from={$date}&to={$date}")
        ->json("data.days.{$date}");

    expect($slots)->toContain('10:00')->and($slots)->toHaveCount(16);
});

test('a cancelled appointment gives its slot back', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);
    $date    = bookingDate();

    occupySlot($company, $page, $date, '11:00', '11:30', 'cancelled');

    $slots = $this->getJson("/api/public/booking/{$page->public_slug}/availability?from={$date}&to={$date}")
        ->json("data.days.{$date}");

    expect($slots)->toContain('11:00');
});

test('buffers keep bookings from touching an existing appointment', function () {
    $company = bookingCompany();
    $page    = publishedPage($company, ['buffer_before_minutes' => 30, 'buffer_after_minutes' => 30]);
    $date    = bookingDate();

    occupySlot($company, $page, $date, '13:00', '13:30', 'scheduled');

    $slots = $this->getJson("/api/public/booking/{$page->public_slug}/availability?from={$date}&to={$date}")
        ->json("data.days.{$date}");

    expect($slots)->not->toContain('13:00')
        ->and($slots)->not->toContain('12:30')
        ->and($slots)->not->toContain('13:30');
});

test('open scheduling ignores existing appointments entirely', function () {
    $company = bookingCompany();
    $page    = publishedPage($company, ['availability_mode' => 'open']);
    $date    = bookingDate();

    occupySlot($company, $page, $date, '10:00', '10:30', 'scheduled');

    $slots = $this->getJson("/api/public/booking/{$page->public_slug}/availability?from={$date}&to={$date}")
        ->json("data.days.{$date}");

    expect($slots)->toContain('10:00');
});

test('a closed weekday offers nothing', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);
    $date    = bookingDate();

    // Close the weekday the test date falls on.
    $day = BookingAvailability::DAYS[(int) now()->addDays(7)->dayOfWeek];
    $hours = $page->working_hours;
    $hours[$day] = [];
    $page->update(['working_hours' => $hours]);

    $slots = $this->getJson("/api/public/booking/{$page->public_slug}/availability?from={$date}&to={$date}")
        ->json("data.days.{$date}");

    expect($slots)->toBeEmpty();
});

test('a date override closes a single day without touching the week', function () {
    $company = bookingCompany();
    $date    = bookingDate();
    $page    = publishedPage($company, ['date_overrides' => [$date => []]]);

    $next = now()->addDays(8)->format('Y-m-d');

    $days = $this->getJson("/api/public/booking/{$page->public_slug}/availability?from={$date}&to={$next}")
        ->json('data.days');

    expect($days[$date])->toBeEmpty()
        ->and($days[$next])->not->toBeEmpty();
});

test('slots beyond the advance window are not offered', function () {
    $company = bookingCompany();
    $page    = publishedPage($company, ['max_days_in_advance' => 3]);

    $far = now()->addDays(10)->format('Y-m-d');

    expect($this->getJson("/api/public/booking/{$page->public_slug}/availability?from={$far}&to={$far}")
        ->json("data.days.{$far}"))->toBeEmpty();
});

/* ---------------- booking ---------------- */

test('booking creates a lead with the Calendar source and an appointment', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);
    $date    = bookingDate();

    $res = $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => $date . ' 10:00:00',
        'answers'   => [
            'name'  => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+5511999990000',
            'notes' => 'Interested in the enterprise plan',
        ],
    ]);

    $res->assertCreated();

    $lead = Lead::where('company_id', $company->id)->first();
    expect($lead->first_name)->toBe('Ada')
        ->and($lead->last_name)->toBe('Lovelace')
        ->and($lead->email)->toBe('ada@example.com')
        ->and($lead->source_platform)->toBe(Lead::PLATFORM_CALENDAR)
        ->and($lead->source_type)->toBe(Lead::SOURCE_BOOKING);

    $appointment = Appointment::where('company_id', $company->id)->first();
    expect($appointment->lead_id)->toBe($lead->id)
        ->and($appointment->calendar_id)->toBe($page->calendar_id)
        ->and($appointment->notes)->toBe('Interested in the enterprise plan')
        ->and((int) $appointment->scheduled_at->diffInMinutes($appointment->end_at))->toBe(30);
});

test('booking twice with the same email reuses the contact', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);
    $date    = bookingDate();

    foreach (['10:00', '11:00'] as $time) {
        $this->postJson("/api/public/booking/{$page->public_slug}/book", [
            'starts_at' => "{$date} {$time}:00",
            'answers'   => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
        ])->assertCreated();
    }

    // One contact, two meetings — not two contacts.
    expect(Lead::where('company_id', $company->id)->count())->toBe(1)
        ->and(Appointment::where('company_id', $company->id)->count())->toBe(2);
});

test('a taken slot is refused even if the page still offers it', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);
    $date    = bookingDate();

    $payload = fn () => [
        'starts_at' => $date . ' 14:00:00',
        'answers'   => ['name' => 'First Booker', 'email' => 'first@example.com'],
    ];

    $this->postJson("/api/public/booking/{$page->public_slug}/book", $payload())->assertCreated();

    // The second visitor had the same slot on screen.
    $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => $date . ' 14:00:00',
        'answers'   => ['name' => 'Second Booker', 'email' => 'second@example.com'],
    ])->assertStatus(409);

    expect(Appointment::where('company_id', $company->id)->count())->toBe(1);
});

test('open scheduling accepts a slot that is already taken', function () {
    $company = bookingCompany();
    $page    = publishedPage($company, ['availability_mode' => 'open']);
    $date    = bookingDate();

    foreach (['first', 'second'] as $who) {
        $this->postJson("/api/public/booking/{$page->public_slug}/book", [
            'starts_at' => $date . ' 15:00:00',
            'answers'   => ['name' => ucfirst($who) . ' Booker', 'email' => "{$who}@example.com"],
        ])->assertCreated();
    }

    expect(Appointment::where('company_id', $company->id)->count())->toBe(2);
});

test('a booking in the past is refused', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);

    $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
        'answers'   => ['name' => 'Time Traveller', 'email' => 'past@example.com'],
    ])->assertStatus(422);

    expect(Appointment::count())->toBe(0);
});

test('the form rules are enforced from the stored configuration', function () {
    $company = bookingCompany();
    $page    = publishedPage($company);
    $date    = bookingDate();

    // Email is required by the default form; a client cannot opt out.
    $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => $date . ' 10:00:00',
        'answers'   => ['name' => 'No Email'],
    ])->assertStatus(422);

    // ...and it must actually be an email.
    $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => $date . ' 10:00:00',
        'answers'   => ['name' => 'Bad Email', 'email' => 'not-an-email'],
    ])->assertStatus(422);

    expect(Lead::count())->toBe(0);
});

test('custom answers are stored against the contact', function () {
    $company = bookingCompany();
    $date    = bookingDate();

    $fields = \App\Services\Booking\BookingFormSchema::defaultFields();
    $fields[] = [
        'key' => 'team_size', 'source' => 'custom', 'type' => 'number',
        'label' => 'Team size', 'required' => false, 'enabled' => true,
    ];

    $page = publishedPage($company, ['form_fields' => $fields]);

    $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => $date . ' 10:00:00',
        'answers'   => ['name' => 'Ada', 'email' => 'ada@example.com', 'team_size' => 42],
    ])->assertCreated();

    $lead = Lead::first();

    expect(\App\Models\LeadCustomField::where('lead_id', $lead->id)->where('field_key', 'team_size')->value('field_value'))
        ->toBe('42');
});

/* ---------------- configuration ---------------- */

test('publishing mints a slug and the page goes live', function () {
    $company  = bookingCompany();
    $user     = bookingUser($company);
    $calendar = Calendar::defaultFor($company->id);

    $res = $this->actingAs($user)->putJson("/api/calendars/{$calendar->id}/booking", [
        'is_published' => true,
        'title'        => 'Book a demo',
    ]);

    $res->assertOk();
    $slug = $res->json('data.public_slug');

    expect($slug)->not->toBeNull();
    $this->getJson("/api/public/booking/{$slug}")->assertOk();
});

test('regenerating the slug breaks the old link', function () {
    $company  = bookingCompany();
    $user     = bookingUser($company);
    $calendar = Calendar::defaultFor($company->id);

    $old = $this->actingAs($user)
        ->putJson("/api/calendars/{$calendar->id}/booking", ['is_published' => true])
        ->json('data.public_slug');

    $new = $this->actingAs($user)
        ->postJson("/api/calendars/{$calendar->id}/booking/slug")
        ->json('data.public_slug');

    expect($new)->not->toBe($old);
    $this->getJson("/api/public/booking/{$old}")->assertNotFound();
    $this->getJson("/api/public/booking/{$new}")->assertOk();
});

test('another company cannot read or change a calendar booking page', function () {
    $mine   = bookingCompany('Mine');
    $theirs = bookingCompany('Theirs');

    $outsider = bookingUser($theirs);
    $calendar = Calendar::defaultFor($mine->id);

    $this->actingAs($outsider)->getJson("/api/calendars/{$calendar->id}/booking")->assertForbidden();
    $this->actingAs($outsider)->putJson("/api/calendars/{$calendar->id}/booking", ['is_published' => true])
        ->assertForbidden();
    $this->actingAs($outsider)->postJson("/api/calendars/{$calendar->id}/booking/slug")->assertForbidden();
});

test('booking configuration requires authentication', function () {
    $company  = bookingCompany();
    $calendar = Calendar::defaultFor($company->id);

    $this->getJson("/api/calendars/{$calendar->id}/booking")->assertUnauthorized();
});

/* ---------------- CRM mapping ---------------- */

/** A contact funnel with an entry stage, for the CRM mapping tests. */
function bookingFunnel(Company $company, User $owner): array
{
    $funnel = \App\Models\Funnel::create([
        'company_id' => $company->id,
        'name'       => 'Inbound',
        'type'       => 'contact',
        'is_active'  => true,
        'created_by' => $owner->id,
    ]);

    $stage = \App\Models\Stage::create([
        'funnel_id' => $funnel->id,
        'name'      => 'New',
        'type'      => 'entry',
        'order'     => 1,
        'is_active' => true,
    ]);

    return [$funnel, $stage];
}

test('a booking files its lead into the configured funnel and stage', function () {
    $company = bookingCompany();
    $user    = bookingUser($company);
    [$funnel, $stage] = bookingFunnel($company, $user);

    $page = publishedPage($company, [
        'booking_defaults' => ['funnel_id' => $funnel->id, 'stage_id' => $stage->id],
    ]);

    $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => bookingDate() . ' 10:00:00',
        'answers'   => ['name' => 'Ada', 'email' => 'ada@example.com'],
    ])->assertCreated();

    $lead = Lead::first();
    expect($lead->funnel_id)->toBe($funnel->id)
        ->and($lead->stage_id)->toBe($stage->id);
});

test('a booking can be assigned to a named user', function () {
    $company = bookingCompany();
    $user    = bookingUser($company);
    $rep     = bookingUser($company);

    $page = publishedPage($company, [
        'booking_defaults' => ['assignment_mode' => 'user', 'assigned_user_id' => $rep->id],
    ]);

    $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => bookingDate() . ' 10:00:00',
        'answers'   => ['name' => 'Ada', 'email' => 'ada@example.com'],
    ])->assertCreated();

    expect(Lead::first()->assigned_to)->toBe($rep->id);
});

test('unassigned mode leaves the lead without an owner', function () {
    $company = bookingCompany();
    $user    = bookingUser($company);
    [$funnel, $stage] = bookingFunnel($company, $user);

    $page = publishedPage($company, [
        'booking_defaults' => [
            'funnel_id' => $funnel->id, 'stage_id' => $stage->id,
            'assignment_mode' => 'unassigned',
        ],
    ]);

    $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => bookingDate() . ' 10:00:00',
        'answers'   => ['name' => 'Ada', 'email' => 'ada@example.com'],
    ])->assertCreated();

    expect(Lead::first()->assigned_to)->toBeNull();
});

test('a user who no longer belongs to the company does not own the lead', function () {
    $mine   = bookingCompany('Mine');
    $theirs = bookingCompany('Theirs');

    $outsider = bookingUser($theirs);

    // Configured directly, as though the user had since moved company.
    $page = publishedPage($mine, [
        'booking_defaults' => ['assignment_mode' => 'user', 'assigned_user_id' => $outsider->id],
    ]);

    $this->postJson("/api/public/booking/{$page->public_slug}/book", [
        'starts_at' => bookingDate() . ' 10:00:00',
        'answers'   => ['name' => 'Ada', 'email' => 'ada@example.com'],
    ])->assertCreated();

    expect(Lead::where('company_id', $mine->id)->first()->assigned_to)->not->toBe($outsider->id);
});

test('CRM targets from another company are refused when configuring', function () {
    $mine   = bookingCompany('Mine');
    $theirs = bookingCompany('Theirs');

    $me       = bookingUser($mine);
    $outsider = bookingUser($theirs);
    [$foreignFunnel] = bookingFunnel($theirs, $outsider);

    $calendar = Calendar::defaultFor($mine->id);

    $this->actingAs($me)->putJson("/api/calendars/{$calendar->id}/booking", [
        'booking_defaults' => ['funnel_id' => $foreignFunnel->id],
    ])->assertStatus(422);

    $this->actingAs($me)->putJson("/api/calendars/{$calendar->id}/booking", [
        'booking_defaults' => ['assignment_mode' => 'user', 'assigned_user_id' => $outsider->id],
    ])->assertStatus(422);
});

test('a stage from a different funnel is refused', function () {
    $company = bookingCompany();
    $user    = bookingUser($company);

    [$funnelA]          = bookingFunnel($company, $user);
    [, $stageOfFunnelB] = bookingFunnel($company, $user);

    $calendar = Calendar::defaultFor($company->id);

    $this->actingAs($user)->putJson("/api/calendars/{$calendar->id}/booking", [
        'booking_defaults' => ['funnel_id' => $funnelA->id, 'stage_id' => $stageOfFunnelB->id],
    ])->assertStatus(422);
});
