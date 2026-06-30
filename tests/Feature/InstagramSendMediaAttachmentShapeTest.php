<?php

use App\Models\Company;
use App\Models\InboxChat;
use App\Models\InstagramIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

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
        return isset($body['message']['attachments'])
            && !isset($body['message']['attachment'])
            && $body['message']['attachments']['type'] === 'image'
            && isset($body['message']['attachments']['payload']['url']);
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
        return !isset($body['message']['attachments']['payload']['is_reusable']);
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
    expect($payload['message']['attachments']['type'])->toBe('image');
    expect($payload['message']['attachments']['payload']['url'])->toBeString();

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
        $sentUrl = $request->data()['message']['attachments']['payload']['url'];
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
        $sentUrl = $request->data()['message']['attachments']['payload']['url'];
        return true;
    });

    $response = $this->get($sentUrl . 'x');
    $response->assertForbidden();
});

test('webm audio is served with its real audio/webm mime, not the video/webm extension guess', function () {
    // A browser's MediaRecorder typically names voice notes "<uuid>.webm"
    // and reports them as audio/webm - but the file extension alone
    // resolves to video/webm, which mismatched the declared "audio"
    // attachment type and made Instagram's fetch reject the upload.
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
            'file' => UploadedFile::fake()->create('voice.webm', 10, 'audio/webm'),
        ]
    );

    $sentUrl = null;
    Http::assertSent(function ($request) use (&$sentUrl) {
        $sentUrl = $request->data()['message']['attachment']['payload']['url'];
        return true;
    });

    expect($sentUrl)->toContain('mime=audio');

    $response = $this->get($sentUrl);
    $response->assertOk();
    $response->assertHeader('Content-Type', 'audio/webm');
});
