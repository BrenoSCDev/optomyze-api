<?php

use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const MANAGED_URL = 'https://evolution.optomyze.test';
const MANAGED_KEY = 'platform-evolution-key';

beforeEach(function () {
    config([
        'services.evolution.url' => MANAGED_URL,
        'services.evolution.key' => MANAGED_KEY,
    ]);
});

function waCompany(string $name = 'Managed Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

function waAdmin(Company $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'email'      => "admin-{$company->id}@example.test",
        'role'       => 'admin',
    ]);
}

/** Evolution answering happily to everything the account flow calls. */
function fakeEvolutionOk(): void
{
    Http::fake([
        '*/instance/create'          => Http::response(['instance' => ['instanceName' => 'x']], 201),
        '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'close']], 200),
        '*'                          => Http::response([], 200),
    ]);
}

/* ---------------- credentials come from the platform ---------------- */

test('installing WhatsApp stamps the managed server onto the integration', function () {
    $company = waCompany();

    test()->actingAs(waAdmin($company))
        ->postJson('/api/integrations/whatsapp-evo-integrations')
        ->assertCreated();

    $integration = WhatsAppEvoIntegration::where('company_id', $company->id)->first();

    // Persisted, not just resolved at read time — the column is what a future
    // custom-server override would replace.
    expect($integration->getRawOriginal('base_url'))->toBe(MANAGED_URL)
        ->and($integration->getRawOriginal('api_key'))->toBe(MANAGED_KEY);
});

test('installing twice does not create a second integration', function () {
    $company = waCompany();
    $admin = waAdmin($company);

    test()->actingAs($admin)->postJson('/api/integrations/whatsapp-evo-integrations')->assertCreated();
    test()->actingAs($admin)->postJson('/api/integrations/whatsapp-evo-integrations')->assertCreated();

    expect(WhatsAppEvoIntegration::where('company_id', $company->id)->count())->toBe(1);
});

test('an integration predating the managed server still resolves credentials', function () {
    $company = waCompany();

    // How every row looked before we hosted Evolution: installed, unconfigured.
    $integration = WhatsAppEvoIntegration::create([
        'name'       => 'wpp_evo_legacy',
        'company_id' => $company->id,
        'base_url'   => null,
        'api_key'    => null,
        'is_active'  => true,
    ]);

    expect($integration->fresh()->base_url)->toBe(MANAGED_URL)
        ->and($integration->fresh()->api_key)->toBe(MANAGED_KEY);
});

test('a company running its own Evolution server keeps it', function () {
    $company = waCompany();

    $integration = WhatsAppEvoIntegration::create([
        'name'       => 'wpp_evo_custom',
        'company_id' => $company->id,
        'base_url'   => 'https://their-own-evolution.example.com',
        'api_key'    => 'their-own-key',
        'is_active'  => true,
    ]);

    expect($integration->fresh()->base_url)->toBe('https://their-own-evolution.example.com')
        ->and($integration->fresh()->api_key)->toBe('their-own-key');
});

test('the configure endpoint can still move a company onto its own server', function () {
    $company = waCompany();
    $admin = waAdmin($company);

    test()->actingAs($admin)->postJson('/api/integrations/whatsapp-evo-integrations')->assertCreated();

    test()->actingAs($admin)->putJson('/api/integrations/whatsapp-evo-integrations/configure', [
        'base_url' => 'https://dedicated.example.com',
        'api_key'  => 'dedicated-key',
    ])->assertOk();

    $integration = WhatsAppEvoIntegration::where('company_id', $company->id)->first();

    expect($integration->base_url)->toBe('https://dedicated.example.com')
        ->and($integration->api_key)->toBe('dedicated-key');
});

/* ---------------- creating an account ---------------- */

test('creating an account asks the user for nothing', function () {
    fakeEvolutionOk();

    $company = waCompany();

    $response = test()->actingAs(waAdmin($company))
        ->postJson('/api/integrations/whatsapp-evo-instances')
        ->assertCreated();

    expect($response->json('instance_name'))->toMatch('/^acct_[0-9A-F]{6}$/')
        ->and($response->json('status'))->toBe('created');
});

test('an account can be created before the marketplace install', function () {
    fakeEvolutionOk();

    $company = waCompany();

    // No integration row exists yet: the old flow required install, then a
    // credentials form, then this. There is nothing left to do in between.
    test()->actingAs(waAdmin($company))
        ->postJson('/api/integrations/whatsapp-evo-instances')
        ->assertCreated();

    expect(WhatsAppEvoIntegration::where('company_id', $company->id)->count())->toBe(1);
});

test('the account is provisioned on the managed server under its identifier', function () {
    fakeEvolutionOk();

    $company = waCompany();

    $response = test()->actingAs(waAdmin($company))
        ->postJson('/api/integrations/whatsapp-evo-instances')
        ->assertCreated();

    $identifier = $response->json('instance_name');

    Http::assertSent(fn ($request) =>
        $request->url() === MANAGED_URL . '/instance/create'
        && $request->header('apikey')[0] === MANAGED_KEY
        && $request['instanceName'] === $identifier
    );
});

test('identifiers are unique across companies, not just within one', function () {
    fakeEvolutionOk();

    $names = collect(range(1, 6))->map(function (int $i) {
        $company = waCompany("Company {$i}");

        return test()->actingAs(waAdmin($company))
            ->postJson('/api/integrations/whatsapp-evo-instances')
            ->json('instance_name');
    });

    expect($names->unique())->toHaveCount(6);
});

test('two companies cannot hold the same identifier', function () {
    // Every tenant shares one Evolution deployment, so the name it is filed
    // under has to be unique across the whole platform. The index used to be
    // scoped per company, which let a second tenant claim a name that was
    // already live and only fail once Evolution was asked to create it.
    $makeInstance = function (string $companyName) {
        $company = waCompany($companyName);

        return WhatsAppInstance::create([
            'company_id'                   => $company->id,
            'whats_app_evo_integration_id' => WhatsAppEvoIntegration::forCompany($company->id)->id,
            'instance_name'                => 'acct_ABCDEF',
            'status'                       => 'created',
        ]);
    };

    $makeInstance('First');

    expect(fn () => $makeInstance('Second'))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('a failed provisioning leaves nothing behind locally', function () {
    Http::fake([
        '*/instance/create' => Http::response(['error' => 'name already in use'], 403),
    ]);

    $company = waCompany();

    test()->actingAs(waAdmin($company))
        ->postJson('/api/integrations/whatsapp-evo-instances')
        ->assertStatus(422);

    expect(WhatsAppInstance::where('company_id', $company->id)->exists())->toBeFalse();
});

test('Evolution errors never reach the customer', function () {
    Http::fake([
        '*/instance/create' => Http::response([
            'message' => 'connect ECONNREFUSED https://evolution.optomyze.test — apikey platform-evolution-key',
        ], 500),
    ]);

    $company = waCompany();

    $response = test()->actingAs(waAdmin($company))
        ->postJson('/api/integrations/whatsapp-evo-instances')
        ->assertStatus(422);

    $body = $response->getContent();

    expect($body)->not->toContain(MANAGED_URL)
        ->and($body)->not->toContain(MANAGED_KEY)
        ->and($body)->not->toContain('ECONNREFUSED');
});

test('an unconfigured platform server refuses instead of calling nowhere', function () {
    config(['services.evolution.url' => null, 'services.evolution.key' => null]);

    Http::fake();

    $company = waCompany();

    test()->actingAs(waAdmin($company))
        ->postJson('/api/integrations/whatsapp-evo-instances')
        ->assertStatus(503);

    Http::assertNothingSent();
    expect(WhatsAppInstance::where('company_id', $company->id)->exists())->toBeFalse();
});

/* ---------------- what the panel is told ---------------- */

test('the settings payload no longer exposes the Evolution server URL', function () {
    $company = waCompany();
    $admin = waAdmin($company);

    test()->actingAs($admin)->postJson('/api/integrations/whatsapp-evo-integrations')->assertCreated();

    $response = test()->actingAs($admin)->getJson('/api/integrations/available/settings')->assertOk();

    expect($response->json())->not->toHaveKey('wpp_evo_base_url')
        ->and($response->json('wpp_evo_configured'))->toBeTrue()
        ->and($response->getContent())->not->toContain(MANAGED_KEY);
});
