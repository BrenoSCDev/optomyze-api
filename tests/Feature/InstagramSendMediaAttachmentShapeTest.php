<?php

use App\Models\Company;
use App\Models\InboxChat;
use App\Models\InstagramIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeInstagramFixtures(): array
{
    $company = Company::create([
        'name' => 'Acme Inc',
        'email' => 'acme-ig-media-test@example.com',
    ]);

    $user = User::factory()->create(['company_id' => $company->id]);

    $integration = InstagramIntegration::create([
        'name' => 'Main',
        'company_id' => $company->id,
        'access_token' => 'fake-token',
        'instagram_user_id' => '17841400000000000',
        'connection_status' => InstagramIntegration::CONNECTION_CONNECTED,
    ]);

    $chat = InboxChat::create([
        'company_id' => $company->id,
        'channel' => InboxChat::CHANNEL_INSTAGRAM,
        'channelable_type' => InstagramIntegration::class,
        'channelable_id' => $integration->id,
        'external_chat_id' => 'igsid_123',
        'external_contact_id' => 'igsid_123',
        'last_message_at' => now(),
    ]);

    return compact('user', 'integration', 'chat');
}

test('image media uses the plural attachments key per Instagram docs', function () {
    Storage::fake('local');
    Http::fake([
        '*graph.instagram.com*' => Http::response(['recipient_id' => 'igsid_123', 'message_id' => 'mid.123'], 200),
    ]);

    $fixtures = makeInstagramFixtures();

    $response = $this->actingAs($fixtures['user'])->post(
        "/api/inbox/instagram/{$fixtures['integration']->id}/media",
        [
            'chat_id' => $fixtures['chat']->id,
            'type' => 'image',
            'file' => UploadedFile::fake()->create('photo.jpg', 50, 'image/jpeg'),
        ]
    );

    $response->assertOk();

    Http::assertSent(function ($request) {
        $body = $request->data();
        return isset($body['message']['attachments'][0])
            && !isset($body['message']['attachment'])
            && $body['message']['attachments'][0]['type'] === 'image'
            && isset($body['message']['attachments'][0]['payload']['url']);
    });
});

test('audio media uses the singular attachment key per Instagram docs', function () {
    Storage::fake('local');
    Http::fake([
        '*graph.instagram.com*' => Http::response(['recipient_id' => 'igsid_123', 'message_id' => 'mid.456'], 200),
    ]);

    $fixtures = makeInstagramFixtures();

    $response = $this->actingAs($fixtures['user'])->post(
        "/api/inbox/instagram/{$fixtures['integration']->id}/media",
        [
            'chat_id' => $fixtures['chat']->id,
            'type' => 'audio',
            'file' => UploadedFile::fake()->create('voice.ogg', 10, 'audio/ogg'),
        ]
    );

    $response->assertOk();

    Http::assertSent(function ($request) {
        $body = $request->data();
        return isset($body['message']['attachment'])
            && !isset($body['message']['attachments'])
            && $body['message']['attachment']['type'] === 'audio'
            && isset($body['message']['attachment']['payload']['url']);
    });
});

test('media payload does not include the undocumented is_reusable field', function () {
    Storage::fake('local');
    Http::fake([
        '*graph.instagram.com*' => Http::response(['recipient_id' => 'igsid_123', 'message_id' => 'mid.789'], 200),
    ]);

    $fixtures = makeInstagramFixtures();

    $this->actingAs($fixtures['user'])->post(
        "/api/inbox/instagram/{$fixtures['integration']->id}/media",
        [
            'chat_id' => $fixtures['chat']->id,
            'type' => 'image',
            'file' => UploadedFile::fake()->create('photo.jpg', 50, 'image/jpeg'),
        ]
    );

    Http::assertSent(function ($request) {
        $body = $request->data();
        return !isset($body['message']['attachments'][0]['payload']['is_reusable']);
    });
});

test('failure response includes the exact request payload sent to Instagram for debugging', function () {
    Storage::fake('local');
    Http::fake([
        '*graph.instagram.com*' => Http::response([
            'error' => [
                'message' => 'Upload failed',
                'type' => 'IGApiException',
                'code' => 100,
                'error_subcode' => 2018007,
            ],
        ], 400),
    ]);

    $fixtures = makeInstagramFixtures();

    $response = $this->actingAs($fixtures['user'])->post(
        "/api/inbox/instagram/{$fixtures['integration']->id}/media",
        [
            'chat_id' => $fixtures['chat']->id,
            'type' => 'image',
            'file' => UploadedFile::fake()->create('photo.jpg', 50, 'image/jpeg'),
        ]
    );

    $response->assertStatus(422);
    $response->assertJsonStructure(['message', 'error', 'request_url', 'request_payload']);

    $payload = $response->json('request_payload');
    expect($payload['recipient']['id'])->toBe('igsid_123');
    expect($payload['message']['attachments'][0]['type'])->toBe('image');
    expect($payload['message']['attachments'][0]['payload']['url'])->toBeString();

    // The access token must never leak into the echoed payload - it's sent
    // via the Authorization header, not the request body.
    expect(json_encode($payload))->not->toContain('fake-token');
});

test('the signed media url sent to Instagram is fetchable without authentication', function () {
    Storage::fake('local');
    Http::fake([
        '*graph.instagram.com*' => Http::response(['recipient_id' => 'igsid_123', 'message_id' => 'mid.999'], 200),
    ]);

    $fixtures = makeInstagramFixtures();

    $this->actingAs($fixtures['user'])->post(
        "/api/inbox/instagram/{$fixtures['integration']->id}/media",
        [
            'chat_id' => $fixtures['chat']->id,
            'type' => 'image',
            'file' => UploadedFile::fake()->create('photo.jpg', 50, 'image/jpeg'),
        ]
    );

    $sentUrl = null;
    Http::assertSent(function ($request) use (&$sentUrl) {
        $sentUrl = $request->data()['message']['attachments'][0]['payload']['url'];
        return true;
    });

    // Simulate Instagram's server: no session, no auth header, just GET.
    $response = $this->get($sentUrl);
    $response->assertOk();
});

test('a tampered signed media url is rejected', function () {
    Storage::fake('local');
    Http::fake([
        '*graph.instagram.com*' => Http::response(['recipient_id' => 'igsid_123', 'message_id' => 'mid.111'], 200),
    ]);

    $fixtures = makeInstagramFixtures();

    $this->actingAs($fixtures['user'])->post(
        "/api/inbox/instagram/{$fixtures['integration']->id}/media",
        [
            'chat_id' => $fixtures['chat']->id,
            'type' => 'image',
            'file' => UploadedFile::fake()->create('photo.jpg', 50, 'image/jpeg'),
        ]
    );

    $sentUrl = null;
    Http::assertSent(function ($request) use (&$sentUrl) {
        $sentUrl = $request->data()['message']['attachments'][0]['payload']['url'];
        return true;
    });

    $response = $this->get($sentUrl . 'x');
    $response->assertForbidden();
});

test('m4a audio is sent with the correct mime and is fetchable without authentication', function () {
    // M4A (audio/mp4) is the format Instagram receives after WebM→M4A conversion.
    // Verifies the full sign → serve pipeline with a format that doesn't require
    // ffmpeg in tests. OGG Opus was tried but Instagram rejects it in practice.
    Storage::fake('local');
    Http::fake([
        '*graph.instagram.com*' => Http::response(['recipient_id' => 'igsid_123', 'message_id' => 'mid.222'], 200),
    ]);

    $fixtures = makeInstagramFixtures();

    $this->actingAs($fixtures['user'])->post(
        "/api/inbox/instagram/{$fixtures['integration']->id}/media",
        [
            'chat_id' => $fixtures['chat']->id,
            'type' => 'audio',
            'file' => UploadedFile::fake()->create('voice.m4a', 10, 'audio/mp4'),
        ]
    );

    $sentUrl = null;
    Http::assertSent(function ($request) use (&$sentUrl) {
        $sentUrl = $request->data()['message']['attachment']['payload']['url'];
        return true;
    });

    expect($sentUrl)->toContain('mime=audio%2Fmp4');

    $response = $this->get($sentUrl);
    $response->assertOk();
    $response->assertHeader('Content-Type', 'audio/mp4');
});
