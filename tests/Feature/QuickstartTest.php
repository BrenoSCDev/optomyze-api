<?php

use App\Models\Company;
use App\Models\CompanyQuickstart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The Quickstart onboarding checklist: one per company, created with the
 * company, ticked step by step, and dismissible.
 */
uses(RefreshDatabase::class);

function quickstartCompany(string $name = 'Quickstart Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

function quickstartUser(Company $company, string $role = 'admin'): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'user-' . $company->id . '-' . uniqid() . '@example.test',
        'role'       => $role,
    ]);
}

test('creating a company creates its quickstart checklist', function () {
    $company = quickstartCompany();

    $quickstart = CompanyQuickstart::where('company_id', $company->id)->first();

    expect($quickstart)->not->toBeNull()
        ->and($quickstart->completedSteps())->toBeEmpty()
        ->and($quickstart->isDismissed())->toBeFalse();
});

test('reading returns the whole checklist in order', function () {
    $user = quickstartUser(quickstartCompany());

    $res = $this->actingAs($user)->getJson('/api/quickstart');

    $res->assertOk();
    expect($res->json('data.steps'))->toBe(CompanyQuickstart::STEPS)
        ->and($res->json('data.total_steps'))->toBe(count(CompanyQuickstart::STEPS))
        ->and($res->json('data.completed_count'))->toBe(0)
        ->and($res->json('data.is_complete'))->toBeFalse()
        ->and($res->json('data.dismissed'))->toBeFalse();
});

test('a company whose checklist is missing still gets one on read', function () {
    $company = quickstartCompany();
    $user    = quickstartUser($company);

    // Safety net for rows inserted by a path that bypassed Eloquent events.
    CompanyQuickstart::where('company_id', $company->id)->delete();

    $this->actingAs($user)->getJson('/api/quickstart')->assertOk();

    expect(CompanyQuickstart::where('company_id', $company->id)->exists())->toBeTrue();
});

test('a step can be ticked and unticked', function () {
    $user = quickstartUser(quickstartCompany());

    $res = $this->actingAs($user)
        ->patchJson('/api/quickstart/steps/create_funnel', ['completed' => true]);

    $res->assertOk();
    expect($res->json('data.completed_steps'))->toBe(['create_funnel'])
        ->and($res->json('data.completed_count'))->toBe(1);

    $res = $this->actingAs($user)
        ->patchJson('/api/quickstart/steps/create_funnel', ['completed' => false]);

    expect($res->json('data.completed_steps'))->toBeEmpty();
});

test('ticking the same step twice does not duplicate it', function () {
    $user = quickstartUser(quickstartCompany());

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($user)
            ->patchJson('/api/quickstart/steps/chat_widget', ['completed' => true])
            ->assertOk();
    }

    $res = $this->actingAs($user)->getJson('/api/quickstart');

    expect($res->json('data.completed_steps'))->toBe(['chat_widget'])
        ->and($res->json('data.completed_count'))->toBe(1);
});

test('completed steps come back in checklist order, not the order they were ticked', function () {
    $user = quickstartUser(quickstartCompany());

    foreach (['chat_widget', 'create_funnel', 'add_team_member'] as $step) {
        $this->actingAs($user)->patchJson("/api/quickstart/steps/{$step}", ['completed' => true]);
    }

    $completed = $this->actingAs($user)->getJson('/api/quickstart')->json('data.completed_steps');

    expect($completed)->toBe(['create_funnel', 'add_team_member', 'chat_widget']);
});

test('an unknown step is refused', function () {
    $user = quickstartUser(quickstartCompany());

    $this->actingAs($user)
        ->patchJson('/api/quickstart/steps/buy_a_yacht', ['completed' => true])
        ->assertStatus(422);

    expect($this->actingAs($user)->getJson('/api/quickstart')->json('data.completed_steps'))
        ->toBeEmpty();
});

test('ticking every step marks the checklist complete but does not dismiss it', function () {
    $user = quickstartUser(quickstartCompany());

    foreach (CompanyQuickstart::STEPS as $step) {
        $this->actingAs($user)->patchJson("/api/quickstart/steps/{$step}", ['completed' => true]);
    }

    $res = $this->actingAs($user)->getJson('/api/quickstart');

    // Finishing the list is not the same as choosing to hide it — the user
    // still has to dismiss, which is what takes it out of the menu.
    expect($res->json('data.is_complete'))->toBeTrue()
        ->and($res->json('data.dismissed'))->toBeFalse();
});

test('dismissing hides the checklist and restoring keeps the ticked steps', function () {
    $user = quickstartUser(quickstartCompany());

    $this->actingAs($user)->patchJson('/api/quickstart/steps/create_funnel', ['completed' => true]);

    $res = $this->actingAs($user)->postJson('/api/quickstart/dismiss');
    $res->assertOk();
    expect($res->json('data.dismissed'))->toBeTrue()
        ->and($res->json('data.dismissed_at'))->not->toBeNull();

    $res = $this->actingAs($user)->postJson('/api/quickstart/dismiss', ['dismissed' => false]);

    expect($res->json('data.dismissed'))->toBeFalse()
        ->and($res->json('data.completed_steps'))->toBe(['create_funnel']);
});

test('the checklist is shared by the company, not per user', function () {
    $company = quickstartCompany();
    $alice   = quickstartUser($company);
    $bob     = quickstartUser($company, 'agent');

    $this->actingAs($alice)->patchJson('/api/quickstart/steps/welcome_message', ['completed' => true]);

    expect($this->actingAs($bob)->getJson('/api/quickstart')->json('data.completed_steps'))
        ->toBe(['welcome_message']);
});

test('one company cannot see or change another company checklist', function () {
    $mine   = quickstartCompany('Mine');
    $theirs = quickstartCompany('Theirs');

    $me       = quickstartUser($mine);
    $outsider = quickstartUser($theirs);

    $this->actingAs($me)->patchJson('/api/quickstart/steps/create_funnel', ['completed' => true]);

    // There is no id in the route: the outsider only ever reaches their own.
    expect($this->actingAs($outsider)->getJson('/api/quickstart')->json('data.completed_steps'))
        ->toBeEmpty();

    $this->actingAs($outsider)->postJson('/api/quickstart/dismiss')->assertOk();

    expect(CompanyQuickstart::where('company_id', $mine->id)->first()->isDismissed())->toBeFalse();
});

test('quickstart requires authentication', function () {
    $this->getJson('/api/quickstart')->assertUnauthorized();
    $this->postJson('/api/quickstart/dismiss')->assertUnauthorized();
});

test('deleting a company removes its checklist', function () {
    $company = quickstartCompany();

    $company->forceDelete();

    expect(CompanyQuickstart::where('company_id', $company->id)->exists())->toBeFalse();
});
