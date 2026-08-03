<?php

use App\Models\Company;
use App\Models\Lead;
use App\Models\Observation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function noteCompany(string $name = 'Notes Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

function noteUser(Company $company, string $handle = 'agent', string $role = 'agent'): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'role'       => $role,
        'email'      => "{$handle}-{$company->id}@example.test",
        'name'       => ucfirst($handle),
    ]);
}

function noteLead(Company $company): Lead
{
    return Lead::create([
        'company_id'      => $company->id,
        'first_name'      => 'Nina',
        'source_platform' => 'website',
        'source_type'     => 'manual',
    ]);
}

function noteDeal(Company $company, Lead $lead, User $owner): Sale
{
    // sales.funnel_id and stage_id are NOT NULL, so a deal needs a pipeline.
    $funnel = App\Models\Funnel::create([
        'company_id' => $company->id, 'name' => 'Sales', 'type' => 'deal',
        'is_active' => true, 'created_by' => $owner->id,
    ]);
    $stage = App\Models\Stage::create([
        'funnel_id' => $funnel->id, 'name' => 'Discovery',
        'type' => 'entry', 'order' => 1, 'is_active' => true,
    ]);

    return Sale::create([
        'company_id'    => $company->id,
        'funnel_id'     => $funnel->id,
        'stage_id'      => $stage->id,
        'lead_id'       => $lead->id,
        'user_id'       => $owner->id,
        'name'          => 'Annual licence',
        'status'        => 'pending',
        'pricing_model' => 'product',
        'subtotal'      => 1000,
        'discount_total' => 0,
        'total'         => 1000,
        'currency'      => 'BRL',
    ]);
}

test('a note can be attached to a deal', function () {
    $company = noteCompany();
    $user = noteUser($company);
    $deal = noteDeal($company, noteLead($company), $user);

    $res = test()->actingAs($user)->postJson('/api/observations', [
        'notable_type' => 'deal',
        'notable_id'   => $deal->id,
        'note'         => 'Customer wants net-60 terms.',
    ]);

    $res->assertCreated();

    $note = Observation::firstOrFail();
    expect($note->notable_type)->toBe('Sale')
        ->and($note->notable_id)->toBe($deal->id)
        ->and($note->company_id)->toBe($company->id)
        // A deal note also records the lead behind it, so lead views can find it.
        ->and($note->lead_id)->toBe($deal->lead_id);
});

test('the legacy lead_id shape still works', function () {
    $company = noteCompany();
    $user = noteUser($company);
    $lead = noteLead($company);

    test()->actingAs($user)->postJson('/api/observations', [
        'lead_id' => $lead->id,
        'note'    => 'Called, no answer.',
    ])->assertCreated();

    $note = Observation::firstOrFail();
    expect($note->notable_type)->toBe('Lead')
        ->and($note->notable_id)->toBe($lead->id);
});

test('replies nest under the note they answer', function () {
    $company = noteCompany();
    $alice = noteUser($company, 'alice');
    $bob = noteUser($company, 'bob');
    $lead = noteLead($company);

    $root = test()->actingAs($alice)->postJson('/api/observations', [
        'lead_id' => $lead->id, 'note' => 'Should we discount?',
    ])->json('data.id');

    app('auth')->forgetGuards();
    test()->actingAs($bob)->postJson('/api/observations', [
        'parent_id' => $root, 'note' => 'Only if they sign this quarter.',
    ])->assertCreated();

    $res = test()->actingAs($bob)->getJson("/api/notes/lead/{$lead->id}");

    $res->assertOk();
    // One thread, not two entries in a flat list.
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.replies'))->toHaveCount(1)
        ->and($res->json('data.0.replies.0.note'))->toBe('Only if they sign this quarter.');
});

test('a reply inherits what its parent is attached to', function () {
    $company = noteCompany();
    $user = noteUser($company);
    $deal = noteDeal($company, noteLead($company), $user);

    $root = test()->actingAs($user)->postJson('/api/observations', [
        'notable_type' => 'deal', 'notable_id' => $deal->id, 'note' => 'Terms?',
    ])->json('data.id');

    test()->actingAs($user)->postJson('/api/observations', [
        // No notable given — the reply cannot land on a different record.
        'parent_id' => $root, 'note' => 'Net-60.',
    ])->assertCreated();

    $reply = Observation::where('parent_id', $root)->firstOrFail();
    expect($reply->notable_type)->toBe('Sale')
        ->and($reply->notable_id)->toBe($deal->id);
});

test('threads stay one level deep', function () {
    $company = noteCompany();
    $user = noteUser($company);
    $lead = noteLead($company);

    $root = test()->actingAs($user)->postJson('/api/observations', [
        'lead_id' => $lead->id, 'note' => 'Root',
    ])->json('data.id');

    $reply = test()->actingAs($user)->postJson('/api/observations', [
        'parent_id' => $root, 'note' => 'Reply',
    ])->json('data.id');

    // Replying to a reply joins the same thread rather than nesting deeper.
    test()->actingAs($user)->postJson('/api/observations', [
        'parent_id' => $reply, 'note' => 'Reply to the reply',
    ])->assertCreated();

    expect(Observation::where('parent_id', $root)->count())->toBe(2)
        ->and(Observation::where('parent_id', $reply)->count())->toBe(0);
});

test('mentions are stored and scoped to the company', function () {
    $company = noteCompany();
    $author = noteUser($company, 'author');
    $mate = noteUser($company, 'mate');
    $outsider = noteUser(noteCompany('Other'), 'outsider');
    $lead = noteLead($company);

    test()->actingAs($author)->postJson('/api/observations', [
        'lead_id' => $lead->id,
        'note'    => "cc @[{$mate->id}] and @[{$outsider->id}]",
    ])->assertCreated();

    // A mention is not a way to reach into another tenant.
    expect(Observation::firstOrFail()->mentions)->toBe([$mate->id]);
});

test('editing stamps edited_at and only the author may do it', function () {
    $company = noteCompany();
    $author = noteUser($company, 'author');
    $other = noteUser($company, 'other');
    $lead = noteLead($company);

    $id = test()->actingAs($author)->postJson('/api/observations', [
        'lead_id' => $lead->id, 'note' => 'First draft',
    ])->json('data.id');

    expect(Observation::find($id)->edited_at)->toBeNull();

    app('auth')->forgetGuards();
    test()->actingAs($other)->putJson("/api/observations/{$id}", ['note' => 'Sneaky'])
        ->assertStatus(403);

    app('auth')->forgetGuards();
    test()->actingAs($author)->putJson("/api/observations/{$id}", ['note' => 'Second draft'])
        ->assertOk();

    $note = Observation::find($id);
    expect($note->note)->toBe('Second draft')
        ->and($note->edited_at)->not->toBeNull();
});

test('deleting a thread takes its replies with it', function () {
    $company = noteCompany();
    $user = noteUser($company);
    $lead = noteLead($company);

    $root = test()->actingAs($user)->postJson('/api/observations', [
        'lead_id' => $lead->id, 'note' => 'Root',
    ])->json('data.id');

    test()->actingAs($user)->postJson('/api/observations', [
        'parent_id' => $root, 'note' => 'Reply',
    ])->assertCreated();

    expect(Observation::count())->toBe(2);

    test()->actingAs($user)->deleteJson("/api/observations/{$root}")->assertOk();

    expect(Observation::count())->toBe(0);
});

test('an agent cannot delete someone else\'s note but a manager can', function () {
    $company = noteCompany();
    $author = noteUser($company, 'author');
    $peer = noteUser($company, 'peer');
    $manager = noteUser($company, 'manager', 'manager');
    $lead = noteLead($company);

    $id = test()->actingAs($author)->postJson('/api/observations', [
        'lead_id' => $lead->id, 'note' => 'Mine',
    ])->json('data.id');

    app('auth')->forgetGuards();
    test()->actingAs($peer)->deleteJson("/api/observations/{$id}")->assertStatus(403);

    app('auth')->forgetGuards();
    test()->actingAs($manager)->deleteJson("/api/observations/{$id}")->assertOk();
});

test('another company cannot read, edit or delete notes', function () {
    $company = noteCompany('Mine');
    $user = noteUser($company);
    $lead = noteLead($company);

    $id = test()->actingAs($user)->postJson('/api/observations', [
        'lead_id' => $lead->id, 'note' => 'Private',
    ])->json('data.id');

    $intruder = noteUser(noteCompany('Theirs'), 'intruder');

    app('auth')->forgetGuards();
    test()->actingAs($intruder)->getJson("/api/notes/lead/{$lead->id}")->assertStatus(404);

    app('auth')->forgetGuards();
    test()->actingAs($intruder)->putJson("/api/observations/{$id}", ['note' => 'x'])->assertStatus(404);

    app('auth')->forgetGuards();
    test()->actingAs($intruder)->deleteJson("/api/observations/{$id}")->assertStatus(404);

    expect(Observation::find($id)->note)->toBe('Private');
});

test('an unknown notable type is refused', function () {
    $company = noteCompany();
    $user = noteUser($company);

    test()->actingAs($user)->getJson('/api/notes/task/1')->assertStatus(404);

    test()->actingAs($user)->postJson('/api/observations', [
        'notable_type' => 'task', 'notable_id' => 1, 'note' => 'x',
    ])->assertStatus(422);
});
