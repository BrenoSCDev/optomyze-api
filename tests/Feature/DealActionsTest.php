<?php

use App\Models\Company;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\Sale;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Deal-level actions: archive/restore, reassign, move funnel, delete.
 *
 * Archiving is the reversible counterpart to deleting — the deal keeps every
 * relation and stays in its stage, it just stops appearing on the board.
 */
uses(RefreshDatabase::class);

function dealCompany(string $name = 'Deal Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

/** A deal funnel with an entry stage, plus its owner. */
function makeDealFunnel(Company $company, string $name = 'Sales'): array
{
    $owner = User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'owner-' . $company->id . '-' . strtolower($name) . '@example.test',
        'role'       => 'admin',
    ]);

    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name'       => $name,
        'type'       => 'deal',
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

    return [$funnel, $stage, $owner];
}

function makeDeal(Company $company, User $owner, Funnel $funnel, Stage $stage): Sale
{
    $lead = Lead::create([
        'company_id' => $company->id,
        'first_name' => 'Deal',
        'last_name'  => 'Contact',
        'email'      => 'contact-' . uniqid() . '@example.test',
    ]);

    return Sale::create([
        'lead_id'       => $lead->id,
        'company_id'    => $company->id,
        'user_id'       => $owner->id,
        'name'          => 'Renewal',
        'status'        => 'in_pipeline',
        'pricing_model' => 'product',
        'funnel_id'     => $funnel->id,
        'stage_id'      => $stage->id,
    ]);
}

test('a new deal starts unarchived', function () {
    $company = dealCompany();
    [$funnel, $stage, $owner] = makeDealFunnel($company);

    expect(makeDeal($company, $owner, $funnel, $stage)->is_active)->toBeTrue();
});

test('archiving hides the deal from the board and restoring brings it back', function () {
    $company = dealCompany();
    [$funnel, $stage, $owner] = makeDealFunnel($company);
    $deal = makeDeal($company, $owner, $funnel, $stage);

    $board = fn () => $this->actingAs($owner)
        ->getJson("/api/funnels/{$funnel->id}/deals")
        ->json('funnel.stages.0.deals');

    expect($board())->toHaveCount(1);

    $this->actingAs($owner)
        ->patchJson("/api/sales/{$deal->id}/archive", ['archived' => true])
        ->assertOk();

    // Hidden, but not deleted: the row and its stage are untouched.
    $deal->refresh();
    expect($deal->is_active)->toBeFalse()
        ->and($deal->stage_id)->toBe($stage->id)
        ->and($board())->toBeEmpty();

    $this->actingAs($owner)
        ->patchJson("/api/sales/{$deal->id}/archive", ['archived' => false])
        ->assertOk();

    expect($board())->toHaveCount(1);
});

test('archived deals are reachable when explicitly asked for', function () {
    $company = dealCompany();
    [$funnel, $stage, $owner] = makeDealFunnel($company);
    $deal = makeDeal($company, $owner, $funnel, $stage);

    $this->actingAs($owner)
        ->patchJson("/api/sales/{$deal->id}/archive", ['archived' => true])
        ->assertOk();

    $archived = $this->actingAs($owner)
        ->getJson("/api/funnels/{$funnel->id}/deals?archived=archived")
        ->json('funnel.stages.0.deals');

    expect($archived)->toHaveCount(1)
        ->and($archived[0]['id'])->toBe($deal->id);
});

test('a deal moves to another deal funnel and lands on its entry stage', function () {
    $company = dealCompany();
    [$from, $fromStage, $owner] = makeDealFunnel($company, 'Sales');
    [$to, $toEntry] = makeDealFunnel($company, 'Renewals');

    $deal = makeDeal($company, $owner, $from, $fromStage);

    $this->actingAs($owner)
        ->postJson("/api/sales/{$deal->id}/move-funnel", ['funnel_id' => $to->id])
        ->assertOk();

    $deal->refresh();
    expect($deal->funnel_id)->toBe($to->id)
        ->and($deal->stage_id)->toBe($toEntry->id);
});

test('moving a deal into the funnel it already sits in is refused', function () {
    $company = dealCompany();
    [$funnel, $stage, $owner] = makeDealFunnel($company);
    $deal = makeDeal($company, $owner, $funnel, $stage);

    $this->actingAs($owner)
        ->postJson("/api/sales/{$deal->id}/move-funnel", ['funnel_id' => $funnel->id])
        ->assertStatus(422);
});

test('another company cannot archive, move, reassign or delete a deal', function () {
    $mine   = dealCompany('Mine');
    $theirs = dealCompany('Theirs');

    [$funnel, $stage, $owner]  = makeDealFunnel($mine);
    [$otherFunnel, , $outsider] = makeDealFunnel($theirs);

    $deal = makeDeal($mine, $owner, $funnel, $stage);

    $this->actingAs($outsider)
        ->patchJson("/api/sales/{$deal->id}/archive", ['archived' => true])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->postJson("/api/sales/{$deal->id}/move-funnel", ['funnel_id' => $otherFunnel->id])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->postJson("/api/sales/{$deal->id}/assign", ['user_id' => $outsider->id])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->deleteJson("/api/sales/{$deal->id}")
        ->assertForbidden();

    expect($deal->fresh()->is_active)->toBeTrue();
});

test('a deal cannot be moved into another company funnel', function () {
    $mine   = dealCompany('Mine');
    $theirs = dealCompany('Theirs');

    [$funnel, $stage, $owner] = makeDealFunnel($mine);
    [$foreignFunnel]          = makeDealFunnel($theirs);

    $deal = makeDeal($mine, $owner, $funnel, $stage);

    $this->actingAs($owner)
        ->postJson("/api/sales/{$deal->id}/move-funnel", ['funnel_id' => $foreignFunnel->id])
        ->assertStatus(422);

    expect($deal->fresh()->funnel_id)->toBe($funnel->id);
});

test('reassigning a deal changes its owner', function () {
    $company = dealCompany();
    [$funnel, $stage, $owner] = makeDealFunnel($company);

    $colleague = User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'colleague@example.test',
        'role'       => 'admin',
    ]);

    $deal = makeDeal($company, $owner, $funnel, $stage);

    $this->actingAs($owner)
        ->postJson("/api/sales/{$deal->id}/assign", ['user_id' => $colleague->id])
        ->assertOk();

    expect($deal->fresh()->user_id)->toBe($colleague->id);
});

test('deleting a deal removes it entirely, unlike archiving', function () {
    $company = dealCompany();
    [$funnel, $stage, $owner] = makeDealFunnel($company);
    $deal = makeDeal($company, $owner, $funnel, $stage);

    $this->actingAs($owner)
        ->deleteJson("/api/sales/{$deal->id}")
        ->assertOk();

    expect(Sale::find($deal->id))->toBeNull();
});
