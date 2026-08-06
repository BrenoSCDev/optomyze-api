<?php

/**
 * Website chat has no third-party API, so the interesting surface is the
 * public one: what a tampered client can name, and what reaches the visitor's
 * browser.
 */

use App\Models\Company;
use App\Models\InboxMessage;
use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\WidgetTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeChatWidget(?Company $company = null): Widget
{
    $company ??= Company::create([
        'name'      => 'Widget Co',
        'email'     => 'chat-' . uniqid() . '@example.test',
        'is_active' => true,
    ]);

    $type = WidgetTypeRegistry::resolve('chat');

    return Widget::create([
        'company_id'      => $company->id,
        'type'            => 'chat',
        'name'            => 'Site chat',
        'status'          => 'active',
        'allowed_domains' => ['example.com'],
        'config'          => $type->normalizeConfig($type->defaultConfig(), $company->id),
        'public_id'       => Str::uuid()->toString(),
    ]);
}

/**
 * Opens a visitor session, clears the pre-chat form the default config asks
 * for, and sends one message. Returns the token and the stored message.
 */
function startVisitorChat(Widget $widget, string $text = 'Hello there'): array
{
    $session = test()->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/session",
        ['page_url' => 'https://example.com/pricing'],
        ['Origin' => 'https://example.com']
    )->assertOk();

    $token = $session->json('data.visitor_token');

    test()->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/identify",
        ['name' => 'Visitor ' . Str::random(5), 'email' => Str::random(8) . '@example.com'],
        ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token]
    )->assertOk();

    $sent = test()->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages",
        ['text' => $text],
        ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token]
    )->assertCreated();

    return [$token, InboxMessage::find($sent->json('data.id'))];
}

test('a visitor can quote an earlier message in the same conversation', function () {
    $widget = makeChatWidget();
    [$token, $first] = startVisitorChat($widget);

    $this->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages",
        ['text' => 'Actually, about that', 'reply_to_message_id' => $first->id],
        ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token]
    )->assertCreated();

    $reply = InboxMessage::latest('id')->first();

    expect($reply->reply_to_message_id)->toBe($first->id)
        ->and($reply->reply_to_snapshot['text'])->toBe('Hello there');
});

test('a visitor cannot quote a message from another visitor conversation', function () {
    $widget = makeChatWidget();
    [$token] = startVisitorChat($widget);
    [, $foreign] = startVisitorChat($widget, 'Someone else entirely');

    $this->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages",
        ['text' => 'Leaking?', 'reply_to_message_id' => $foreign->id],
        ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token]
    )->assertStatus(422);
});

test('a visitor cannot quote or react to an internal note', function () {
    $widget = makeChatWidget();
    [$token, $visitorMessage] = startVisitorChat($widget);

    $note = InboxMessage::create([
        'inbox_chat_id'       => $visitorMessage->inbox_chat_id,
        'external_message_id' => (string) Str::uuid(),
        'direction'           => 'outgoing',
        'message_mode'        => 'internal',
        'type'                => 'text',
        'text'                => 'Careful, this one is a tyre-kicker',
        'status'              => 'sent',
        'sent_at'             => now(),
    ]);

    $headers = ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token];

    $this->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages",
        ['text' => 'What was that?', 'reply_to_message_id' => $note->id],
        $headers
    )->assertStatus(422);

    $this->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages/{$note->id}/reaction",
        ['emoji' => '👍'],
        $headers
    )->assertStatus(422);

    expect($note->fresh()->reactions)->toBeNull();
});

test('an internal note never reaches the visitor, quoted or otherwise', function () {
    $widget = makeChatWidget();
    [$token, $visitorMessage] = startVisitorChat($widget);

    InboxMessage::create([
        'inbox_chat_id'       => $visitorMessage->inbox_chat_id,
        'external_message_id' => (string) Str::uuid(),
        'direction'           => 'outgoing',
        'message_mode'        => 'internal',
        'type'                => 'text',
        'text'                => 'Internal only',
        'status'              => 'sent',
        'sent_at'             => now(),
    ]);

    $body = $this->getJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages",
        ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token]
    )->assertOk()->json('data.messages');

    expect(collect($body)->pluck('text'))->not->toContain('Internal only');
});

test('an agent cannot quote an internal note in a visitor-visible reply', function () {
    $widget = makeChatWidget();
    [, $visitorMessage] = startVisitorChat($widget);

    $agent = User::factory()->create(['company_id' => $widget->company_id]);

    $note = InboxMessage::create([
        'inbox_chat_id'       => $visitorMessage->inbox_chat_id,
        'external_message_id' => (string) Str::uuid(),
        'direction'           => 'outgoing',
        'message_mode'        => 'internal',
        'type'                => 'text',
        'text'                => 'Do not show this to them',
        'status'              => 'sent',
        'sent_at'             => now(),
    ]);

    $this->actingAs($agent)->postJson(
        "/api/inbox/chats/{$visitorMessage->inbox_chat_id}/website-messages",
        ['text' => 'About your question', 'reply_to_message_id' => $note->id]
    )->assertStatus(422);

    // The same note is fine to quote in another internal note.
    $this->actingAs($agent)->postJson(
        "/api/inbox/chats/{$visitorMessage->inbox_chat_id}/website-messages",
        ['text' => 'Agreed', 'message_mode' => 'internal', 'reply_to_message_id' => $note->id]
    )->assertOk();
});

test('a visitor reaction lands on the message and rides the poll back', function () {
    $widget = makeChatWidget();
    [$token, $message] = startVisitorChat($widget);
    $headers = ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token];

    $this->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages/{$message->id}/reaction",
        ['emoji' => '🙏'],
        $headers
    )->assertOk();

    expect($message->fresh()->reactions['contact']['emoji'])->toBe('🙏');

    // The poll asks only for messages after this one, so the reaction has to
    // travel in the conversation-wide map instead.
    $this->getJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages?after_id={$message->id}",
        $headers
    )->assertOk()->assertJsonPath("data.reactions.{$message->id}.contact", '🙏');
});

test('an empty emoji clears the visitor reaction', function () {
    $widget = makeChatWidget();
    [$token, $message] = startVisitorChat($widget);
    $headers = ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token];

    $this->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages/{$message->id}/reaction",
        ['emoji' => '🙏'],
        $headers
    )->assertOk();

    $this->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages/{$message->id}/reaction",
        ['emoji' => ''],
        $headers
    )->assertOk();

    expect($message->fresh()->reactions)->not->toHaveKey('contact');
});

test('an agent reaction on a website chat needs no provider call', function () {
    $widget = makeChatWidget();
    [$token, $message] = startVisitorChat($widget);

    $agent = User::factory()->create(['company_id' => $widget->company_id]);

    $this->actingAs($agent)
        ->postJson("/api/inbox/messages/{$message->id}/reaction", ['emoji' => '❤️'])
        ->assertOk();

    expect($message->fresh()->reactions['agent']['emoji'])->toBe('❤️');

    $this->getJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages",
        ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token]
    )->assertOk()->assertJsonPath("data.reactions.{$message->id}.agent", '❤️');
});

test('a visitor reply is rendered back with its quoted excerpt', function () {
    $widget = makeChatWidget();
    [$token, $first] = startVisitorChat($widget);

    $this->postJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages",
        ['text' => 'Following up', 'reply_to_message_id' => $first->id],
        ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token]
    )->assertCreated();

    $messages = $this->getJson(
        "/api/public/widgets/{$widget->public_id}/chat/messages",
        ['Origin' => 'https://example.com', 'X-Visitor-Token' => $token]
    )->assertOk()->json('data.messages');

    $reply = collect($messages)->firstWhere('text', 'Following up');

    expect($reply['reply_to']['text'])->toBe('Hello there')
        ->and($reply['reply_to']['id'])->toBe($first->id);
});
