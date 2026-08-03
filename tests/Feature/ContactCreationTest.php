<?php

use App\Models\Company;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function contactCompany(string $name = 'Contacts Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

function contactAdmin(Company $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'role'       => 'admin',
        'email'      => "admin-{$company->id}@example.test",
    ]);
}

function contactFunnelWithStage(Company $company, User $owner): array
{
    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name'       => 'Inbound',
        'type'       => 'contact',
        'is_active'  => true,
        'created_by' => $owner->id,
    ]);

    $stage = Stage::create([
        'funnel_id' => $funnel->id,
        'name'      => 'New',
        'type'      => 'entry',
        'order'     => 1,
        'is_active' => true,
    ]);

    return [$funnel, $stage];
}

test('a contact can be created with no funnel at all', function () {
    $company = contactCompany();

    $res = test()->actingAs(contactAdmin($company))->postJson('/api/leads', [
        'company_id'      => $company->id,
        'first_name'      => 'Nina',
        'last_name'       => 'Unfiled',
        'email'           => 'nina@example.com',
        'source_platform' => 'website',
        'source_type'     => 'manual',
    ]);

    $res->assertCreated();

    $lead = Lead::firstOrFail();
    expect($lead->funnel_id)->toBeNull()
        ->and($lead->stage_id)->toBeNull()
        ->and($lead->company_id)->toBe($company->id);
});

test('the company comes from the session, not the request', function () {
    $mine = contactCompany('Mine');
    $theirs = contactCompany('Theirs');

    // A client that names someone else's company must not get one there.
    test()->actingAs(contactAdmin($mine))->postJson('/api/leads', [
        'company_id'      => $theirs->id,
        'first_name'      => 'Trojan',
        'source_platform' => 'other',
        'source_type'     => 'manual',
    ])->assertCreated();

    expect(Lead::firstOrFail()->company_id)->toBe($mine->id)
        ->and(Lead::where('company_id', $theirs->id)->count())->toBe(0);
});

test('a contact can be created straight into a funnel', function () {
    $company = contactCompany();
    $admin = contactAdmin($company);
    [$funnel, $stage] = contactFunnelWithStage($company, $admin);

    test()->actingAs($admin)->postJson('/api/leads', [
        'company_id'      => $company->id,
        'funnel_id'       => $funnel->id,
        'stage_id'        => $stage->id,
        'first_name'      => 'Filed',
        'source_platform' => 'other',
        'source_type'     => 'manual',
    ])->assertCreated();

    expect(Lead::firstOrFail()->stage_id)->toBe($stage->id);
});

test('another company\'s funnel is refused', function () {
    $mine = contactCompany('Mine');
    $theirs = contactCompany('Theirs');
    [$theirFunnel] = contactFunnelWithStage($theirs, contactAdmin($theirs));

    test()->actingAs(contactAdmin($mine))->postJson('/api/leads', [
        'company_id'      => $mine->id,
        'funnel_id'       => $theirFunnel->id,
        'first_name'      => 'Misfiled',
        'source_platform' => 'other',
        'source_type'     => 'manual',
    ])->assertStatus(422);

    expect(Lead::count())->toBe(0);
});

test('a stage from a different funnel is refused', function () {
    $company = contactCompany();
    $admin = contactAdmin($company);
    [$funnelA, ] = contactFunnelWithStage($company, $admin);

    $funnelB = Funnel::create([
        'company_id' => $company->id, 'name' => 'Other', 'type' => 'contact',
        'is_active' => true, 'created_by' => $admin->id,
    ]);
    $stageB = Stage::create([
        'funnel_id' => $funnelB->id, 'name' => 'B', 'type' => 'entry', 'order' => 1, 'is_active' => true,
    ]);

    test()->actingAs($admin)->postJson('/api/leads', [
        'company_id'      => $company->id,
        'funnel_id'       => $funnelA->id,
        'stage_id'        => $stageB->id,
        'first_name'      => 'Crossed',
        'source_platform' => 'other',
        'source_type'     => 'manual',
    ])->assertStatus(422);
});

test('an unfiled contact can be added to a funnel afterwards', function () {
    $company = contactCompany();
    $admin = contactAdmin($company);
    [$funnel, $stage] = contactFunnelWithStage($company, $admin);

    $lead = Lead::create([
        'company_id'      => $company->id,
        'first_name'      => 'Nina',
        'source_platform' => 'website',
        'source_type'     => 'manual',
    ]);

    // This is what the Contacts row action does.
    test()->actingAs($admin)
        ->postJson("/api/leads/{$lead->id}/move-funnel", ['funnel_id' => $funnel->id])
        ->assertOk();

    $lead->refresh();
    expect($lead->funnel_id)->toBe($funnel->id)
        // Lands in the funnel's entry stage.
        ->and($lead->stage_id)->toBe($stage->id);
});
