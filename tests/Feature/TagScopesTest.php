<?php

use App\Models\Company;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeTagFixtures(): array
{
    $company = Company::create(['name' => 'Acme', 'email' => 'acme-tags@example.com']);
    $user    = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);

    return compact('company', 'user');
}

test('a new tag defaults to every scope rather than none', function () {
    $f = makeTagFixtures();

    $res = $this->actingAs($f['user'])->postJson('/api/tags', [
        'company_id' => $f['company']->id,
        'name'       => 'Priority',
    ]);

    $res->assertCreated();
    // A tag scoped to nothing would be invisible in every picker.
    expect($res->json('data.scopes'))->toBe(['lead', 'deal', 'task']);
});

test('the scope filter returns only tags usable on that entity', function () {
    $f = makeTagFixtures();

    Tag::create(['company_id' => $f['company']->id, 'name' => 'Leads only',  'scopes' => ['lead']]);
    Tag::create(['company_id' => $f['company']->id, 'name' => 'Deals only',  'scopes' => ['deal']]);
    Tag::create(['company_id' => $f['company']->id, 'name' => 'Everywhere',  'scopes' => ['lead', 'deal', 'task']]);

    $names = fn (string $scope) => collect(
        $this->actingAs($f['user'])->getJson("/api/tags?scope={$scope}")->json('data')
    )->pluck('name')->sort()->values()->all();

    expect($names('lead'))->toBe(['Everywhere', 'Leads only'])
        ->and($names('deal'))->toBe(['Deals only', 'Everywhere'])
        ->and($names('task'))->toBe(['Everywhere']);
});

test('a tag predating scopes stays visible everywhere', function () {
    $f = makeTagFixtures();

    // Null scopes is what a pre-scoping row looks like; treating it as an empty
    // set would make the tag vanish from every picker.
    $tag = Tag::create(['company_id' => $f['company']->id, 'name' => 'Legacy']);
    $tag->update(['scopes' => null]);

    foreach (['lead', 'deal', 'task'] as $scope) {
        $names = collect($this->actingAs($f['user'])->getJson("/api/tags?scope={$scope}")->json('data'))
            ->pluck('name')->all();

        expect($names)->toContain('Legacy');
    }

    expect($tag->fresh()->appliesTo('deal'))->toBeTrue();
});

test('an unknown or missing scope returns everything rather than nothing', function () {
    $f = makeTagFixtures();
    Tag::create(['company_id' => $f['company']->id, 'name' => 'A', 'scopes' => ['lead']]);
    Tag::create(['company_id' => $f['company']->id, 'name' => 'B', 'scopes' => ['deal']]);

    expect($this->actingAs($f['user'])->getJson('/api/tags')->json('data'))->toHaveCount(2)
        ->and($this->actingAs($f['user'])->getJson('/api/tags?scope=nonsense')->json('data'))->toHaveCount(2);
});

test('scopes can be narrowed on update', function () {
    $f   = makeTagFixtures();
    $tag = Tag::create(['company_id' => $f['company']->id, 'name' => 'Narrowing', 'scopes' => ['lead', 'deal', 'task']]);

    $this->actingAs($f['user'])
        ->putJson("/api/tags/{$tag->id}", ['scopes' => ['deal']])
        ->assertOk();

    expect($tag->fresh()->scopes)->toBe(['deal'])
        ->and($tag->fresh()->appliesTo('lead'))->toBeFalse();

    expect($this->actingAs($f['user'])->getJson('/api/tags?scope=lead')->json('data'))->toHaveCount(0);
});

test('an invalid scope value is rejected', function () {
    $f = makeTagFixtures();

    $this->actingAs($f['user'])->postJson('/api/tags', [
        'company_id' => $f['company']->id,
        'name'       => 'Bad',
        'scopes'     => ['lead', 'invoice'],
    ])->assertStatus(422);
});

test('scope filtering does not leak tags across companies', function () {
    $mine   = makeTagFixtures();
    $theirs = ['company' => Company::create(['name' => 'Other', 'email' => 'other-tags@example.com'])];

    Tag::create(['company_id' => $mine['company']->id,   'name' => 'Mine',    'scopes' => ['lead']]);
    Tag::create(['company_id' => $theirs['company']->id, 'name' => 'Foreign', 'scopes' => ['lead']]);

    $names = collect($this->actingAs($mine['user'])->getJson('/api/tags?scope=lead')->json('data'))
        ->pluck('name')->all();

    expect($names)->toBe(['Mine']);
});

test('usage counts are returned only when asked for', function () {
    $f = makeTagFixtures();
    Tag::create(['company_id' => $f['company']->id, 'name' => 'Counted', 'scopes' => ['lead']]);

    $plain = $this->actingAs($f['user'])->getJson('/api/tags')->json('data.0');
    expect($plain)->not->toHaveKey('leads_count');

    $counted = $this->actingAs($f['user'])->getJson('/api/tags?with_counts=1')->json('data.0');
    expect($counted)->toHaveKeys(['leads_count', 'sales_count', 'tasks_count'])
        ->and($counted['leads_count'])->toBe(0);
});
