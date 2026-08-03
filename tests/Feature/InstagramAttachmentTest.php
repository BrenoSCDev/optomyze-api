<?php

use App\Models\Company;
use App\Models\Funnel;
use App\Models\InboxMessage;
use App\Models\InstagramIntegration;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * Inbound Instagram attachment handling.
 *
 * The failure this guards against is silence: an attachment shape the switch
 * did not recognise used to `return` before creating anything, so the message
 * never reached the inbox and an agent had no idea the customer had sent
 * something.
 */
uses(RefreshDatabase::class);

function igIntegration(): InstagramIntegration
{
    $company = Company::create([
        'name'      => 'IG Co',
        'email'     => 'ig-' . uniqid() . '@example.test',
        'is_active' => true,
    ]);

    $owner = User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'ig-owner-' . uniqid() . '@example.test',
        'role'       => 'admin',
    ]);

    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name'       => 'Inbound',
        'type'       => 'contact',
        'is_active'  => true,
        'created_by' => $owner->id,
    ]);

    Stage::create([
        'funnel_id' => $funnel->id,
        'name'      => 'New',
        'type'      => 'entry',
        'order'     => 1,
        'is_active' => true,
    ]);

    return InstagramIntegration::create([
        'name'              => 'Main',
        'company_id'        => $company->id,
        'access_token'      => 'token',
        'instagram_user_id' => '17841477325686236',
        'username'          => 'optomyze',
        'connection_status' => 'connected',
        'is_active'         => true,
        'funnel_id'         => $funnel->id,
    ]);
}

/** A webhook body in Meta's shape, carrying one attachment. */
function igAttachmentPayload(array $attachment, string $mid = 'mid-1'): array
{
    return [
        'object' => 'instagram',
        'entry'  => [[
            'time'      => 1785734977973,
            'id'        => '17841477325686236',
            'messaging' => [[
                'sender'    => ['id' => '1801346397940801'],
                'recipient' => ['id' => '17841477325686236'],
                'timestamp' => 1785734976417,
                'message'   => [
                    'mid'         => $mid,
                    'attachments' => [$attachment],
                ],
            ]],
        ]],
    ];
}

function postIgWebhook(InstagramIntegration $integration, array $payload)
{
    return test()->postJson(
        "/api/integrations/instagram/webhook/{$integration->webhook_callback_id}",
        $payload
    );
}

beforeEach(function () {
    // The controller downloads media for downloadable types; nothing should
    // reach the network in a test.
    Http::fake(['*' => Http::response('binary-body', 200, ['Content-Type' => 'video/mp4'])]);
});

test('a video attachment is stored as a video message', function () {
    $integration = igIntegration();

    postIgWebhook($integration, igAttachmentPayload([
        'type'    => 'video',
        'payload' => ['url' => 'https://lookaside.fbsbx.com/video.mp4'],
    ]))->assertOk();

    $message = InboxMessage::where('external_message_id', 'mid-1')->first();

    expect($message)->not->toBeNull()
        ->and($message->type)->toBe('video');
});

test('a disappearing (ephemeral) attachment is recorded instead of dropped', function () {
    $integration = igIntegration();

    // Meta's exact shape for view-once media: no payload, no URL, ever.
    postIgWebhook($integration, igAttachmentPayload(['type' => 'ephemeral'], 'mid-ephemeral'))
        ->assertOk();

    $message = InboxMessage::where('external_message_id', 'mid-ephemeral')->first();

    expect($message)->not->toBeNull()
        ->and($message->type)->toBe('ephemeral')
        ->and($message->media_url)->toBeNull()
        ->and($message->metadata['ephemeral'] ?? null)->toBeTrue();
});

test('an image attachment still works', function () {
    $integration = igIntegration();

    postIgWebhook($integration, igAttachmentPayload([
        'type'    => 'image',
        'payload' => ['url' => 'https://lookaside.fbsbx.com/photo.jpg'],
    ]), )->assertOk();

    expect(InboxMessage::where('external_message_id', 'mid-1')->first()?->type)->toBe('image');
});

test('an audio attachment still works', function () {
    $integration = igIntegration();

    postIgWebhook($integration, igAttachmentPayload([
        'type'    => 'audio',
        'payload' => ['url' => 'https://lookaside.fbsbx.com/voice.m4a'],
    ]))->assertOk();

    expect(InboxMessage::where('external_message_id', 'mid-1')->first()?->type)->toBe('audio');
});

test('a shared post keeps its link in metadata and downloads nothing', function () {
    $integration = igIntegration();

    postIgWebhook($integration, igAttachmentPayload([
        'type'    => 'share',
        'payload' => ['url' => 'https://instagram.com/p/abc'],
    ], 'mid-share'))->assertOk();

    $message = InboxMessage::where('external_message_id', 'mid-share')->first();

    expect($message->type)->toBe('shared_post')
        ->and($message->media_url)->toBeNull();
});

test('the webhook still answers 200 for an attachment shape we do not know', function () {
    $integration = igIntegration();

    // Meta retries on non-2xx, so an unknown shape must not look like failure.
    postIgWebhook($integration, igAttachmentPayload(['type' => 'not_a_real_type'], 'mid-unknown'))
        ->assertOk();

    expect(InboxMessage::where('external_message_id', 'mid-unknown')->exists())->toBeFalse();
});
