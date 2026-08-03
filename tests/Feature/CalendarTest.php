<?php

use App\Models\Appointment;
use App\Models\Calendar;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Multi-calendar architecture: one company owns many calendars, always has
 * exactly one default, and nothing scheduled is ever orphaned.
 */
uses(RefreshDatabase::class);

function calCompany(string $name = 'Calendar Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

function calUser(Company $company, string $role = 'admin'): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'cal-' . $company->id . '-' . uniqid() . '@example.test',
        'role'       => $role,
    ]);
}

/**
 * Runs the backfill migration's up() directly.
 *
 * `migrate --path` would be a no-op here: the migration is already recorded as
 * run by RefreshDatabase, so Laravel would skip it and the idempotency test
 * would pass without executing anything.
 */
function runBackfill(): void
{
    (require base_path('database/migrations/2026_08_03_000007_backfill_default_calendars.php'))->up();
}

function makeAppointment(Company $company, ?Calendar $calendar = null): Appointment
{
    return Appointment::create([
        'company_id'   => $company->id,
        'calendar_id'  => $calendar?->id,
        'title'        => 'Discovery call',
        'scheduled_at' => now()->addDay(),
        'timezone'     => 'UTC',
        'status'       => 'scheduled',
    ]);
}

/* ---------------- default calendar ---------------- */

test('creating a company creates exactly one default calendar', function () {
    $company = calCompany();

    $calendars = Calendar::where('company_id', $company->id)->get();

    expect($calendars)->toHaveCount(1)
        ->and($calendars->first()->is_default)->toBeTrue()
        ->and($calendars->first()->type)->toBe('company')
        ->and($calendars->first()->is_active)->toBeTrue();
});

test('the default calendar is recreated if it goes missing', function () {
    $company = calCompany();

    Calendar::where('company_id', $company->id)->forceDelete();

    $calendar = Calendar::defaultFor($company->id);

    expect($calendar->is_default)->toBeTrue()
        ->and($calendar->company_id)->toBe($company->id);
});

test('a company with calendars but no default promotes one instead of adding another', function () {
    $company = calCompany();

    // Simulates a company whose default flag was lost out of band.
    Calendar::where('company_id', $company->id)->update(['is_default' => false]);

    $calendar = Calendar::defaultFor($company->id);

    expect($calendar->is_default)->toBeTrue()
        ->and(Calendar::where('company_id', $company->id)->count())->toBe(1);
});

/* ---------------- migration safety ---------------- */

test('the backfill migration is idempotent and never creates a second default', function () {
    $company = calCompany();
    $before  = Calendar::where('company_id', $company->id)->count();

    // The requirement is explicit: rerunning must not duplicate defaults.
    runBackfill();
    runBackfill();

    expect(Calendar::where('company_id', $company->id)->count())->toBe($before)
        ->and(Calendar::where('company_id', $company->id)->where('is_default', true)->count())->toBe(1);
});

test('the backfill files pre-existing scheduling data without touching its other columns', function () {
    $company = calCompany();
    $user    = calUser($company);

    $appointment = makeAppointment($company);
    // Unfile it, as it would have been before calendars existed.
    DB::table('appointments')->where('id', $appointment->id)->update(['calendar_id' => null]);

    $original = DB::table('appointments')->where('id', $appointment->id)->first();

    runBackfill();

    $after = DB::table('appointments')->where('id', $appointment->id)->first();

    expect($after->calendar_id)->toBe(Calendar::defaultFor($company->id)->id)
        // Ownership, timestamps and relationships all survive untouched.
        ->and($after->title)->toBe($original->title)
        ->and($after->company_id)->toBe($original->company_id)
        ->and($after->created_at)->toBe($original->created_at)
        ->and($after->scheduled_at)->toBe($original->scheduled_at);
});

test('the backfill leaves records that already sit on another calendar alone', function () {
    $company = calCompany();
    $user    = calUser($company);

    $other = Calendar::create(['company_id' => $company->id, 'name' => 'Sales Team']);
    $appointment = makeAppointment($company, $other);

    runBackfill();

    expect($appointment->fresh()->calendar_id)->toBe($other->id);
});

/* ---------------- CRUD ---------------- */

test('a company can create and list several calendars', function () {
    $company = calCompany();
    $user    = calUser($company);

    foreach (['Sales Team', 'Customer Success', 'Marketing'] as $name) {
        $this->actingAs($user)->postJson('/api/calendars', ['name' => $name])->assertCreated();
    }

    $res = $this->actingAs($user)->getJson('/api/calendars');

    $res->assertOk();
    // Three new ones plus the company default.
    expect($res->json('data'))->toHaveCount(4)
        // Default first so the switcher opens on it.
        ->and($res->json('data.0.is_default'))->toBeTrue();
});

test('the first calendar in a company is default, later ones are not', function () {
    $company = calCompany();
    $user    = calUser($company);

    $res = $this->actingAs($user)->postJson('/api/calendars', ['name' => 'Marketing']);

    expect($res->json('data.is_default'))->toBeFalse();
});

test('promoting a calendar demotes the previous default', function () {
    $company  = calCompany();
    $user     = calUser($company);
    $original = Calendar::defaultFor($company->id);

    $new = $this->actingAs($user)->postJson('/api/calendars', ['name' => 'Sales'])->json('data.id');

    $this->actingAs($user)->patchJson("/api/calendars/{$new}/default")->assertOk();

    expect(Calendar::find($new)->is_default)->toBeTrue()
        ->and($original->fresh()->is_default)->toBeFalse()
        // The invariant: exactly one, always.
        ->and(Calendar::where('company_id', $company->id)->where('is_default', true)->count())->toBe(1);
});

test('a calendar can be renamed, recoloured and archived', function () {
    $company = calCompany();
    $user    = calUser($company);

    $id = $this->actingAs($user)->postJson('/api/calendars', ['name' => 'Field Techs'])->json('data.id');

    $this->actingAs($user)->putJson("/api/calendars/{$id}", [
        'name'      => 'Service Technicians',
        'color'     => '#10B981',
        'is_active' => false,
    ])->assertOk();

    $calendar = Calendar::find($id);
    expect($calendar->name)->toBe('Service Technicians')
        ->and($calendar->color)->toBe('#10B981')
        ->and($calendar->is_active)->toBeFalse();

    // Archived calendars stay out of the switcher but remain reachable.
    expect($this->actingAs($user)->getJson('/api/calendars')->json('data'))->toHaveCount(1)
        ->and($this->actingAs($user)->getJson('/api/calendars?include_archived=1')->json('data'))->toHaveCount(2);
});

test('the default calendar cannot be archived or deleted', function () {
    $company = calCompany();
    $user    = calUser($company);
    $default = Calendar::defaultFor($company->id);

    $this->actingAs($user)->putJson("/api/calendars/{$default->id}", ['is_active' => false])->assertOk();
    expect($default->fresh()->is_active)->toBeTrue();

    $this->actingAs($user)->deleteJson("/api/calendars/{$default->id}")->assertStatus(422);
    expect(Calendar::find($default->id))->not->toBeNull();
});

test('deleting a calendar moves its appointments and tasks to the default rather than destroying them', function () {
    $company = calCompany();
    $user    = calUser($company);
    $default = Calendar::defaultFor($company->id);

    $doomed = Calendar::create(['company_id' => $company->id, 'name' => 'Temporary']);
    $appointment = makeAppointment($company, $doomed);

    $task = Task::create([
        'company_id'  => $company->id,
        'calendar_id' => $doomed->id,
        'title'       => 'Follow up',
        'due_date'    => now()->addDays(2),
        'created_by'  => $user->id,
    ]);

    $this->actingAs($user)->deleteJson("/api/calendars/{$doomed->id}")->assertOk();

    // The container goes; its contents are re-homed, not deleted.
    expect(Appointment::find($appointment->id))->not->toBeNull()
        ->and($appointment->fresh()->calendar_id)->toBe($default->id)
        ->and(Task::find($task->id))->not->toBeNull()
        ->and($task->fresh()->calendar_id)->toBe($default->id);
});

/* ---------------- company isolation ---------------- */

test('one company cannot see, edit or delete another company calendar', function () {
    $mine   = calCompany('Mine');
    $theirs = calCompany('Theirs');

    $me       = calUser($mine);
    $outsider = calUser($theirs);

    $id = $this->actingAs($me)->postJson('/api/calendars', ['name' => 'Private Sales'])->json('data.id');

    // Their listing never includes it...
    $theirIds = collect($this->actingAs($outsider)->getJson('/api/calendars')->json('data'))->pluck('id');
    expect($theirIds)->not->toContain($id);

    // ...and addressing it directly is refused.
    $this->actingAs($outsider)->getJson("/api/calendars/{$id}")->assertForbidden();
    $this->actingAs($outsider)->putJson("/api/calendars/{$id}", ['name' => 'Hijacked'])->assertForbidden();
    $this->actingAs($outsider)->patchJson("/api/calendars/{$id}/default")->assertForbidden();
    $this->actingAs($outsider)->deleteJson("/api/calendars/{$id}")->assertForbidden();

    expect(Calendar::find($id)->name)->toBe('Private Sales');
});

test('a calendar owner must belong to the same company', function () {
    $mine   = calCompany('Mine');
    $theirs = calCompany('Theirs');

    $me       = calUser($mine);
    $outsider = calUser($theirs);

    $this->actingAs($me)
        ->postJson('/api/calendars', ['name' => 'Rep calendar', 'owner_id' => $outsider->id])
        ->assertStatus(422);
});

test('private calendars are hidden from other members but visible to admins', function () {
    $company = calCompany();
    $owner   = calUser($company, 'agent');
    $peer    = calUser($company, 'agent');
    $admin   = calUser($company, 'admin');

    $calendar = Calendar::create([
        'company_id' => $company->id,
        'name'       => 'My schedule',
        'visibility' => 'private',
        'owner_id'   => $owner->id,
    ]);

    $visibleTo = fn (User $user) => collect(
        $this->actingAs($user)->getJson('/api/calendars')->json('data')
    )->pluck('id');

    expect($visibleTo($owner))->toContain($calendar->id)
        ->and($visibleTo($peer))->not->toContain($calendar->id)
        ->and($visibleTo($admin))->toContain($calendar->id);
});

/* ---------------- appointments on calendars ---------------- */

test('an appointment created without a calendar lands on the default', function () {
    $company = calCompany();
    $user    = calUser($company);

    $res = $this->actingAs($user)->postJson('/api/appointments', [
        'title'        => 'Intro call',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
    ]);

    $res->assertCreated();
    expect($res->json('calendar_id'))->toBe(Calendar::defaultFor($company->id)->id);
});

test('an appointment can be created on and moved between calendars', function () {
    $company = calCompany();
    $user    = calUser($company);
    $sales   = Calendar::create(['company_id' => $company->id, 'name' => 'Sales']);
    $success = Calendar::create(['company_id' => $company->id, 'name' => 'Customer Success']);

    $id = $this->actingAs($user)->postJson('/api/appointments', [
        'title'        => 'Demo',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'calendar_id'  => $sales->id,
    ])->json('id');

    expect(Appointment::find($id)->calendar_id)->toBe($sales->id);

    $this->actingAs($user)->putJson("/api/appointments/{$id}", ['calendar_id' => $success->id])->assertOk();

    expect(Appointment::find($id)->calendar_id)->toBe($success->id);
});

test('another company calendar id on an appointment falls back to the caller default', function () {
    $mine   = calCompany('Mine');
    $theirs = calCompany('Theirs');

    $me      = calUser($mine);
    $foreign = Calendar::defaultFor($theirs->id);

    $res = $this->actingAs($me)->postJson('/api/appointments', [
        'title'        => 'Sneaky',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'calendar_id'  => $foreign->id,
    ]);

    $res->assertCreated();
    expect($res->json('calendar_id'))->toBe(Calendar::defaultFor($mine->id)->id)
        ->and($res->json('calendar_id'))->not->toBe($foreign->id);
});

test('the calendar feed can be filtered to one calendar', function () {
    $company = calCompany();
    $user    = calUser($company);
    $sales   = Calendar::create(['company_id' => $company->id, 'name' => 'Sales']);

    makeAppointment($company, Calendar::defaultFor($company->id));
    makeAppointment($company, $sales);

    $all = $this->actingAs($user)->getJson('/api/appointments')->json();
    expect($all)->toHaveCount(2);

    $filtered = $this->actingAs($user)->getJson("/api/appointments?calendar_id={$sales->id}")->json();
    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['calendar_id'])->toBe($sales->id);
});

test('filtering the feed by another company calendar returns nothing', function () {
    $mine   = calCompany('Mine');
    $theirs = calCompany('Theirs');

    $me = calUser($mine);
    makeAppointment($mine, Calendar::defaultFor($mine->id));

    $foreign = Calendar::defaultFor($theirs->id);

    expect($this->actingAs($me)->getJson("/api/appointments?calendar_id={$foreign->id}")->json())
        ->toBeEmpty();
});

test('calendars require authentication', function () {
    $this->getJson('/api/calendars')->assertUnauthorized();
    $this->postJson('/api/calendars', ['name' => 'X'])->assertUnauthorized();
});

test('deleting a company removes its calendars', function () {
    $company = calCompany();
    $company->forceDelete();

    expect(Calendar::withTrashed()->where('company_id', $company->id)->exists())->toBeFalse();
});
