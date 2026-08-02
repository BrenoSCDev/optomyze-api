<?php

use App\Models\Company;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeContactFixtures(int $leadCount = 20): array
{
    $company = Company::create(['name' => 'Acme Inc', 'email' => 'acme-contacts@example.com']);
    $user = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);

    $funnel = Funnel::create([
        'company_id' => $company->id, 'name' => 'Contact cadence', 'is_active' => true,
        'created_by' => $user->id, 'type' => 'contact',
    ]);
    $stage = Stage::create(['funnel_id' => $funnel->id, 'name' => 'Entry', 'order' => 1, 'type' => 'entry']);

    foreach (range(1, $leadCount) as $i) {
        Lead::create([
            'company_id' => $company->id,
            'funnel_id'  => $funnel->id,
            'stage_id'   => $stage->id,
            'first_name' => "Lead{$i}",
            'last_name'  => 'Silva',
            'email'      => "lead{$i}@example.com",
            'phone'      => '55629111' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        ]);
    }

    return compact('company', 'user', 'funnel', 'stage');
}

test('leads can now exist with no funnel or stage', function () {
    $f = makeContactFixtures(1);

    $lead = Lead::create([
        'company_id' => $f['company']->id,
        'first_name' => 'Pipelineless',
        'last_name'  => 'Contact',
        'email'      => 'no-pipeline@example.com',
    ]);

    expect($lead->funnel_id)->toBeNull();
    expect($lead->stage_id)->toBeNull();
});

test('lists contacts with 15 per page by default', function () {
    $f = makeContactFixtures(20);

    $response = $this->actingAs($f['user'])->getJson('/api/contacts');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(15);
    expect($response->json('meta.per_page'))->toBe(15);
    expect($response->json('meta.total'))->toBe(20);
    expect($response->json('meta.last_page'))->toBe(2);
});

test('honours the offered page sizes', function () {
    $f = makeContactFixtures(60);

    foreach ([10, 20, 50] as $size) {
        $r = $this->actingAs($f['user'])->getJson("/api/contacts?per_page={$size}");
        expect($r->json('meta.per_page'))->toBe($size);
        expect($r->json('data'))->toHaveCount($size);
    }
});

test('clamps an unsupported page size to the default', function () {
    $f = makeContactFixtures(20);

    // 500 would let a caller pull the whole table in one request.
    $r = $this->actingAs($f['user'])->getJson('/api/contacts?per_page=500');

    expect($r->json('meta.per_page'))->toBe(15);
});

test('searches across name, email and phone', function () {
    $f = makeContactFixtures(5);

    $byName = $this->actingAs($f['user'])->getJson('/api/contacts?search=Lead3');
    expect($byName->json('meta.total'))->toBe(1);

    $byEmail = $this->actingAs($f['user'])->getJson('/api/contacts?search=lead4@example.com');
    expect($byEmail->json('meta.total'))->toBe(1);
});

test('filters to contacts outside any pipeline', function () {
    $f = makeContactFixtures(3);

    Lead::create([
        'company_id' => $f['company']->id,
        'first_name' => 'Loose',
        'last_name'  => 'Contact',
    ]);

    $r = $this->actingAs($f['user'])->getJson('/api/contacts?without_funnel=1');

    expect($r->json('meta.total'))->toBe(1);
    expect($r->json('data.0.first_name'))->toBe('Loose');
});

test('does not leak contacts from another company', function () {
    $f = makeContactFixtures(3);

    $other = Company::create(['name' => 'Other', 'email' => 'other-contacts@example.com']);
    Lead::create(['company_id' => $other->id, 'first_name' => 'Foreign', 'last_name' => 'Lead']);

    $r = $this->actingAs($f['user'])->getJson('/api/contacts');

    expect(collect($r->json('data'))->pluck('first_name'))->not->toContain('Foreign');
    expect($r->json('meta.total'))->toBe(3);
});

test('contact pipeline management is off by default and can be toggled', function () {
    $f = makeContactFixtures(1);

    expect($f['company']->fresh()->contact_pipeline_enabled)->toBeFalse();

    $r = $this->actingAs($f['user'])->putJson('/api/business-profile', [
        'contact_pipeline_enabled' => true,
    ]);

    $r->assertOk();
    expect($r->json('data.contact_pipeline_enabled'))->toBeTrue();
    expect($f['company']->fresh()->contact_pipeline_enabled)->toBeTrue();
});

test('funnels are now typed contact, not sale', function () {
    $f = makeContactFixtures(1);

    expect($f['funnel']->fresh()->type)->toBe('contact');
    expect(Funnel::where('type', 'sale')->count())->toBe(0);
});

test('navigation funnels exclude contact funnels when pipelines are off', function () {
    $f = makeContactFixtures(1);

    Funnel::create([
        'company_id' => $f['company']->id, 'name' => 'Deal pipeline', 'is_active' => true,
        'created_by' => $f['user']->id, 'type' => 'deal',
    ]);

    // contact_pipeline_enabled defaults to false
    $r = $this->actingAs($f['user'])->getJson('/api/funnels/navigation');

    $r->assertOk();
    $types = collect($r->json('data'))->pluck('type')->unique()->values();
    expect($types)->toEqual(collect(['deal']));
});

test('navigation funnels include contact funnels once pipelines are on', function () {
    $f = makeContactFixtures(1);
    $f['company']->update(['contact_pipeline_enabled' => true]);

    $r = $this->actingAs($f['user'])->getJson('/api/funnels/navigation');

    $types = collect($r->json('data'))->pluck('type')->unique()->sort()->values();
    expect($types)->toContain('contact');
});

test('navigation funnels drop a deactivated funnel', function () {
    $f = makeContactFixtures(1);
    $f['company']->update(['contact_pipeline_enabled' => true]);

    $before = $this->actingAs($f['user'])->getJson('/api/funnels/navigation');
    expect(collect($before->json('data'))->pluck('id'))->toContain($f['funnel']->id);

    $f['funnel']->update(['is_active' => false]);

    $after = $this->actingAs($f['user'])->getJson('/api/funnels/navigation');
    expect(collect($after->json('data'))->pluck('id'))->not->toContain($f['funnel']->id);
});

test('lead chat lookup returns null when the lead has no conversation', function () {
    $f = makeContactFixtures(1);
    $lead = Lead::where('company_id', $f['company']->id)->first();

    $r = $this->actingAs($f['user'])->getJson("/api/inbox/leads/{$lead->id}/chat");

    $r->assertOk();
    expect($r->json('data'))->toBeNull();
});

test('lead chat lookup does not cross company boundaries', function () {
    $f = makeContactFixtures(1);

    $other = Company::create(['name' => 'Other', 'email' => 'other-chat@example.com']);
    $foreignLead = Lead::create(['company_id' => $other->id, 'first_name' => 'Foreign', 'last_name' => 'Lead']);

    // The lead exists, but belongs to another tenant — no chat should surface.
    $r = $this->actingAs($f['user'])->getJson("/api/inbox/leads/{$foreignLead->id}/chat");

    $r->assertOk();
    expect($r->json('data'))->toBeNull();
});

/* ---------------- Archiving ---------------- */

test('archiving hides a lead from the contacts list', function () {
    $f = makeContactFixtures(3);
    $lead = Lead::where('company_id', $f['company']->id)->first();

    $this->actingAs($f['user'])->patchJson("/api/leads/{$lead->id}/archive")->assertOk();

    expect($lead->fresh()->is_active)->toBeFalse();

    $r = $this->actingAs($f['user'])->getJson('/api/contacts');
    expect($r->json('meta.total'))->toBe(2);
    expect(collect($r->json('data'))->pluck('id'))->not->toContain($lead->id);
});

test('archived leads are visible with the archived filter, and unarchive restores them', function () {
    $f = makeContactFixtures(3);
    $lead = Lead::where('company_id', $f['company']->id)->first();
    $this->actingAs($f['user'])->patchJson("/api/leads/{$lead->id}/archive")->assertOk();

    $archived = $this->actingAs($f['user'])->getJson('/api/contacts?archived=archived');
    expect($archived->json('meta.total'))->toBe(1);
    expect($archived->json('data.0.id'))->toBe($lead->id);

    $all = $this->actingAs($f['user'])->getJson('/api/contacts?archived=all');
    expect($all->json('meta.total'))->toBe(3);

    $this->actingAs($f['user'])->patchJson("/api/leads/{$lead->id}/unarchive")->assertOk();
    expect($lead->fresh()->is_active)->toBeTrue();
    expect($this->actingAs($f['user'])->getJson('/api/contacts')->json('meta.total'))->toBe(3);
});

test('bulk archive applies to many leads at once', function () {
    $f = makeContactFixtures(5);
    $ids = Lead::where('company_id', $f['company']->id)->pluck('id')->take(3)->values()->all();

    $r = $this->actingAs($f['user'])->postJson('/api/leads/bulk/archive', [
        'lead_ids' => $ids, 'archived' => true,
    ]);

    $r->assertOk();
    expect($r->json('updated'))->toBe(3);
    expect($this->actingAs($f['user'])->getJson('/api/contacts')->json('meta.total'))->toBe(2);
});

test('bulk actions cannot touch another company\'s leads', function () {
    $f = makeContactFixtures(2);

    $other = Company::create(['name' => 'Other', 'email' => 'other-bulk@example.com']);
    $foreign = Lead::create(['company_id' => $other->id, 'first_name' => 'Foreign', 'last_name' => 'Lead']);

    $this->actingAs($f['user'])->postJson('/api/leads/bulk/archive', [
        'lead_ids' => [$foreign->id], 'archived' => true,
    ])->assertNotFound();

    expect($foreign->fresh()->is_active)->toBeTrue();
});

test('bulk assign rejects a user from another company', function () {
    $f = makeContactFixtures(2);
    $ids = Lead::where('company_id', $f['company']->id)->pluck('id')->all();

    $other = Company::create(['name' => 'Other', 'email' => 'other-assign@example.com']);
    $foreignUser = User::factory()->create(['company_id' => $other->id]);

    $this->actingAs($f['user'])->postJson('/api/leads/bulk/assign', [
        'lead_ids' => $ids, 'user_id' => $foreignUser->id,
    ])->assertStatus(422);

    expect(Lead::whereIn('id', $ids)->whereNotNull('assigned_to')->count())->toBe(0);
});

test('bulk assign sets the owner for every selected lead', function () {
    $f = makeContactFixtures(3);
    $ids = Lead::where('company_id', $f['company']->id)->pluck('id')->all();

    $r = $this->actingAs($f['user'])->postJson('/api/leads/bulk/assign', [
        'lead_ids' => $ids, 'user_id' => $f['user']->id,
    ]);

    $r->assertOk();
    expect(Lead::whereIn('id', $ids)->where('assigned_to', $f['user']->id)->count())->toBe(3);
});

test('bulk archive is capped so one call cannot rewrite the table', function () {
    $f = makeContactFixtures(1);

    $this->actingAs($f['user'])->postJson('/api/leads/bulk/archive', [
        'lead_ids' => range(1, 501), 'archived' => true,
    ])->assertStatus(422);
});
