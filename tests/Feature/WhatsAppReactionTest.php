<?php

/**
 * Evolution answers 200 even when a reaction targets nothing, so the full
 * `key` it is addressed with — remoteJid, fromMe, id — is what gets asserted.
 * Those three account for nearly every silent failure.
 */

use App\Models\Company;
use App\Models\Funnel;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\User;
use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeReactionFixtures(): array
{
    $company = Company::create([
        'name'  => 'Acme Inc',
        'email' => 'acme-wa-react-' . uniqid() . '@example.com',
    ]);

    $user = User::factory()->create(['company_id' => $company->id]);

    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name'       => 'Sales',
        'is_active'  => true,
        'created_by' => $user->id,
        'type'       => 'sale',
    ]);

    Stage::create(['funnel_id' => $funnel->id, 'name' => 'Entry', 'order' => 1, 'type' => 'entry']);

    $integration = WhatsAppEvoIntegration::create([
        'name'       => 'Main',
        'company_id' => $company->id,
        'base_url'   => 'https://evolution.example.com',
        'api_key'    => 'fake-key',
    ]);

    $instance = WhatsAppInstance::create([
        'company_id'                   => $company->id,
        'whats_app_evo_integration_id' => $integration->id,
        'instance_name'                => 'wa-react-test-' . uniqid(),
        'funnel_id'                    => $funnel->id,
        'status'                       => 'connected',
    ]);

    $lead = Lead::factory()->create([
        'company_id' => $company->id,
        'funnel_id'  => $funnel->id,
        'phone'      => '5562991143213',
    ]);

    $chat = InboxChat::firstOrCreateWhatsApp($company->id, $instance, $lead->phone, [
        'lead_id' => $lead->id,
    ]);

    $inbound = InboxMessage::create([
        'inbox_chat_id'       => $chat->id,
        'external_message_id' => '3A729A46D930B59873CF',
        'direction'           => 'incoming',
        'message_mode'        => 'external',
        'type'                => 'text',
        'text'                => 'Good morning, how much is the instalment?',
        'status'              => 'delivered',
        'sent_at'             => now(),
    ]);

    return compact('user', 'instance', 'lead', 'chat', 'inbound');
}

test('reacting to a customer message sends the full key with fromMe false', function () {
    Http::fake(['*evolution.example.com*' => Http::response(['status' => 'success'], 200)]);

    $f = makeReactionFixtures();
    $f['chat']->update(['provider_jid' => '556291143213@s.whatsapp.net']);

    $this->actingAs($f['user'])
        ->postJson("/api/inbox/messages/{$f['inbound']->id}/reaction", ['emoji' => '👍'])
        ->assertOk();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/message/sendReaction/')
            // The JID WhatsApp reported, not the one synthesised from the
            // lead's phone (note the differing Brazilian digit format).
            && $body['key']['remoteJid'] === '556291143213@s.whatsapp.net'
            && $body['key']['fromMe'] === false
            && $body['key']['id'] === '3A729A46D930B59873CF'
            && $body['reaction'] === '👍';
    });

    expect($f['inbound']->fresh()->reactions['agent']['emoji'])->toBe('👍');
});

test('reacting to our own message flips fromMe to true', function () {
    Http::fake(['*evolution.example.com*' => Http::response(['status' => 'success'], 200)]);

    $f = makeReactionFixtures();

    $outgoing = InboxMessage::create([
        'inbox_chat_id'       => $f['chat']->id,
        'external_message_id' => 'BAE5F0000000AAAA',
        'direction'           => 'outgoing',
        'message_mode'        => 'external',
        'type'                => 'text',
        'text'                => 'The instalment is R$ 1,200.00.',
        'status'              => 'sent',
        'sent_at'             => now(),
    ]);

    $this->actingAs($f['user'])
        ->postJson("/api/inbox/messages/{$outgoing->id}/reaction", ['emoji' => '🙏'])
        ->assertOk();

    Http::assertSent(fn ($request) => $request->data()['key']['fromMe'] === true);
});

test('an empty emoji removes the reaction through the same endpoint', function () {
    Http::fake(['*evolution.example.com*' => Http::response(['status' => 'success'], 200)]);

    $f = makeReactionFixtures();
    $f['inbound']->update(['reactions' => ['agent' => ['emoji' => '👍', 'timestamp' => null]]]);

    $this->actingAs($f['user'])
        ->postJson("/api/inbox/messages/{$f['inbound']->id}/reaction", ['emoji' => ''])
        ->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/message/sendReaction/')
            && $request->data()['reaction'] === '';
    });

    expect($f['inbound']->fresh()->reactions)->not->toHaveKey('agent');
});

test('a message with only a locally-minted id cannot be reacted to', function () {
    Http::fake(['*evolution.example.com*' => Http::response(['status' => 'success'], 200)]);

    $f = makeReactionFixtures();
    $f['inbound']->update(['external_message_id' => 'local-' . \Illuminate\Support\Str::uuid()]);

    $this->actingAs($f['user'])
        ->postJson("/api/inbox/messages/{$f['inbound']->id}/reaction", ['emoji' => '👍'])
        ->assertStatus(422);

    Http::assertNothingSent();
});

test('the webhook records the JID WhatsApp reports, resolving the @lid form', function () {
    $f = makeReactionFixtures();

    $this->postJson('/api/whatsapp-evo-chat', [
        'event'    => 'messages.upsert',
        'instance' => $f['instance']->instance_name,
        'data'     => [
            'key' => [
                'id'           => '3A000000000000000009',
                'remoteJid'    => '15612340338830@lid',
                'remoteJidAlt' => '5562991143213@s.whatsapp.net',
                'fromMe'       => false,
            ],
            'messageType' => 'conversation',
            'message'     => ['conversation' => 'Hello'],
        ],
    ])->assertOk();

    expect($f['chat']->fresh()->provider_jid)->toBe('5562991143213@s.whatsapp.net');
});

test('an inbound reaction updates the target message instead of adding a bubble', function () {
    $f = makeReactionFixtures();
    $before = InboxMessage::where('inbox_chat_id', $f['chat']->id)->count();

    $this->postJson('/api/whatsapp-evo-chat', [
        'event'    => 'messages.upsert',
        'instance' => $f['instance']->instance_name,
        'data'     => [
            'key'         => ['id' => '3A00000000000000000A', 'remoteJid' => '5562991143213@s.whatsapp.net', 'fromMe' => false],
            'messageType' => 'reactionMessage',
            'message'     => [
                'reactionMessage' => [
                    'text' => '👍',
                    'key'  => ['id' => '3A729A46D930B59873CF', 'fromMe' => true],
                ],
            ],
        ],
    ])->assertOk();

    expect(InboxMessage::where('inbox_chat_id', $f['chat']->id)->count())->toBe($before)
        ->and($f['inbound']->fresh()->reactions['contact']['emoji'])->toBe('👍');
});

test('an inbound empty reaction clears it rather than storing an empty string', function () {
    $f = makeReactionFixtures();
    $f['inbound']->update(['reactions' => ['contact' => ['emoji' => '👍', 'timestamp' => null]]]);

    $this->postJson('/api/whatsapp-evo-chat', [
        'event'    => 'messages.upsert',
        'instance' => $f['instance']->instance_name,
        'data'     => [
            'key'         => ['id' => '3A00000000000000000B', 'remoteJid' => '5562991143213@s.whatsapp.net', 'fromMe' => false],
            'messageType' => 'reactionMessage',
            'message'     => [
                'reactionMessage' => [
                    'text' => '',
                    'key'  => ['id' => '3A729A46D930B59873CF', 'fromMe' => true],
                ],
            ],
        ],
    ])->assertOk();

    expect($f['inbound']->fresh()->reactions)->not->toHaveKey('contact');
});
