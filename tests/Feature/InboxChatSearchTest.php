<?php

use App\Models\Company;
use App\Models\Funnel;
use App\Models\InboxChat;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeChatSearchFixtures(): array
{
    $company = Company::create([
        'name' => 'Acme Inc',
        'email' => 'acme-chat-search-test@example.com',
    ]);

    $user = User::factory()->create(['company_id' => $company->id]);

    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name' => 'Sales Funnel',
        'is_active' => true,
        'created_by' => $user->id,
        'type' => 'sale',
    ]);

    $stage = Stage::create([
        'funnel_id' => $funnel->id,
        'name' => 'Entry',
        'order' => 1,
        'type' => 'entry',
    ]);

    $integration = \App\Models\WhatsAppEvoIntegration::create([
        'name' => 'Main',
        'company_id' => $company->id,
        'base_url' => 'https://evolution.example.com',
        'api_key' => 'fake-key',
    ]);

    $instance = WhatsAppInstance::create([
        'company_id' => $company->id,
        'whats_app_evo_integration_id' => $integration->id,
        'instance_name' => 'INSTANCE_1',
        'funnel_id' => $funnel->id,
        'status' => 'connected',
    ]);

    $leadAnna = Lead::create([
        'company_id' => $company->id,
        'funnel_id' => $funnel->id,
        'stage_id' => $stage->id,
        'first_name' => 'Anna',
        'last_name' => 'Monteiro',
        'phone' => '556291110001',
    ]);

    $leadBruno = Lead::create([
        'company_id' => $company->id,
        'funnel_id' => $funnel->id,
        'stage_id' => $stage->id,
        'first_name' => 'Bruno',
        'last_name' => 'Silva',
        'phone' => '556291110002',
    ]);

    $chatAnna = InboxChat::create([
        'company_id' => $company->id,
        'channel' => InboxChat::CHANNEL_WHATSAPP,
        'channelable_type' => WhatsAppInstance::class,
        'channelable_id' => $instance->id,
        'lead_id' => $leadAnna->id,
        'external_chat_id' => $leadAnna->phone . '@s.whatsapp.net',
        'external_contact_id' => $leadAnna->phone,
        'last_message_at' => now(),
    ]);

    $chatBruno = InboxChat::create([
        'company_id' => $company->id,
        'channel' => InboxChat::CHANNEL_WHATSAPP,
        'channelable_type' => WhatsAppInstance::class,
        'channelable_id' => $instance->id,
        'lead_id' => $leadBruno->id,
        'external_chat_id' => $leadBruno->phone . '@s.whatsapp.net',
        'external_contact_id' => $leadBruno->phone,
        'last_message_at' => now(),
    ]);

    // Leadless chat (e.g. outbound-from-phone before a lead matched) -
    // search should still be able to match it via its own contact_name.
    $chatLeadless = InboxChat::create([
        'company_id' => $company->id,
        'channel' => InboxChat::CHANNEL_WHATSAPP,
        'channelable_type' => WhatsAppInstance::class,
        'channelable_id' => $instance->id,
        'lead_id' => null,
        'contact_name' => 'Carla Souza',
        'external_chat_id' => '556291110003@s.whatsapp.net',
        'external_contact_id' => '556291110003',
        'last_message_at' => now(),
    ]);

    return compact('user', 'chatAnna', 'chatBruno', 'chatLeadless');
}

test('searches chats by lead first name', function () {
    $fixtures = makeChatSearchFixtures();

    $response = $this->actingAs($fixtures['user'])
        ->getJson('/api/inbox/chats?search=Anna');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($fixtures['chatAnna']->id);
    expect($ids)->not->toContain($fixtures['chatBruno']->id);
    expect($ids)->not->toContain($fixtures['chatLeadless']->id);
});

test('searches chats by lead last name', function () {
    $fixtures = makeChatSearchFixtures();

    $response = $this->actingAs($fixtures['user'])
        ->getJson('/api/inbox/chats?search=Silva');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($fixtures['chatBruno']->id);
    expect($ids)->not->toContain($fixtures['chatAnna']->id);
});

test('searches chats by full name across first and last name', function () {
    $fixtures = makeChatSearchFixtures();

    $response = $this->actingAs($fixtures['user'])
        ->getJson('/api/inbox/chats?search=' . urlencode('Anna Monteiro'));

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($fixtures['chatAnna']->id);
    expect($ids)->not->toContain($fixtures['chatBruno']->id);
});

test('searches leadless chats by their own contact name', function () {
    $fixtures = makeChatSearchFixtures();

    $response = $this->actingAs($fixtures['user'])
        ->getJson('/api/inbox/chats?search=Carla');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($fixtures['chatLeadless']->id);
    expect($ids)->not->toContain($fixtures['chatAnna']->id);
});

test('returns no chats when search matches nothing', function () {
    $fixtures = makeChatSearchFixtures();

    $response = $this->actingAs($fixtures['user'])
        ->getJson('/api/inbox/chats?search=Nonexistent');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

test('omitting search returns all company chats', function () {
    $fixtures = makeChatSearchFixtures();

    $response = $this->actingAs($fixtures['user'])
        ->getJson('/api/inbox/chats');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($fixtures['chatAnna']->id);
    expect($ids)->toContain($fixtures['chatBruno']->id);
    expect($ids)->toContain($fixtures['chatLeadless']->id);
});

test('meta.total counts every matching chat, not just the returned page', function () {
    $fixtures = makeChatSearchFixtures();
    $chat = $fixtures['chatAnna'];

    // Page size is 20, so 25 chats forces a second page.
    foreach (range(1, 22) as $i) {
        InboxChat::create([
            'company_id' => $chat->company_id,
            'channel' => InboxChat::CHANNEL_WHATSAPP,
            'channelable_type' => $chat->channelable_type,
            'channelable_id' => $chat->channelable_id,
            'lead_id' => null,
            'contact_name' => "Filler {$i}",
            'external_chat_id' => "5562999{$i}@s.whatsapp.net",
            'external_contact_id' => "5562999{$i}",
            'last_message_at' => now()->subMinutes($i),
        ]);
    }

    $response = $this->actingAs($fixtures['user'])->getJson('/api/inbox/chats');

    $response->assertOk();
    // 3 fixture chats + 22 filler = 25 total, capped at 20 per page.
    expect($response->json('data'))->toHaveCount(20);
    expect($response->json('meta.total'))->toBe(25);
    expect($response->json('meta.last_page'))->toBe(2);
});

test('meta.total reflects the active search filter', function () {
    $fixtures = makeChatSearchFixtures();

    $response = $this->actingAs($fixtures['user'])
        ->getJson('/api/inbox/chats?search=Anna');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});
