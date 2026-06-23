<?php

use App\Models\Company;
use App\Models\Funnel;
use App\Models\InboxMessage;
use App\Models\Stage;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

function makeWhatsAppInstanceForMediaTest(): WhatsAppInstance
{
    $company = Company::create([
        'name' => 'Acme Inc',
        'email' => 'acme-media-test@example.com',
    ]);

    $user = User::factory()->create(['company_id' => $company->id]);

    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name' => 'Sales Funnel',
        'is_active' => true,
        'created_by' => $user->id,
        'type' => 'sale',
    ]);

    Stage::create([
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

    return WhatsAppInstance::create([
        'company_id' => $company->id,
        'whats_app_evo_integration_id' => $integration->id,
        'instance_name' => 'CARS_WHATSAPP_9Oowv9k2',
        'funnel_id' => $funnel->id,
        'status' => 'connected',
    ]);
}

// Real Evolution API v3.6.0 audioMessage webhook payload (trimmed of
// fields irrelevant to this test, like sha256 byte arrays).
function evolutionAudioPayload(string $instanceName): array
{
    return [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'remoteJid' => '120371390971995@lid',
                'remoteJidAlt' => '556294092429@s.whatsapp.net',
                'fromMe' => false,
                'id' => '3A4697D6686376598C4C',
                'participant' => '',
                'addressingMode' => 'lid',
            ],
            'pushName' => 'Alexia - Optomyze',
            'status' => 'DELIVERY_ACK',
            'message' => [
                'audioMessage' => [
                    'url' => 'https://mmg.whatsapp.net/v/fake.enc',
                    'mimetype' => 'audio/ogg; codecs=opus',
                    // Evolution v3.6.0 serializes protobuf Long fields like
                    // this instead of a plain integer.
                    'fileLength' => ['low' => 6289, 'high' => 0, 'unsigned' => true],
                    'seconds' => 2,
                    'ptt' => true,
                ],
            ],
            'contextInfo' => null,
            'messageType' => 'audioMessage',
            'messageTimestamp' => 1782253729,
            'instanceId' => '01d1a42a-e5ac-4419-ae83-ef962da5b60c',
            'source' => 'ios',
        ],
        'destination' => 'https://example.com/webhook',
        'date_time' => '2026-06-23T19:28:49.562Z',
        'sender' => '556295225796@s.whatsapp.net',
        'server_url' => 'https://evolution.example.com',
        'apikey' => 'fake-api-key',
    ];
}

// Real Evolution API v3.6.0 imageMessage webhook payload, trimmed of the
// large byte-array fields (jpegThumbnail, sha256s, scan sidecars) which are
// irrelevant to fileLength resolution.
function evolutionImagePayload(string $instanceName): array
{
    return [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'remoteJid' => '120371390971995@lid',
                'remoteJidAlt' => '556294092429@s.whatsapp.net',
                'fromMe' => false,
                'id' => '3AD215E7FDA98D8FC13F',
                'participant' => '',
                'addressingMode' => 'lid',
            ],
            'pushName' => 'Alexia - Optomyze',
            'status' => 'DELIVERY_ACK',
            'message' => [
                'imageMessage' => [
                    'url' => 'https://mmg.whatsapp.net/v/fake.enc',
                    'mimetype' => 'image/jpeg',
                    'fileLength' => ['low' => 117125, 'high' => 0, 'unsigned' => true],
                    'height' => 1280,
                    'width' => 720,
                ],
            ],
            'contextInfo' => [
                'mentionedJid' => [],
                'groupMentions' => [],
                'statusSourceType' => 0,
            ],
            'messageType' => 'imageMessage',
            'messageTimestamp' => 1782253750,
            'instanceId' => '01d1a42a-e5ac-4419-ae83-ef962da5b60c',
            'source' => 'ios',
        ],
        'destination' => 'https://example.com/webhook',
        'date_time' => '2026-06-23T19:29:11.110Z',
        'sender' => '556295225796@s.whatsapp.net',
        'server_url' => 'https://evolution.example.com',
        'apikey' => 'fake-api-key',
    ];
}

// Real Evolution API v3.6.0 videoMessage payload with gifPlayback=true (a
// WhatsApp "GIF" is actually a looping mp4 videoMessage), trimmed of the
// large byte-array fields irrelevant to this test.
function evolutionGifPayload(string $instanceName): array
{
    return [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'remoteJid' => '120371390971995@lid',
                'remoteJidAlt' => '556294092429@s.whatsapp.net',
                'fromMe' => false,
                'id' => '3ABDCA3744504A301CF3',
                'participant' => '',
                'addressingMode' => 'lid',
            ],
            'pushName' => 'Alexia - Optomyze',
            'status' => 'DELIVERY_ACK',
            'message' => [
                'videoMessage' => [
                    'url' => 'https://mmg.whatsapp.net/v/fake.enc',
                    'mimetype' => 'video/mp4',
                    'fileLength' => ['low' => 20396, 'high' => 0, 'unsigned' => true],
                    'seconds' => 0,
                    'gifPlayback' => true,
                    'height' => 262,
                    'width' => 200,
                ],
            ],
            'contextInfo' => [
                'mentionedJid' => [],
                'groupMentions' => [],
                'pairedMediaType' => 0,
                'statusSourceType' => 2,
            ],
            'messageType' => 'videoMessage',
            'messageTimestamp' => 1782254255,
            'instanceId' => '01d1a42a-e5ac-4419-ae83-ef962da5b60c',
            'source' => 'ios',
        ],
        'destination' => 'https://example.com/webhook',
        'date_time' => '2026-06-23T19:37:35.984Z',
        'sender' => '556295225796@s.whatsapp.net',
        'server_url' => 'https://evolution.example.com',
        'apikey' => 'fake-api-key',
    ];
}

// Real Evolution API v3.6.0 static stickerMessage payload (isAnimated=false),
// trimmed of the large byte-array fields irrelevant to this test.
function evolutionStaticStickerPayload(string $instanceName): array
{
    return [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'remoteJid' => '120371390971995@lid',
                'remoteJidAlt' => '556294092429@s.whatsapp.net',
                'fromMe' => false,
                'id' => '3A90AFA30D2C685BA00C',
                'participant' => '',
                'addressingMode' => 'lid',
            ],
            'pushName' => 'Alexia - Optomyze',
            'status' => 'DELIVERY_ACK',
            'message' => [
                'stickerMessage' => [
                    'url' => 'https://mmg.whatsapp.net/v/fake.enc',
                    'mimetype' => 'image/webp',
                    'height' => 512,
                    'width' => 512,
                    'fileLength' => ['low' => 8076, 'high' => 0, 'unsigned' => true],
                    'isAnimated' => false,
                    'stickerSentTs' => ['low' => -156654887, 'high' => 414, 'unsigned' => false],
                    'isAvatar' => false,
                    'isAiSticker' => false,
                    'isLottie' => false,
                    'accessibilityLabel' => '',
                ],
            ],
            'contextInfo' => null,
            'messageType' => 'stickerMessage',
            'messageTimestamp' => 1782254773,
            'instanceId' => '01d1a42a-e5ac-4419-ae83-ef962da5b60c',
            'source' => 'ios',
        ],
        'destination' => 'https://example.com/webhook',
        'date_time' => '2026-06-23T19:46:13.987Z',
        'sender' => '556295225796@s.whatsapp.net',
        'server_url' => 'https://evolution.example.com',
        'apikey' => 'fake-api-key',
    ];
}

// Real Evolution API v3.6.0 animated stickerMessage payload (isAnimated=true),
// trimmed of the large byte-array fields irrelevant to this test.
function evolutionAnimatedStickerPayload(string $instanceName): array
{
    return [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'remoteJid' => '120371390971995@lid',
                'remoteJidAlt' => '556294092429@s.whatsapp.net',
                'fromMe' => false,
                'id' => '3A76665B7C9801C1948E',
                'participant' => '',
                'addressingMode' => 'lid',
            ],
            'pushName' => 'Alexia - Optomyze',
            'status' => 'DELIVERY_ACK',
            'message' => [
                'stickerMessage' => [
                    'url' => 'https://mmg.whatsapp.net/v/fake.enc',
                    'mimetype' => 'image/webp',
                    'fileLength' => ['low' => 247098, 'high' => 0, 'unsigned' => true],
                    'firstFrameLength' => 246684,
                    'isAnimated' => true,
                    'stickerSentTs' => ['low' => -156588727, 'high' => 414, 'unsigned' => false],
                    'isAvatar' => false,
                    'isAiSticker' => false,
                    'isLottie' => false,
                    'accessibilityLabel' => '',
                ],
            ],
            'contextInfo' => null,
            'messageType' => 'stickerMessage',
            'messageTimestamp' => 1782254839,
            'instanceId' => '01d1a42a-e5ac-4419-ae83-ef962da5b60c',
            'source' => 'ios',
        ],
        'destination' => 'https://example.com/webhook',
        'date_time' => '2026-06-23T19:47:19.693Z',
        'sender' => '556295225796@s.whatsapp.net',
        'server_url' => 'https://evolution.example.com',
        'apikey' => 'fake-api-key',
    ];
}

test('audio message with Long-style fileLength is stored without error', function () {
    Http::fake(['*/chat/getBase64FromMediaMessage/*' => Http::response(['base64' => null], 200)]);

    $instance = makeWhatsAppInstanceForMediaTest();

    $response = $this->postJson('/api/whatsapp-evo-chat', evolutionAudioPayload($instance->instance_name));

    $response->assertOk();

    $message = InboxMessage::whereHas(
        'chat',
        fn ($q) => $q->where('channelable_id', $instance->id)
    )->first();

    expect($message)->not->toBeNull();
    expect($message->type)->toBe('audio');
    expect($message->media_size)->toBe(6289);
});

test('image message with Long-style fileLength is stored without error', function () {
    Http::fake(['*/chat/getBase64FromMediaMessage/*' => Http::response(['base64' => null], 200)]);

    $instance = makeWhatsAppInstanceForMediaTest();

    $response = $this->postJson('/api/whatsapp-evo-chat', evolutionImagePayload($instance->instance_name));

    $response->assertOk();

    $message = InboxMessage::whereHas(
        'chat',
        fn ($q) => $q->where('channelable_id', $instance->id)
    )->first();

    expect($message)->not->toBeNull();
    expect($message->type)->toBe('image');
    expect($message->media_size)->toBe(117125);
});

test('gif (videoMessage with gifPlayback) is stored as a video with gif_playback metadata', function () {
    Http::fake(['*/chat/getBase64FromMediaMessage/*' => Http::response(['base64' => null], 200)]);

    $instance = makeWhatsAppInstanceForMediaTest();

    $response = $this->postJson('/api/whatsapp-evo-chat', evolutionGifPayload($instance->instance_name));

    $response->assertOk();

    $message = InboxMessage::whereHas(
        'chat',
        fn ($q) => $q->where('channelable_id', $instance->id)
    )->first();

    expect($message)->not->toBeNull();
    expect($message->type)->toBe('video');
    expect($message->media_size)->toBe(20396);
    expect($message->media_mime)->toBe('video/mp4');
    expect($message->metadata)->toBe(['gif_playback' => true]);
});

test('static sticker (isAnimated=false) with Long-style fileLength is stored without error', function () {
    Http::fake(['*/chat/getBase64FromMediaMessage/*' => Http::response(['base64' => null], 200)]);

    $instance = makeWhatsAppInstanceForMediaTest();

    $response = $this->postJson('/api/whatsapp-evo-chat', evolutionStaticStickerPayload($instance->instance_name));

    $response->assertOk();

    $message = InboxMessage::whereHas(
        'chat',
        fn ($q) => $q->where('channelable_id', $instance->id)
    )->first();

    expect($message)->not->toBeNull();
    expect($message->type)->toBe('sticker');
    expect($message->media_size)->toBe(8076);
    expect($message->media_mime)->toBe('image/webp');
});

test('animated sticker (isAnimated=true) with Long-style fileLength is stored without error', function () {
    Http::fake(['*/chat/getBase64FromMediaMessage/*' => Http::response(['base64' => null], 200)]);

    $instance = makeWhatsAppInstanceForMediaTest();

    $response = $this->postJson('/api/whatsapp-evo-chat', evolutionAnimatedStickerPayload($instance->instance_name));

    $response->assertOk();

    $message = InboxMessage::whereHas(
        'chat',
        fn ($q) => $q->where('channelable_id', $instance->id)
    )->first();

    expect($message)->not->toBeNull();
    expect($message->type)->toBe('sticker');
    expect($message->media_size)->toBe(247098);
    expect($message->media_mime)->toBe('image/webp');
});
