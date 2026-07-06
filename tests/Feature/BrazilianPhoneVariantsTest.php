<?php

use App\Models\Company;
use App\Models\Funnel;
use App\Models\InboxChat;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\WhatsAppInstance;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── PhoneNormalizer unit tests ──────────────────────────────────────────────

test('normalize strips non-digits', function () {
    expect(PhoneNormalizer::normalize('+55 (11) 9 8765-4321'))->toBe('5511987654321');
    expect(PhoneNormalizer::normalize('  5511987654321  '))->toBe('5511987654321');
    expect(PhoneNormalizer::normalize('5511987654321'))->toBe('5511987654321');
});

test('variants returns both BR formats for 13-digit new number', function () {
    // new (13 digits): 55 + DDD(11) + 9 + 87654321
    $variants = PhoneNormalizer::variants('5511987654321');
    expect($variants)->toContain('5511987654321'); // new
    expect($variants)->toContain('551187654321');  // old (9 stripped)
    expect($variants)->toHaveCount(2);
});

test('variants returns both BR formats for 12-digit old number', function () {
    // old (12 digits): 55 + DDD(11) + 87654321
    $variants = PhoneNormalizer::variants('551187654321');
    expect($variants)->toContain('551187654321');  // old
    expect($variants)->toContain('5511987654321'); // new (9 inserted)
    expect($variants)->toHaveCount(2);
});

test('variants returns only the original for non-BR numbers', function () {
    expect(PhoneNormalizer::variants('14155552671'))->toBe(['14155552671']); // US
    expect(PhoneNormalizer::variants('447911123456'))->toBe(['447911123456']); // UK
});

test('variants returns only the original for 13-digit BR number where 5th digit is not 9', function () {
    // A 13-digit number starting with 55 but without the mobile-9 marker
    // should not produce a spurious variant.
    $variants = PhoneNormalizer::variants('5511187654321');
    expect($variants)->toHaveCount(1);
    expect($variants[0])->toBe('5511187654321');
});

// ─── Lead::findByPhone integration tests ─────────────────────────────────────

function makeCompanyForPhoneTest(): Company
{
    return Company::create([
        'name' => 'Phone Test Co',
        'email' => 'phone-test-' . uniqid() . '@example.com',
    ]);
}

test('findByPhone matches the exact stored phone', function () {
    $company = makeCompanyForPhoneTest();
    $lead = Lead::factory()->create(['company_id' => $company->id, 'phone' => '5511987654321']);

    expect(Lead::findByPhone($company->id, '5511987654321'))->not->toBeNull();
    expect(Lead::findByPhone($company->id, '5511987654321')->id)->toBe($lead->id);
});

test('findByPhone finds new-format lead when searched with old format', function () {
    $company = makeCompanyForPhoneTest();
    // Lead stored with the new (13-digit) format
    $lead = Lead::factory()->create(['company_id' => $company->id, 'phone' => '5511987654321']);

    // WhatsApp delivers the old (12-digit) format
    $found = Lead::findByPhone($company->id, '551187654321');
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($lead->id);
});

test('findByPhone finds old-format lead when searched with new format', function () {
    $company = makeCompanyForPhoneTest();
    // Lead stored with the old (12-digit) format
    $lead = Lead::factory()->create(['company_id' => $company->id, 'phone' => '551187654321']);

    // Web form delivers the new (13-digit) format
    $found = Lead::findByPhone($company->id, '5511987654321');
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($lead->id);
});

test('findByPhone does not cross company boundaries', function () {
    $companyA = makeCompanyForPhoneTest();
    $companyB = makeCompanyForPhoneTest();
    Lead::factory()->create(['company_id' => $companyA->id, 'phone' => '5511987654321']);

    expect(Lead::findByPhone($companyB->id, '5511987654321'))->toBeNull();
    expect(Lead::findByPhone($companyB->id, '551187654321'))->toBeNull();
});

test('findByPhone returns null for blank phone', function () {
    $company = makeCompanyForPhoneTest();
    expect(Lead::findByPhone($company->id, ''))->toBeNull();
    expect(Lead::findByPhone($company->id, '   '))->toBeNull();
});

// ─── WhatsApp webhook integration test ───────────────────────────────────────

function makeInstanceForBrPhoneTest(): WhatsAppInstance
{
    $company = makeCompanyForPhoneTest();

    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name' => 'Sales',
        'is_active' => true,
        'created_by' => \App\Models\User::factory()->create(['company_id' => $company->id])->id,
        'type' => 'sale',
    ]);

    Stage::create(['funnel_id' => $funnel->id, 'name' => 'Entry', 'order' => 1, 'type' => 'entry']);

    $integration = \App\Models\WhatsAppEvoIntegration::create([
        'name' => 'Main',
        'company_id' => $company->id,
        'base_url' => 'https://evolution.example.com',
        'api_key' => 'fake-key',
    ]);

    return WhatsAppInstance::create([
        'company_id' => $company->id,
        'whats_app_evo_integration_id' => $integration->id,
        'instance_name' => 'br-phone-test-' . uniqid(),
        'funnel_id' => $funnel->id,
        'status' => 'connected',
    ]);
}

test('whatsapp webhook matches existing new-format lead when message arrives with old format', function () {
    $instance = makeInstanceForBrPhoneTest();

    // Lead was created from a web form with the new 13-digit number
    $lead = Lead::factory()->create([
        'company_id' => $instance->company_id,
        'phone' => '5511987654321',
    ]);

    $leadCountBefore = Lead::where('company_id', $instance->company_id)->count();

    // WhatsApp delivers the same person's old 12-digit number
    $payload = [
        'event' => 'messages.upsert',
        'instance' => $instance->instance_name,
        'data' => [
            'key' => [
                'remoteJid' => '551187654321@s.whatsapp.net',
                'fromMe' => false,
                'id' => 'MSGID001',
            ],
            'pushName' => 'Test Person',
            'message' => ['conversation' => 'oi'],
            'messageType' => 'conversation',
            'messageTimestamp' => time(),
        ],
    ];

    $response = $this->postJson('/api/whatsapp-evo-chat', $payload);
    $response->assertOk();

    // No new lead should have been created — it matched the existing one
    expect(Lead::where('company_id', $instance->company_id)->count())->toBe($leadCountBefore);

    // The chat should be linked to the existing lead
    $chat = InboxChat::where('company_id', $instance->company_id)->first();
    expect($chat)->not->toBeNull();
    expect($chat->lead_id)->toBe($lead->id);
});

test('whatsapp webhook matches existing old-format lead when message arrives with new format', function () {
    $instance = makeInstanceForBrPhoneTest();

    // Lead was created from an older source with the 12-digit number
    $lead = Lead::factory()->create([
        'company_id' => $instance->company_id,
        'phone' => '551187654321',
    ]);

    $leadCountBefore = Lead::where('company_id', $instance->company_id)->count();

    // WhatsApp delivers the new 13-digit number
    $payload = [
        'event' => 'messages.upsert',
        'instance' => $instance->instance_name,
        'data' => [
            'key' => [
                'remoteJid' => '5511987654321@s.whatsapp.net',
                'fromMe' => false,
                'id' => 'MSGID002',
            ],
            'pushName' => 'Test Person',
            'message' => ['conversation' => 'oi'],
            'messageType' => 'conversation',
            'messageTimestamp' => time(),
        ],
    ];

    $response = $this->postJson('/api/whatsapp-evo-chat', $payload);
    $response->assertOk();

    expect(Lead::where('company_id', $instance->company_id)->count())->toBe($leadCountBefore);

    $chat = InboxChat::where('company_id', $instance->company_id)->first();
    expect($chat->lead_id)->toBe($lead->id);
});
