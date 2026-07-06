<?php

use App\Models\Company;
use App\Models\Funnel;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\Stage;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

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

// Real Evolution API v3.6.0 inbound documentMessage payload (PDF), trimmed
// of the large byte-array fields irrelevant to this test.
function evolutionPdfDocumentPayload(string $instanceName): array
{
    return [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'remoteJid' => '120371390971995@lid',
                'remoteJidAlt' => '556294092429@s.whatsapp.net',
                'fromMe' => false,
                'id' => '3A3E7689185936F2523B',
                'participant' => '',
                'addressingMode' => 'lid',
            ],
            'pushName' => 'Alexia - Optomyze',
            'status' => 'DELIVERY_ACK',
            'message' => [
                'documentMessage' => [
                    'url' => 'https://mmg.whatsapp.net/v/fake.enc',
                    'mimetype' => 'application/pdf',
                    'title' => '71C7814B-1A82-437B-A828-4FDB8B1B8875',
                    'fileLength' => ['low' => 43798, 'high' => 0, 'unsigned' => true],
                    'pageCount' => 1,
                    'fileName' => '71C7814B-1A82-437B-A828-4FDB8B1B8875.pdf',
                    'contactVcard' => false,
                ],
            ],
            'contextInfo' => null,
            'messageType' => 'documentMessage',
            'messageTimestamp' => 1782256019,
            'instanceId' => '01d1a42a-e5ac-4419-ae83-ef962da5b60c',
            'source' => 'ios',
        ],
        'destination' => 'https://example.com/webhook',
        'date_time' => '2026-06-23T20:06:59.265Z',
        'sender' => '556295225796@s.whatsapp.net',
        'server_url' => 'https://evolution.example.com',
        'apikey' => 'fake-api-key',
    ];
}

// Real Evolution API v3.6.0 outbound (fromMe=true) documentMessage payload
// (Word doc, sent directly from the connected phone), trimmed of the large
// byte-array fields irrelevant to this test.
function evolutionWordDocumentPayload(string $instanceName): array
{
    return [
        'event' => 'messages.upsert',
        'instance' => $instanceName,
        'data' => [
            'key' => [
                'remoteJid' => '120371390971995@lid',
                'remoteJidAlt' => '556294092429@s.whatsapp.net',
                'fromMe' => true,
                'id' => '2A95180632DEDF2A713D',
                'participant' => '',
                'addressingMode' => 'lid',
            ],
            'pushName' => 'Breno Castro',
            'status' => 'SERVER_ACK',
            'message' => [
                'documentMessage' => [
                    'url' => 'https://mmg.whatsapp.net/v/fake.enc',
                    'mimetype' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'title' => 'COLD CALL SCRIPT BAD WEBSITE',
                    'fileLength' => ['low' => 8533, 'high' => 0, 'unsigned' => true],
                    'fileName' => 'COLD CALL SCRIPT BAD WEBSITE.docx',
                    'contactVcard' => false,
                    'contextInfo' => [
                        'mentionedJid' => [],
                        'groupMentions' => [],
                        'forwardingScore' => 1,
                        'isForwarded' => true,
                        'pairedMediaType' => 0,
                    ],
                ],
            ],
            'contextInfo' => [
                'mentionedJid' => [],
                'groupMentions' => [],
                'forwardingScore' => 1,
                'isForwarded' => true,
                'pairedMediaType' => 0,
            ],
            'messageType' => 'documentMessage',
            'messageTimestamp' => 1782256149,
            'instanceId' => '01d1a42a-e5ac-4419-ae83-ef962da5b60c',
            'source' => 'unknown',
        ],
        'destination' => 'https://example.com/webhook',
        'date_time' => '2026-06-23T20:09:09.847Z',
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

test('pdf document message with Long-style fileLength is stored without error', function () {
    Http::fake(['*/chat/getBase64FromMediaMessage/*' => Http::response(['base64' => null], 200)]);

    $instance = makeWhatsAppInstanceForMediaTest();

    $response = $this->postJson('/api/whatsapp-evo-chat', evolutionPdfDocumentPayload($instance->instance_name));

    $response->assertOk();

    $message = InboxMessage::whereHas(
        'chat',
        fn ($q) => $q->where('channelable_id', $instance->id)
    )->first();

    expect($message)->not->toBeNull();
    expect($message->type)->toBe('document');
    expect($message->media_mime)->toBe('application/pdf');
    expect($message->media_size)->toBe(43798);
    expect($message->text)->toBe('71C7814B-1A82-437B-A828-4FDB8B1B8875.pdf');
});

test('outbound docx document message (fromMe=true) with Long-style fileLength is stored without error', function () {
    Http::fake(['*/chat/getBase64FromMediaMessage/*' => Http::response(['base64' => null], 200)]);

    $instance = makeWhatsAppInstanceForMediaTest();

    // Outbound-only messages only attach to an already-existing chat
    // (outbound never fabricates a new one) - simulate one already there.
    $chat = InboxChat::create([
        'company_id' => $instance->company_id,
        'channel' => InboxChat::CHANNEL_WHATSAPP,
        'channelable_type' => WhatsAppInstance::class,
        'channelable_id' => $instance->id,
        'external_chat_id' => '556294092429@s.whatsapp.net',
        'external_contact_id' => '556294092429',
        'last_message_at' => now(),
    ]);

    $response = $this->postJson('/api/whatsapp-evo-chat', evolutionWordDocumentPayload($instance->instance_name));

    $response->assertOk();

    $message = InboxMessage::where('inbox_chat_id', $chat->id)->first();

    expect($message)->not->toBeNull();
    expect($message->type)->toBe('document');
    expect($message->direction)->toBe('outgoing');
    expect($message->media_mime)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($message->media_size)->toBe(8533);
    expect($message->text)->toBe('COLD CALL SCRIPT BAD WEBSITE.docx');
});

test('webhook with @lid remoteJid and no remoteJidAlt is skipped without creating a lead', function () {
    // Evolution sometimes fires a second webhook event for the same message
    // using only the internal @lid identifier and no remoteJidAlt. Without a
    // real phone number we cannot associate the message to a contact, so the
    // event must be dropped silently (200 OK so Evolution doesn't retry) rather
    // than creating a garbage lead whose name/phone are the raw @lid string.
    $instance = makeWhatsAppInstanceForMediaTest();

    $payload = [
        'event' => 'messages.upsert',
        'instance' => $instance->instance_name,
        'data' => [
            'key' => [
                'remoteJid' => '256482629148725@lid',
                // remoteJidAlt intentionally absent
                'fromMe' => false,
                'id' => 'AAAABBBBCCCCDDDD',
                'addressingMode' => 'lid',
            ],
            'pushName' => '',
            'message' => ['conversation' => 'hello'],
            'messageType' => 'conversation',
            'messageTimestamp' => time(),
        ],
    ];

    $leadsBefore = \App\Models\Lead::where('company_id', $instance->company_id)->count();

    $response = $this->postJson('/api/whatsapp-evo-chat', $payload);

    $response->assertOk();
    expect(\App\Models\Lead::where('company_id', $instance->company_id)->count())->toBe($leadsBefore);
});
