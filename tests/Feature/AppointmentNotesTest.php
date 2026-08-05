<?php

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Observation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Threaded comments on appointments.
 *
 * Appointments reuse the polymorphic notes that leads and deals already use,
 * so replies, editing and deletion behave identically — these guard the new
 * notable rather than re-testing the thread mechanics.
 */
uses(RefreshDatabase::class);

function apptCompany(string $name = 'Appt Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

function apptUser(Company $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'appt-' . $company->id . '-' . uniqid() . '@example.test',
        'role'       => 'admin',
    ]);
}

function anAppointment(Company $company): Appointment
{
    return Appointment::create([
        'company_id'   => $company->id,
        'title'        => 'Discovery call',
        'scheduled_at' => now()->addDay(),
        'timezone'     => 'UTC',
        'status'       => 'scheduled',
    ]);
}

test('a user can comment on an appointment', function () {
    $company = apptCompany();
    $user    = apptUser($company);
    $appt    = anAppointment($company);

    $this->actingAs($user)->postJson('/api/observations', [
        'notable_type' => 'appointment',
        'notable_id'   => $appt->id,
        'note'         => 'Client asked to bring the pricing deck.',
    ])->assertCreated();

    $notes = $this->actingAs($user)->getJson("/api/notes/appointment/{$appt->id}")->json('data');

    expect($notes)->toHaveCount(1)
        ->and($notes[0]['note'])->toBe('Client asked to bring the pricing deck.');
});

test('users can reply to each other on an appointment', function () {
    $company = apptCompany();
    $alice   = apptUser($company);
    $bob     = apptUser($company);
    $appt    = anAppointment($company);

    $parent = $this->actingAs($alice)->postJson('/api/observations', [
        'notable_type' => 'appointment',
        'notable_id'   => $appt->id,
        'note'         => 'Do we know if they confirmed?',
    ])->json('data.id');

    $this->actingAs($bob)->postJson('/api/observations', [
        'parent_id' => $parent,
        'note'      => 'Yes — confirmed by email this morning.',
    ])->assertCreated();

    $notes = $this->actingAs($alice)->getJson("/api/notes/appointment/{$appt->id}")->json('data');

    // Replies nest under their parent rather than appearing as new threads.
    expect($notes)->toHaveCount(1)
        ->and($notes[0]['replies'])->toHaveCount(1)
        ->and($notes[0]['replies'][0]['note'])->toBe('Yes — confirmed by email this morning.');
});

test('another company cannot read or add comments on an appointment', function () {
    $mine   = apptCompany('Mine');
    $theirs = apptCompany('Theirs');

    $me       = apptUser($mine);
    $outsider = apptUser($theirs);
    $appt     = anAppointment($mine);

    $this->actingAs($me)->postJson('/api/observations', [
        'notable_type' => 'appointment',
        'notable_id'   => $appt->id,
        'note'         => 'Internal only.',
    ])->assertCreated();

    // "Not yours" and "does not exist" look the same from outside.
    $this->actingAs($outsider)->getJson("/api/notes/appointment/{$appt->id}")->assertNotFound();

    // The write path refuses through validation (422) rather than 404 — it
    // reports "Record not found" either way and never reveals the appointment.
    $this->actingAs($outsider)->postJson('/api/observations', [
        'notable_type' => 'appointment',
        'notable_id'   => $appt->id,
        'note'         => 'Sneaking in.',
    ])->assertStatus(422);

    expect(Observation::count())->toBe(1);
});

test('an unknown notable type is still refused', function () {
    $company = apptCompany();
    $user    = apptUser($company);
    $appt    = anAppointment($company);

    $this->actingAs($user)->getJson("/api/notes/calendar/{$appt->id}")->assertNotFound();
});

test('deleting an appointment takes its comments with it', function () {
    $company = apptCompany();
    $user    = apptUser($company);
    $appt    = anAppointment($company);

    $this->actingAs($user)->postJson('/api/observations', [
        'notable_type' => 'appointment',
        'notable_id'   => $appt->id,
        'note'         => 'Rescheduled once already.',
    ])->assertCreated();

    $this->actingAs($user)->deleteJson("/api/appointments/{$appt->id}")->assertNoContent();

    expect(Observation::where('notable_id', $appt->id)
        ->where('notable_type', 'Appointment')->count())->toBe(0);
});
