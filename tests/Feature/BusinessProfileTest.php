<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeBusinessProfileFixtures(string $role = 'admin'): array
{
    $company = Company::create([
        'name' => 'Acme Inc',
        'email' => 'acme-business-profile@example.com',
        'phone' => '556291110000',
        'website' => 'https://acme.example',
        'subscription_plan' => 'premium',
        'product_module' => 'ERP',
        'settings' => ['secret_flag' => true],
    ]);

    $user = User::factory()->create(['company_id' => $company->id, 'role' => $role]);

    return compact('company', 'user');
}

test('returns the editable business profile fields', function () {
    $f = makeBusinessProfileFixtures();

    $response = $this->actingAs($f['user'])->getJson('/api/business-profile');

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Acme Inc');
    expect($response->json('data.website'))->toBe('https://acme.example');
});

test('never exposes plan, product module or the settings blob', function () {
    $f = makeBusinessProfileFixtures();

    $data = $this->actingAs($f['user'])->getJson('/api/business-profile')->json('data');

    foreach (['subscription_plan', 'product_module', 'settings', 'leads_limit', 'users_limit', 'storage_use', 'is_active'] as $forbidden) {
        expect($data)->not->toHaveKey($forbidden);
    }
});

test('updates the allowed fields', function () {
    $f = makeBusinessProfileFixtures();

    $response = $this->actingAs($f['user'])->putJson('/api/business-profile', [
        'name' => 'Acme Global',
        'phone' => '556299998888',
        'website' => 'https://acme.global',
    ]);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Acme Global');
    expect($f['company']->fresh()->name)->toBe('Acme Global');
});

test('ignores attempts to change plan, product module or settings', function () {
    $f = makeBusinessProfileFixtures();

    $this->actingAs($f['user'])->putJson('/api/business-profile', [
        'name' => 'Acme Global',
        'subscription_plan' => 'enterprise',
        'product_module' => 'product',
        'settings' => ['secret_flag' => false],
        'leads_limit' => 999999,
        'is_active' => false,
    ])->assertOk();

    $company = $f['company']->fresh();
    expect($company->name)->toBe('Acme Global');            // allowed change applied
    expect($company->subscription_plan)->toBe('premium');   // untouched
    expect($company->product_module)->toBe('ERP');          // untouched
    expect($company->settings)->toBe(['secret_flag' => true]);
    expect($company->is_active)->toBeTrue();
});

test('updates the company timezone', function () {
    $f = makeBusinessProfileFixtures();

    $response = $this->actingAs($f['user'])->putJson('/api/business-profile', [
        'timezone' => 'America/Sao_Paulo',
    ]);

    $response->assertOk();
    expect($response->json('data.timezone'))->toBe('America/Sao_Paulo');
    expect($f['company']->fresh()->timezone)->toBe('America/Sao_Paulo');
});

test('agents can read but not edit the business profile', function () {
    $f = makeBusinessProfileFixtures('agent');

    $this->actingAs($f['user'])->getJson('/api/business-profile')->assertOk();

    $this->actingAs($f['user'])
        ->putJson('/api/business-profile', ['name' => 'Hacked Inc'])
        ->assertForbidden();

    expect($f['company']->fresh()->name)->toBe('Acme Inc');
});

test('uploads and removes a company logo', function () {
    Storage::fake('local');
    $f = makeBusinessProfileFixtures();

    $upload = $this->actingAs($f['user'])->postJson('/api/business-profile/logo', [
        // create() rather than image(): the latter needs the GD extension,
        // which isn't guaranteed in CI. The `image` rule matches on mime type.
        'logo' => UploadedFile::fake()->create('logo.png', 40, 'image/png'),
    ]);

    $upload->assertOk();
    $path = $upload->json('data.logo_path');
    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);

    $this->actingAs($f['user'])->deleteJson('/api/business-profile/logo')->assertOk();
    expect($f['company']->fresh()->logo_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

test('rejects a non-image logo', function () {
    Storage::fake('local');
    $f = makeBusinessProfileFixtures();

    $this->actingAs($f['user'])
        ->postJson('/api/business-profile/logo', [
            'logo' => UploadedFile::fake()->create('payload.pdf', 100, 'application/pdf'),
        ])
        ->assertStatus(422);
});
