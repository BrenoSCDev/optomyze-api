<?php

use App\Models\Company;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePrefUser(string $email = 'prefs@example.com'): User
{
    $company = Company::create(['name' => 'Acme', 'email' => "co-{$email}"]);

    return User::factory()->create(['company_id' => $company->id, 'email' => $email]);
}

test('creating a user creates their preferences row', function () {
    $user = makePrefUser();

    // Created by the User model's `created` hook, not lazily on first read —
    // so the table is complete whether or not the user has ever logged in.
    expect(UserPreference::where('user_id', $user->id)->exists())->toBeTrue();
});

test('reading returns the shipped defaults', function () {
    $user = makePrefUser();

    $res = $this->actingAs($user)->getJson('/api/user/preferences');

    $res->assertOk();
    expect($res->json('data.theme'))->toBe('dark')
        ->and($res->json('data.language'))->toBe('en')
        ->and($res->json('data.optus_enabled'))->toBeFalse()
        ->and($res->json('data.notification_sound'))->toBe('default')
        ->and($res->json('data.default_search_target'))->toBe('leads');
});

test('a user whose row is missing still gets one on read', function () {
    $user = makePrefUser();

    // Safety net for rows deleted out of band, or a user inserted by a path
    // that bypassed Eloquent events (a raw insert or a SQL import).
    UserPreference::where('user_id', $user->id)->delete();

    $this->actingAs($user)->getJson('/api/user/preferences')->assertOk();

    expect(UserPreference::where('user_id', $user->id)->exists())->toBeTrue();
});

test('every user ends up with exactly one preferences row', function () {
    $users = collect(range(1, 3))->map(fn ($i) => makePrefUser("bulk{$i}@example.com"));

    // The unique constraint on user_id is what makes firstOrCreate safe; a
    // second read must not add a duplicate.
    $users->each(fn ($u) => $this->actingAs($u)->getJson('/api/user/preferences')->assertOk());
    $users->each(fn ($u) => $this->actingAs($u)->getJson('/api/user/preferences')->assertOk());

    expect(UserPreference::whereIn('user_id', $users->pluck('id'))->count())->toBe(3);
});

test('a partial update leaves the other preferences alone', function () {
    $user = makePrefUser();

    $this->actingAs($user)->putJson('/api/user/preferences', [
        'theme'    => 'light',
        'language' => 'pt-BR',
    ])->assertOk();

    // Sending one toggle must not reset the rest of the row.
    $this->actingAs($user)->putJson('/api/user/preferences', ['optus_enabled' => true])->assertOk();

    $prefs = UserPreference::where('user_id', $user->id)->first();
    expect($prefs->theme)->toBe('light')
        ->and($prefs->language)->toBe('pt-BR')
        ->and($prefs->optus_enabled)->toBeTrue()
        ->and($prefs->notification_sound)->toBe('default');
});

test('invalid values are rejected', function () {
    $user = makePrefUser();

    foreach ([
        ['theme' => 'sepia'],
        ['language' => 'klingon'],
        ['notification_sound' => 'airhorn'],
        ['default_search_target' => 'invoices'],
    ] as $payload) {
        $this->actingAs($user)->putJson('/api/user/preferences', $payload)->assertStatus(422);
    }

    // Nothing was written.
    expect(UserPreference::where('user_id', $user->id)->first()?->theme)->not->toBe('sepia');
});

test('menu is accepted as a search target', function () {
    $user = makePrefUser();

    $this->actingAs($user)
        ->putJson('/api/user/preferences', ['default_search_target' => 'menu'])
        ->assertOk();

    expect(UserPreference::where('user_id', $user->id)->first()->default_search_target)->toBe('menu');
});

test('preferences are per user and never leak between them', function () {
    $alice = makePrefUser('alice@example.com');
    $bob   = makePrefUser('bob@example.com');

    $this->actingAs($alice)->putJson('/api/user/preferences', ['theme' => 'light'])->assertOk();

    // Bob reads his own row, not Alice's — there is no id in the route to
    // address anyone else's preferences.
    expect($this->actingAs($bob)->getJson('/api/user/preferences')->json('data.theme'))->toBe('dark');
    expect($this->actingAs($alice)->getJson('/api/user/preferences')->json('data.theme'))->toBe('light');
});

test('reset restores the shipped defaults', function () {
    $user = makePrefUser();

    $this->actingAs($user)->putJson('/api/user/preferences', [
        'theme'                 => 'light',
        'optus_enabled'         => true,
        'default_search_target' => 'menu',
    ])->assertOk();

    $res = $this->actingAs($user)->deleteJson('/api/user/preferences');

    $res->assertOk();
    expect($res->json('data.theme'))->toBe('dark')
        ->and($res->json('data.optus_enabled'))->toBeFalse()
        ->and($res->json('data.default_search_target'))->toBe('leads');
});

test('preferences require authentication', function () {
    $this->getJson('/api/user/preferences')->assertUnauthorized();
    $this->putJson('/api/user/preferences', ['theme' => 'light'])->assertUnauthorized();
});

test('deleting a user removes their preferences', function () {
    $user = makePrefUser();
    $this->actingAs($user)->getJson('/api/user/preferences')->assertOk();

    $userId = $user->id;
    $user->forceDelete();

    expect(UserPreference::where('user_id', $userId)->exists())->toBeFalse();
});
