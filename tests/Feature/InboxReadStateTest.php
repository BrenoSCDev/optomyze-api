<?php

use App\Events\InboxChatUpdated;
use App\Events\InboxMessagesRead;
use App\Models\Company;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function inboxCompany(): Company
{
    return Company::create(['name' => 'Inbox Co', 'email' => 'inbox@example.test', 'is_active' => true]);
}

function inboxAdmin(Company $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'role'       => 'admin',
        'email'      => "admin-{$company->id}@example.test",
    ]);
}

function makeChat(Company $company, array $attributes = []): InboxChat
{
    static $seq = 0;
    $seq++;

    return InboxChat::create(array_merge([
        'company_id'       => $company->id,
        'channel'          => 'website',
        'external_chat_id'    => "chat-{$company->id}-{$seq}",
        'external_contact_id' => "visitor-{$company->id}-{$seq}",
        'contact_name'     => 'Visitor',
        'last_message_at'  => now(),
    ], $attributes));
}

function addMessage(InboxChat $chat, string $direction, string $status = 'delivered'): InboxMessage
{
    static $msgSeq = 0;
    $msgSeq++;

    return InboxMessage::create([
        'inbox_chat_id'       => $chat->id,
        'external_message_id' => "msg-{$chat->id}-{$msgSeq}",
        'source'        => $direction === 'incoming' ? InboxMessage::SOURCE_CONTACT : InboxMessage::SOURCE_USER,
        'direction'     => $direction,
        'message_mode'  => 'external',
        'type'          => 'text',
        'text'          => 'hello',
        'status'        => $status,
        'sent_at'       => now(),
    ]);
}

test('the chat list reports how many inbound messages are unread', function () {
    $company = inboxCompany();
    $chat = makeChat($company);
    addMessage($chat, 'incoming');
    addMessage($chat, 'incoming');
    addMessage($chat, 'incoming', InboxMessage::STATUS_READ);
    addMessage($chat, 'outgoing');   // our own messages never count as unread

    $res = test()->actingAs(inboxAdmin($company))->getJson('/api/inbox/chats');

    $res->assertOk();
    expect($res->json('data.0.unread_count'))->toBe(2);
});

test('opening a conversation marks its inbound messages read', function () {
    Event::fake([InboxMessagesRead::class]);

    $company = inboxCompany();
    $chat = makeChat($company);
    addMessage($chat, 'incoming');
    addMessage($chat, 'incoming');
    $outgoing = addMessage($chat, 'outgoing');

    $res = test()->actingAs(inboxAdmin($company))->postJson("/api/inbox/chats/{$chat->id}/read");

    $res->assertOk();
    expect($res->json('marked'))->toBe(2)
        ->and($chat->unreadMessages()->count())->toBe(0)
        // The outbound message's own delivery state is untouched.
        ->and($outgoing->fresh()->status)->toBe('delivered');

    Event::assertDispatched(InboxMessagesRead::class, fn ($e) => $e->direction === 'incoming');
});

test('re-opening an already read conversation broadcasts nothing', function () {
    Event::fake([InboxMessagesRead::class]);

    $company = inboxCompany();
    $chat = makeChat($company);
    addMessage($chat, 'incoming', InboxMessage::STATUS_READ);

    $res = test()->actingAs(inboxAdmin($company))->postJson("/api/inbox/chats/{$chat->id}/read");

    $res->assertOk();
    expect($res->json('marked'))->toBe(0);

    // Otherwise clicking through the list would spam everyone's inbox.
    Event::assertNotDispatched(InboxMessagesRead::class);
});

test('another company cannot mark a conversation read', function () {
    $company = inboxCompany();
    $chat = makeChat($company);
    addMessage($chat, 'incoming');

    $other = Company::create(['name' => 'Other', 'email' => 'other@example.test', 'is_active' => true]);

    test()->actingAs(inboxAdmin($other))
        ->postJson("/api/inbox/chats/{$chat->id}/read")
        ->assertStatus(403);

    expect($chat->unreadMessages()->count())->toBe(1);
});

test('the unread and read filters select opposite sets', function () {
    $company = inboxCompany();
    $admin = inboxAdmin($company);

    $unreadChat = makeChat($company, ['contact_name' => 'Has unread']);
    addMessage($unreadChat, 'incoming');

    $readChat = makeChat($company, ['contact_name' => 'All read']);
    addMessage($readChat, 'incoming', InboxMessage::STATUS_READ);

    $names = fn (string $state) => collect(
        test()->actingAs($admin)->getJson("/api/inbox/chats?read_state={$state}")->json('data')
    )->pluck('contact_name')->all();

    expect($names('unread'))->toBe(['Has unread'])
        ->and($names('read'))->toBe(['All read'])
        ->and(count($names('all')))->toBe(2);
});

test('archived conversations are hidden until asked for', function () {
    $company = inboxCompany();
    $admin = inboxAdmin($company);

    makeChat($company, ['contact_name' => 'Active']);
    makeChat($company, ['contact_name' => 'Put away', 'is_archived' => true]);

    $defaultList = test()->actingAs($admin)->getJson('/api/inbox/chats')->json('data');
    $archivedList = test()->actingAs($admin)->getJson('/api/inbox/chats?archived=1')->json('data');

    expect(collect($defaultList)->pluck('contact_name')->all())->toBe(['Active'])
        ->and(collect($archivedList)->pluck('contact_name')->all())->toBe(['Put away']);
});

test('favourites sort above newer conversations', function () {
    $company = inboxCompany();
    $admin = inboxAdmin($company);

    makeChat($company, ['contact_name' => 'Newest', 'last_message_at' => now()]);
    makeChat($company, ['contact_name' => 'Pinned but old', 'last_message_at' => now()->subDays(30), 'is_favorite' => true]);

    $list = test()->actingAs($admin)->getJson('/api/inbox/chats')->json('data');

    // The whole point of the pin: recency stops deciding the top of the list.
    expect(collect($list)->pluck('contact_name')->all())->toBe(['Pinned but old', 'Newest']);
});

test('the favourite filter returns only pinned conversations', function () {
    $company = inboxCompany();
    $admin = inboxAdmin($company);

    makeChat($company, ['contact_name' => 'Plain']);
    makeChat($company, ['contact_name' => 'Pinned', 'is_favorite' => true]);

    $list = test()->actingAs($admin)->getJson('/api/inbox/chats?favorite=1')->json('data');

    expect(collect($list)->pluck('contact_name')->all())->toBe(['Pinned']);
});

test('archiving and favouriting toggle and broadcast', function () {
    Event::fake([InboxChatUpdated::class]);

    $company = inboxCompany();
    $admin = inboxAdmin($company);
    $chat = makeChat($company);

    // No body: the endpoint flips whatever the current value is.
    test()->actingAs($admin)->patchJson("/api/inbox/chats/{$chat->id}/favorite")->assertOk();
    expect($chat->fresh()->is_favorite)->toBeTrue();

    test()->actingAs($admin)->patchJson("/api/inbox/chats/{$chat->id}/favorite")->assertOk();
    expect($chat->fresh()->is_favorite)->toBeFalse();

    // An explicit value wins over the toggle.
    test()->actingAs($admin)->patchJson("/api/inbox/chats/{$chat->id}/archived", ['archived' => true])->assertOk();
    expect($chat->fresh()->is_archived)->toBeTrue();

    Event::assertDispatched(InboxChatUpdated::class, 3);
});

test('another company cannot archive a conversation', function () {
    $company = inboxCompany();
    $chat = makeChat($company);
    $other = Company::create(['name' => 'Other', 'email' => 'other@example.test', 'is_active' => true]);

    test()->actingAs(inboxAdmin($other))
        ->patchJson("/api/inbox/chats/{$chat->id}/archived", ['archived' => true])
        ->assertStatus(403);

    expect($chat->fresh()->is_archived)->toBeFalse();
});

test('broadcast timestamps carry the company timezone, not UTC', function () {
    // The cache is per-request in production; in tests the static survives
    // between cases, so a company id reused by RefreshDatabase would keep the
    // previous case's timezone.
    App\Support\CompanyTimezoneCache::flush();

    $company = inboxCompany();
    $company->update(['timezone' => 'America/Sao_Paulo']);   // UTC-3, no DST

    $chat = makeChat($company);
    $message = addMessage($chat, 'incoming');

    $payload = (new App\Events\InboxMessageReceived($message))->broadcastWith();

    // The bug: a bare Carbon in a broadcast payload JSON-encodes as UTC, so
    // the chat list jumped by the company's offset the moment a live message
    // arrived while the message bubble beside it stayed correct.
    expect($payload['chat']['last_message_at'])->toEndWith('-03:00')
        ->and($payload['message']['sent_at'])->toEndWith('-03:00');
});

test('the widget reports how far the visitor has been read', function () {
    $company = inboxCompany();
    $chat = makeChat($company);

    $first = addMessage($chat, 'incoming');
    $second = addMessage($chat, 'incoming');
    addMessage($chat, 'outgoing');

    $widget = App\Models\Widget::create([
        'company_id'      => $company->id,
        'type'            => 'chat',
        'name'            => 'Site chat',
        'status'          => 'active',
        'allowed_domains' => ['example.com'],
        'config'          => App\Services\Widgets\WidgetTypeRegistry::resolve('chat')->defaultConfig(),
        'public_id'       => Illuminate\Support\Str::uuid()->toString(),
    ]);

    $visitor = App\Models\WidgetChatVisitor::create([
        'widget_id'     => $widget->id,
        'company_id'    => $company->id,
        'visitor_id'    => 'v-1',
        'token'         => 'tok-1',
        'inbox_chat_id' => $chat->id,
        'identified'    => false,
    ]);

    $service = new App\Services\Widgets\WebsiteChatService();

    // Nothing read yet: no marker to show.
    expect($service->seenUntilId($visitor))->toBeNull();

    $chat->markInboundRead();

    // A high-water mark, so a poll asking only for newer messages still
    // learns that the earlier ones were read.
    expect($service->seenUntilId($visitor))->toBe($second->id)
        ->and($service->seenUntilId($visitor))->toBeGreaterThan($first->id);
});
