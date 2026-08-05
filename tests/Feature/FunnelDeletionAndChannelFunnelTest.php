<?php

use App\Models\Company;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\User;
use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function funnelCompany(string $name = 'Funnel Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

function funnelAdmin(Company $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'email'      => "admin-{$company->id}@example.test",
        'role'       => 'admin',
    ]);
}

/** A funnel with one entry stage. `type` decides contact vs deal pipeline. */
function makeFunnel(Company $company, User $owner, string $type = 'contact', string $name = 'Inbound'): array
{
    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name'       => $name,
        'type'       => $type,
        'is_active'  => true,
        'created_by' => $owner->id,
    ]);

    $stage = Stage::create([
        'funnel_id'  => $funnel->id,
        'name'       => 'Entry',
        'type'       => 'entry',
        'order'      => 1,
        'is_active'  => true,
        'created_by' => $owner->id,
    ]);

    return [$funnel, $stage];
}

function makeLead(Company $company, Funnel $funnel, Stage $stage, string $name): Lead
{
    return Lead::create([
        'company_id' => $company->id,
        'name'       => $name,
        'funnel_id'  => $funnel->id,
        'stage_id'   => $stage->id,
    ]);
}

/* ---------------- deleting a funnel keeps its contacts ---------------- */

test('the impact endpoint counts the contacts that would be unfiled', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);
    [$funnel, $stage] = makeFunnel($company, $admin);

    collect(['Ana', 'Bruno', 'Carla'])->each(fn ($n) => makeLead($company, $funnel, $stage, $n));

    test()->actingAs($admin)
        ->getJson("/api/funnels/{$funnel->id}/delete-impact")
        ->assertOk()
        ->assertJson(['lead_count' => 3]);
});

test('the impact endpoint counts only this funnel', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);
    [$funnel, $stage] = makeFunnel($company, $admin);
    [$other, $otherStage] = makeFunnel($company, $admin, 'contact', 'Outbound');

    makeLead($company, $funnel, $stage, 'Counted');
    makeLead($company, $other, $otherStage, 'Not counted');

    test()->actingAs($admin)
        ->getJson("/api/funnels/{$funnel->id}/delete-impact")
        ->assertOk()
        ->assertJson(['lead_count' => 1]);
});

test('another company cannot read a funnel impact', function () {
    $owner = funnelCompany('Owner');
    [$funnel] = makeFunnel($owner, funnelAdmin($owner));

    $intruder = funnelCompany('Intruder');

    test()->actingAs(funnelAdmin($intruder))
        ->getJson("/api/funnels/{$funnel->id}/delete-impact")
        ->assertNotFound();
});

test('deleting a funnel keeps its contacts and unfiles them', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);
    [$funnel, $stage] = makeFunnel($company, $admin);

    $lead = makeLead($company, $funnel, $stage, 'Survivor');

    test()->actingAs($admin)
        ->deleteJson("/api/funnels/{$funnel->id}")
        ->assertOk()
        ->assertJson(['unfiled_leads' => 1]);

    $lead->refresh();

    expect($lead->exists)->toBeTrue()
        ->and($lead->funnel_id)->toBeNull()
        // The stage has to go with the funnel — it belongs to a funnel that no
        // longer exists, so keeping the id would dangle.
        ->and($lead->stage_id)->toBeNull();
});

test('deleting a funnel leaves other funnels contacts alone', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);
    [$doomed, $doomedStage] = makeFunnel($company, $admin);
    [$kept, $keptStage] = makeFunnel($company, $admin, 'contact', 'Outbound');

    $moved = makeLead($company, $doomed, $doomedStage, 'Moved');
    $untouched = makeLead($company, $kept, $keptStage, 'Untouched');

    test()->actingAs($admin)->deleteJson("/api/funnels/{$doomed->id}")->assertOk();

    expect($moved->fresh()->funnel_id)->toBeNull()
        ->and($untouched->fresh()->funnel_id)->toBe($kept->id)
        ->and($untouched->fresh()->stage_id)->toBe($keptStage->id);
});

test('deleting a funnel does not disconnect the WhatsApp account using it', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);
    [$funnel] = makeFunnel($company, $admin);

    $account = WhatsAppInstance::create([
        'company_id'                   => $company->id,
        'whats_app_evo_integration_id' => WhatsAppEvoIntegration::forCompany($company->id)->id,
        'instance_name'                => 'acct_AAAAAA',
        'status'                       => 'connected',
        'funnel_id'                    => $funnel->id,
    ]);

    test()->actingAs($admin)->deleteJson("/api/funnels/{$funnel->id}")->assertOk();

    $account->refresh();

    expect($account->exists)->toBeTrue()
        ->and($account->status)->toBe('connected')
        ->and($account->funnel_id)->toBeNull();
});

/* ---------------- only contact funnels reach the channel picker ---------------- */

test('the integrations settings list offers contact funnels only', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);

    [$contact] = makeFunnel($company, $admin, 'contact', 'Contacts');
    [$deal]    = makeFunnel($company, $admin, 'deal', 'Deals');

    $names = collect(
        test()->actingAs($admin)->getJson('/api/integrations/available/settings')->json('funnels')
    )->pluck('name');

    expect($names)->toContain($contact->name)
        ->and($names)->not->toContain($deal->name);
});

test('another company funnels never appear in the list', function () {
    $company = funnelCompany('Mine');
    $admin = funnelAdmin($company);
    makeFunnel($company, $admin, 'contact', 'Mine Inbound');

    $other = funnelCompany('Theirs');
    [$foreign] = makeFunnel($other, funnelAdmin($other), 'contact', 'Theirs Inbound');

    $names = collect(
        test()->actingAs($admin)->getJson('/api/integrations/available/settings')->json('funnels')
    )->pluck('name');

    expect($names)->not->toContain($foreign->name);
});

/* ---------------- an account may have no funnel ---------------- */

function makeAccount(Company $company, ?Funnel $funnel = null): WhatsAppInstance
{
    return WhatsAppInstance::create([
        'company_id'                   => $company->id,
        'whats_app_evo_integration_id' => WhatsAppEvoIntegration::forCompany($company->id)->id,
        'instance_name'                => 'acct_' . strtoupper(bin2hex(random_bytes(3))),
        'status'                       => 'connected',
        'funnel_id'                    => $funnel?->id,
    ]);
}

test('an account can be detached from its funnel', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);
    [$funnel] = makeFunnel($company, $admin);

    $account = makeAccount($company, $funnel);

    test()->actingAs($admin)
        ->putJson("/api/integrations/whatsapp-evo-integrations/{$account->id}/funnel", [
            'funnel_id' => null,
        ])
        ->assertOk();

    expect($account->fresh()->funnel_id)->toBeNull();
});

test('an omitted funnel_id detaches rather than erroring', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);
    [$funnel] = makeFunnel($company, $admin);

    $account = makeAccount($company, $funnel);

    test()->actingAs($admin)
        ->putJson("/api/integrations/whatsapp-evo-integrations/{$account->id}/funnel", [])
        ->assertOk();

    expect($account->fresh()->funnel_id)->toBeNull();
});

test('a contact funnel can still be attached', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);
    [$funnel] = makeFunnel($company, $admin);

    $account = makeAccount($company);

    test()->actingAs($admin)
        ->putJson("/api/integrations/whatsapp-evo-integrations/{$account->id}/funnel", [
            'funnel_id' => $funnel->id,
        ])
        ->assertOk();

    expect($account->fresh()->funnel_id)->toBe($funnel->id);
});

test('a deal funnel is refused', function () {
    $company = funnelCompany();
    $admin = funnelAdmin($company);
    [$deal] = makeFunnel($company, $admin, 'deal', 'Deals');

    $account = makeAccount($company);

    test()->actingAs($admin)
        ->putJson("/api/integrations/whatsapp-evo-integrations/{$account->id}/funnel", [
            'funnel_id' => $deal->id,
        ])
        ->assertStatus(422);

    expect($account->fresh()->funnel_id)->toBeNull();
});

test('another company funnel is refused', function () {
    $company = funnelCompany('Mine');
    $admin = funnelAdmin($company);
    $account = makeAccount($company);

    $other = funnelCompany('Theirs');
    [$foreign] = makeFunnel($other, funnelAdmin($other));

    test()->actingAs($admin)
        ->putJson("/api/integrations/whatsapp-evo-integrations/{$account->id}/funnel", [
            'funnel_id' => $foreign->id,
        ])
        ->assertStatus(422);

    expect($account->fresh()->funnel_id)->toBeNull();
});
