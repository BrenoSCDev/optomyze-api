<?php

use App\Models\Company;
use App\Models\Identity;
use App\Models\Lead;
use App\Models\User;
use App\Support\CompanySelectionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function makeCompany(string $name): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower($name) . '@example.test',
        'is_active' => true,
    ]);
}

/**
 * Sends the next request as the bearer of $token.
 *
 * The guard reset is essential, not cosmetic: Laravel resolves the guard once
 * per test case and caches the user on it, so a second call would keep
 * authenticating as whoever the first one resolved — and a revoked token would
 * appear to still work. Real requests each get a fresh container; tests do not.
 */
function asToken(Tests\TestCase $test, string $token): Tests\TestCase
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', "Bearer {$token}");
}

/**
 * A person with an account in each of the given companies. The identity is
 * created by the User model's `creating` hook off the first account, then
 * reused for the rest because the email matches.
 */
function makePerson(string $email, array $companies, string $password = 'secret123', string $role = 'admin'): Identity
{
    foreach ($companies as $company) {
        User::factory()->create([
            'company_id' => $company->id,
            'email'      => $email,
            'password'   => $password,
            'role'       => $role,
            'is_active'  => true,
        ]);
    }

    return Identity::where('email', $email)->firstOrFail();
}

test('a user created without an identity gets one automatically', function () {
    $user = User::factory()->create(['company_id' => makeCompany('Solo')->id, 'email' => 'solo@example.test']);

    expect($user->identity_id)->not->toBeNull()
        ->and($user->identity->email)->toBe('solo@example.test');
});

test('a second account for the same email reuses the identity', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');

    $identity = makePerson('multi@example.test', [$a, $b]);

    expect(Identity::where('email', 'multi@example.test')->count())->toBe(1)
        ->and($identity->accounts()->count())->toBe(2)
        ->and($identity->accounts->pluck('company_id')->sort()->values()->all())
        ->toBe([$a->id, $b->id]);
});

test('the same email may exist once per company but not twice in one', function () {
    $a = makeCompany('Alpha');

    User::factory()->create(['company_id' => $a->id, 'email' => 'dup@example.test']);

    expect(fn () => User::factory()->create(['company_id' => $a->id, 'email' => 'dup@example.test']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('a single-company login still returns a session directly', function () {
    makePerson('one@example.test', [makeCompany('Only')]);

    $res = $this->postJson('/api/auth/login', ['email' => 'one@example.test', 'password' => 'secret123']);

    $res->assertOk();
    expect($res->json('access_token'))->not->toBeNull()
        ->and($res->json('requires_company_selection'))->toBeNull();
});

test('a multi-company login asks which company', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    makePerson('multi@example.test', [$a, $b]);

    $res = $this->postJson('/api/auth/login', ['email' => 'multi@example.test', 'password' => 'secret123']);

    $res->assertOk();
    expect($res->json('requires_company_selection'))->toBeTrue()
        ->and($res->json('access_token'))->toBeNull()
        ->and($res->json('selection_token'))->not->toBeNull()
        ->and(collect($res->json('companies'))->pluck('company_id')->sort()->values()->all())
        ->toBe([$a->id, $b->id]);
});

test('selecting a company issues a session scoped to it', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    makePerson('multi@example.test', [$a, $b]);

    $login = $this->postJson('/api/auth/login', ['email' => 'multi@example.test', 'password' => 'secret123']);

    $res = $this->postJson('/api/auth/select-company', [
        'selection_token' => $login->json('selection_token'),
        'company_id'      => $b->id,
    ]);

    $res->assertOk();
    expect($res->json('user.company_id'))->toBe($b->id)
        ->and($res->json('access_token'))->not->toBeNull();
});

test('a selection token cannot buy access to a company the person is not in', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    $outsider = makeCompany('Gamma');
    makePerson('multi@example.test', [$a, $b]);

    $login = $this->postJson('/api/auth/login', ['email' => 'multi@example.test', 'password' => 'secret123']);

    $this->postJson('/api/auth/select-company', [
        'selection_token' => $login->json('selection_token'),
        'company_id'      => $outsider->id,
    ])->assertStatus(403);
});

test('a forged selection token is rejected', function () {
    makeCompany('Alpha');

    $this->postJson('/api/auth/select-company', [
        'selection_token' => 'not-a-real-token',
        'company_id'      => 1,
    ])->assertStatus(401);
});

test('a selection token expires', function () {
    $identity = makePerson('multi@example.test', [makeCompany('Alpha'), makeCompany('Beta')]);
    $token = CompanySelectionToken::issue($identity);

    $this->travel(CompanySelectionToken::ttlSeconds() + 5)->seconds();

    expect(CompanySelectionToken::resolve($token))->toBeNull();
});

test('a session only ever sees the company it was issued for', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    $identity = makePerson('multi@example.test', [$a, $b]);

    $accountA = $identity->accountForCompany($a->id);
    $accountB = $identity->accountForCompany($b->id);

    $leadA = Lead::create(['company_id' => $a->id, 'first_name' => 'Ana',  'last_name' => 'Alpha', 'source_platform' => 'other', 'source_type' => 'manual']);
    $leadB = Lead::create(['company_id' => $b->id, 'first_name' => 'Bruno', 'last_name' => 'Beta', 'source_platform' => 'other', 'source_type' => 'manual']);

    // Acting as the Alpha account, Beta's lead must be refused even though the
    // same person owns an account there. 403 is what assertLeadAccess returns.
    $this->actingAs($accountA)->getJson("/api/leads/{$leadB->id}")->assertStatus(403);
    $this->actingAs($accountA)->getJson("/api/leads/{$leadA->id}")->assertOk();

    $this->actingAs($accountB)->getJson("/api/leads/{$leadA->id}")->assertStatus(403);
    $this->actingAs($accountB)->getJson("/api/leads/{$leadB->id}")->assertOk();
});

test('switching company moves the session and revokes the old token', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    makePerson('multi@example.test', [$a, $b]);

    $login = $this->postJson('/api/auth/login', ['email' => 'multi@example.test', 'password' => 'secret123']);
    $session = $this->postJson('/api/auth/select-company', [
        'selection_token' => $login->json('selection_token'),
        'company_id'      => $a->id,
    ]);
    $oldToken = $session->json('access_token');

    $switched = asToken($this, $oldToken)
        ->postJson('/api/auth/switch-company', ['company_id' => $b->id]);

    $switched->assertOk();
    expect($switched->json('user.company_id'))->toBe($b->id);

    // The token that belonged to Alpha must be dead, or the person would hold
    // two live sessions in two companies at once.
    asToken($this, $oldToken)->getJson('/api/auth/me')->assertStatus(401);

    // ...while the token just issued works, and is bound to Beta.
    $me = asToken($this, $switched->json('access_token'))->getJson('/api/auth/me');
    $me->assertOk();
    expect($me->json('company_id'))->toBe($b->id);
});

test('switching to a company the person does not belong to is refused', function () {
    $a = makeCompany('Alpha');
    $outsider = makeCompany('Gamma');
    $identity = makePerson('solo@example.test', [$a]);

    $this->actingAs($identity->accountForCompany($a->id))
        ->postJson('/api/auth/switch-company', ['company_id' => $outsider->id])
        ->assertStatus(403);
});

test('the companies endpoint lists only this person accounts', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    $other = makeCompany('Gamma');
    makePerson('other@example.test', [$other]);
    $identity = makePerson('multi@example.test', [$a, $b]);

    $res = $this->actingAs($identity->accountForCompany($a->id))->getJson('/api/auth/companies');

    $res->assertOk();
    expect(collect($res->json('data'))->pluck('company_id')->sort()->values()->all())->toBe([$a->id, $b->id])
        ->and($res->json('active_company_id'))->toBe($a->id);
});

test('an inactive account is not offered and cannot be selected', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    $identity = makePerson('multi@example.test', [$a, $b]);

    User::where('identity_id', $identity->id)->where('company_id', $b->id)->update(['is_active' => false]);

    // Down to one selectable account, so login goes straight through to Alpha.
    $login = $this->postJson('/api/auth/login', ['email' => 'multi@example.test', 'password' => 'secret123']);
    $login->assertOk();
    expect($login->json('user.company_id'))->toBe($a->id);

    $this->actingAs($identity->accountForCompany($a->id))
        ->postJson('/api/auth/switch-company', ['company_id' => $b->id])
        ->assertStatus(403);
});

test('an account at an inactive company is not offered', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    $identity = makePerson('multi@example.test', [$a, $b]);

    $b->update(['is_active' => false]);

    expect($identity->selectableAccounts()->count())->toBe(1)
        ->and($identity->accountForCompany($b->id))->toBeNull();
});

test('a person with no active account anywhere cannot sign in', function () {
    $a = makeCompany('Alpha');
    $identity = makePerson('gone@example.test', [$a]);
    User::where('identity_id', $identity->id)->update(['is_active' => false]);

    $this->postJson('/api/auth/login', ['email' => 'gone@example.test', 'password' => 'secret123'])
        ->assertStatus(403);
});

test('wrong credentials are rejected the same way as an unknown address', function () {
    makePerson('known@example.test', [makeCompany('Alpha')]);

    $wrongPassword = $this->postJson('/api/auth/login', ['email' => 'known@example.test', 'password' => 'nope']);
    $unknownEmail  = $this->postJson('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'nope']);

    $wrongPassword->assertStatus(422);
    $unknownEmail->assertStatus(422);
    expect($wrongPassword->json('errors.email'))->toBe($unknownEmail->json('errors.email'));
});

test('changing the password updates the credential login checks', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    $identity = makePerson('multi@example.test', [$a, $b]);

    $this->actingAs($identity->accountForCompany($a->id))
        ->postJson('/api/auth/reset/password', [
            'old_password'              => 'secret123',
            'new_password'              => 'brand-new-pw',
            'new_password_confirmation' => 'brand-new-pw',
        ])->assertOk();

    // One credential covers every company.
    $this->postJson('/api/auth/login', ['email' => 'multi@example.test', 'password' => 'brand-new-pw'])
        ->assertOk();
    $this->postJson('/api/auth/login', ['email' => 'multi@example.test', 'password' => 'secret123'])
        ->assertStatus(422);

    // The mirrored copy on each account is kept current for rollback safety.
    foreach (User::where('identity_id', $identity->id)->get() as $account) {
        expect(Hash::check('brand-new-pw', $account->password))->toBeTrue();
    }
});

test('deleting an account revokes its sessions but leaves the other company alone', function () {
    $a = makeCompany('Alpha');
    $b = makeCompany('Beta');
    $identity = makePerson('multi@example.test', [$a, $b]);

    $accountA = $identity->accountForCompany($a->id);
    $accountB = $identity->accountForCompany($b->id);

    $tokenA = $accountA->createToken('auth_token')->plainTextToken;
    $tokenB = $accountB->createToken('auth_token')->plainTextToken;

    $admin = User::factory()->create(['company_id' => $a->id, 'role' => 'admin', 'email' => 'admin@example.test']);
    $this->actingAs($admin)->deleteJson("/api/users/{$accountA->id}")->assertOk();

    asToken($this, $tokenA)->getJson('/api/auth/me')->assertStatus(401);
    asToken($this, $tokenB)->getJson('/api/auth/me')->assertOk();
});

test('disabling an account ends its session immediately', function () {
    $a = makeCompany('Alpha');
    $identity = makePerson('worker@example.test', [$a], role: 'agent');
    $account = $identity->accountForCompany($a->id);
    $token = $account->createToken('auth_token')->plainTextToken;

    $admin = User::factory()->create(['company_id' => $a->id, 'role' => 'admin', 'email' => 'admin@example.test']);
    $this->actingAs($admin)->patchJson("/api/users/{$account->id}/toggle-status")->assertOk();

    asToken($this, $token)->getJson('/api/auth/me')->assertStatus(401);
});
