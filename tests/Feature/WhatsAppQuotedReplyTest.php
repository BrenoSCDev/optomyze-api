<?php

/**
 * Evolution answers 200 in every quoting failure mode we have hit — a dropped
 * `quoted`, a rewritten mimetype, a response with no `key.id`. None of them
 * surface as an error, so the request shape itself is what gets asserted here.
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeQuotedReplyFixtures(array $quotedAttributes = []): array
{
    $company = Company::create([
        'name'  => 'Acme Inc',
        'email' => 'acme-wa-quote-' . uniqid() . '@example.com',
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
        'instance_name'                => 'wa-quote-test-' . uniqid(),
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

    $quoted = InboxMessage::create(array_merge([
        'inbox_chat_id'       => $chat->id,
        'external_message_id' => '3A729A46D930B59873CF',
        'direction'           => 'incoming',
        'message_mode'        => 'external',
        'type'                => 'text',
        'text'                => 'Good morning, how much is the instalment?',
        'status'              => 'delivered',
        'sent_at'             => now(),
    ], $quotedAttributes));

    return compact('user', 'instance', 'lead', 'chat', 'quoted');
}

test('text reply sends quoted as a flat field carrying both key and text fallback', function () {
    Http::fake(['*evolution.example.com*' => Http::response(['key' => ['id' => 'BAE5F0000000AAAA']], 200)]);

    $f = makeQuotedReplyFixtures();

    $this->actingAs($f['user'])->postJson(
        "/api/inbox/instances/{$f['instance']->id}/messages",
        [
            'lead_id'             => $f['lead']->id,
            'text'                => 'The instalment is R$ 1,200.00.',
            'reply_to_message_id' => $f['quoted']->id,
        ]
    )->assertOk();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/message/sendText/')
            && $body['quoted']['key']['id'] === '3A729A46D930B59873CF'
            // The text fallback Evolution uses when its own lookup misses.
            && $body['quoted']['message']['conversation'] === 'Good morning, how much is the instalment?'
            && !isset($body['options']);
    });

    expect(InboxMessage::where('direction', 'outgoing')->first())
        ->reply_to_message_id->toBe($f['quoted']->id)
        ->external_message_id->toBe('BAE5F0000000AAAA');
});

test('quoting a message with no text omits the message fallback rather than sending it empty', function () {
    Http::fake(['*evolution.example.com*' => Http::response(['key' => ['id' => 'BAE5F0000000BBBB']], 200)]);

    $f = makeQuotedReplyFixtures(['type' => 'audio', 'text' => null]);

    $this->actingAs($f['user'])->postJson(
        "/api/inbox/instances/{$f['instance']->id}/messages",
        [
            'lead_id'             => $f['lead']->id,
            'text'                => 'Got your voice note.',
            'reply_to_message_id' => $f['quoted']->id,
        ]
    )->assertOk();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return isset($body['quoted']['key']['id']) && !isset($body['quoted']['message']);
    });
});

test('image reply goes through sendMedia with the quote attached', function () {
    Storage::fake('local');
    Http::fake(['*evolution.example.com*' => Http::response(['key' => ['id' => 'BAE5F0000000CCCC']], 200)]);

    $f = makeQuotedReplyFixtures();

    $this->actingAs($f['user'])->post(
        "/api/inbox/instances/{$f['instance']->id}/media",
        [
            'lead_id'             => $f['lead']->id,
            'type'                => 'image',
            'file'                => UploadedFile::fake()->create('photo.jpg', 50, 'image/jpeg'),
            'reply_to_message_id' => $f['quoted']->id,
        ]
    )->assertOk();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/message/sendMedia/')
            && $body['quoted']['key']['id'] === '3A729A46D930B59873CF';
    });
});

test('audio reply keeps sendWhatsAppAudio and drops the quote from the request', function () {
    Storage::fake('local');
    Http::fake(['*evolution.example.com*' => Http::response(['key' => ['id' => 'BAE5F0000000DDDD']], 200)]);

    $f = makeQuotedReplyFixtures();

    $this->actingAs($f['user'])->post(
        "/api/inbox/instances/{$f['instance']->id}/media",
        [
            'lead_id'             => $f['lead']->id,
            'type'                => 'audio',
            'file'                => UploadedFile::fake()->create('note.ogg', 20, 'audio/ogg'),
            'reply_to_message_id' => $f['quoted']->id,
        ]
    )->assertOk();

    // sendMedia with mediatype "audio" would carry the quote but strips the
    // opus codec parameter, producing a voice note that never renders on the
    // recipient's phone. Delivery wins; the link stays CRM-side only.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/message/sendWhatsAppAudio/')
            && !isset($request->data()['quoted']);
    });

    expect(InboxMessage::where('type', 'audio')->where('direction', 'outgoing')->first())
        ->reply_to_message_id->toBe($f['quoted']->id);
});

test('a message from another conversation cannot be quoted', function () {
    Http::fake(['*evolution.example.com*' => Http::response(['key' => ['id' => 'BAE5F0000000EEEE']], 200)]);

    $f     = makeQuotedReplyFixtures();
    $other = makeQuotedReplyFixtures();

    $this->actingAs($f['user'])->postJson(
        "/api/inbox/instances/{$f['instance']->id}/messages",
        [
            'lead_id'             => $f['lead']->id,
            'text'                => 'Leaking someone else’s message?',
            'reply_to_message_id' => $other['quoted']->id,
        ]
    )->assertStatus(422);

    Http::assertNothingSent();
});

test('a message with only a locally-minted id cannot be quoted', function () {
    Http::fake(['*evolution.example.com*' => Http::response(['key' => ['id' => 'BAE5F0000000FFFF']], 200)]);

    $f = makeQuotedReplyFixtures(['external_message_id' => 'local-' . \Illuminate\Support\Str::uuid()]);

    $this->actingAs($f['user'])->postJson(
        "/api/inbox/instances/{$f['instance']->id}/messages",
        [
            'lead_id'             => $f['lead']->id,
            'text'                => 'Replying to an internal note',
            'reply_to_message_id' => $f['quoted']->id,
        ]
    )->assertStatus(422);

    Http::assertNothingSent();
});

test('a send response without key.id stores a local fallback id', function () {
    Http::fake(['*evolution.example.com*' => Http::response(['status' => 'PENDING'], 200)]);

    $f = makeQuotedReplyFixtures();

    $this->actingAs($f['user'])->postJson(
        "/api/inbox/instances/{$f['instance']->id}/messages",
        ['lead_id' => $f['lead']->id, 'text' => 'No id came back']
    )->assertOk();

    expect(InboxMessage::where('direction', 'outgoing')->first()->external_message_id)
        ->toStartWith('local-');
});

test('inbound media reply reads contextInfo off the typed message body', function () {
    $f = makeQuotedReplyFixtures();

    $this->postJson('/api/whatsapp-evo-chat', [
        'event'    => 'messages.upsert',
        'instance' => $f['instance']->instance_name,
        'data'     => [
            'key' => [
                'id'        => '3A000000000000000001',
                'remoteJid' => $f['chat']->external_chat_id,
                'fromMe'    => false,
            ],
            'messageType' => 'imageMessage',
            'message'     => [
                'imageMessage' => [
                    'mimetype'    => 'image/jpeg',
                    'caption'     => 'Like this one?',
                    // Only place a media reply carries the reference — reading
                    // just data.contextInfo loses the link entirely.
                    'contextInfo' => [
                        'stanzaId'      => '3A729A46D930B59873CF',
                        'quotedMessage' => ['conversation' => 'Good morning, how much is the instalment?'],
                    ],
                ],
            ],
        ],
    ])->assertOk();

    expect(InboxMessage::where('external_message_id', '3A000000000000000001')->first())
        ->reply_to_message_id->toBe($f['quoted']->id);
});
