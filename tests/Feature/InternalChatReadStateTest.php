<?php

use App\Events\ConversationRead;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\InternalMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function chatCompany(string $name = 'Chat Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

function chatUser(Company $company, string $handle): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'role'       => 'agent',
        'email'      => "{$handle}-{$company->id}@example.test",
        'name'       => ucfirst($handle),
    ]);
}

/** A conversation with the given members, all starting fully caught up. */
function makeConversation(Company $company, array $members, string $type = 'private'): Conversation
{
    $conversation = Conversation::create([
        'company_id'      => $company->id,
        'type'            => $type,
        'name'            => $type === 'group' ? 'Team' : null,
        'created_by'      => $members[0]->id,
        'last_message_at' => now(),
    ]);

    foreach ($members as $member) {
        $conversation->participants()->attach($member->id, [
            'role'         => 'member',
            'last_read_at' => now(),
        ]);
    }

    return $conversation->fresh('participants');
}

function say(Conversation $conversation, User $sender, string $body = 'hello'): InternalMessage
{
    return InternalMessage::create([
        'conversation_id' => $conversation->id,
        'user_id'         => $sender->id,
        'type'            => 'text',
        'body'            => $body,
    ]);
}

test('a message from someone else counts as unread', function () {
    $company = chatCompany();
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $conversation = makeConversation($company, [$alice, $bob]);

    $this->travel(5)->seconds();
    say($conversation, $bob);
    say($conversation, $bob);

    expect($conversation->unreadCountFor($alice))->toBe(2)
        // The sender has read what they just sent.
        ->and($conversation->unreadCountFor($bob))->toBe(0);
});

test('the conversation list reports each participant their own count', function () {
    $company = chatCompany();
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $conversation = makeConversation($company, [$alice, $bob]);

    $this->travel(5)->seconds();
    say($conversation, $bob);

    $forAlice = test()->actingAs($alice)->getJson('/api/chat/conversations');
    app('auth')->forgetGuards();
    $forBob = test()->actingAs($bob)->getJson('/api/chat/conversations');

    expect($forAlice->json('data.0.unread_count'))->toBe(1)
        ->and($forBob->json('data.0.unread_count'))->toBe(0);
});

test('marking read clears the count and broadcasts', function () {
    Event::fake([ConversationRead::class]);

    $company = chatCompany();
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $conversation = makeConversation($company, [$alice, $bob]);

    $this->travel(5)->seconds();
    say($conversation, $bob);

    $res = test()->actingAs($alice)->postJson("/api/chat/{$conversation->id}/read");

    $res->assertOk();
    expect($res->json('changed'))->toBeTrue()
        ->and($conversation->fresh('participants')->unreadCountFor($alice))->toBe(0);

    Event::assertDispatched(ConversationRead::class, fn ($e) => $e->userId === $alice->id);
});

test('marking an already read conversation broadcasts nothing', function () {
    Event::fake([ConversationRead::class]);

    $company = chatCompany();
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $conversation = makeConversation($company, [$alice, $bob]);

    $res = test()->actingAs($alice)->postJson("/api/chat/{$conversation->id}/read");

    $res->assertOk();
    expect($res->json('changed'))->toBeFalse();

    // Otherwise clicking through the sidebar would broadcast on every row.
    Event::assertNotDispatched(ConversationRead::class);
});

test('opening the thread marks it read', function () {
    $company = chatCompany();
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $conversation = makeConversation($company, [$alice, $bob]);

    $this->travel(5)->seconds();
    say($conversation, $bob);

    expect($conversation->unreadCountFor($alice))->toBe(1);

    test()->actingAs($alice)->getJson("/api/chat/{$conversation->id}/messages")->assertOk();

    expect($conversation->fresh('participants')->unreadCountFor($alice))->toBe(0);
});

test('a sender is told who has seen their message', function () {
    $company = chatCompany();
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $carol = chatUser($company, 'carol');
    $conversation = makeConversation($company, [$alice, $bob, $carol], 'group');

    $this->travel(5)->seconds();
    say($conversation, $alice, 'anyone there?');

    // Bob opens the thread; Carol has not.
    test()->actingAs($bob)->getJson("/api/chat/{$conversation->id}/messages")->assertOk();

    app('auth')->forgetGuards();
    $res = test()->actingAs($alice)->getJson("/api/chat/{$conversation->id}/messages");

    $res->assertOk();
    expect($res->json('data.0.read_by'))->toBe([$bob->id]);
});

test('read receipts are only shown to the author', function () {
    $company = chatCompany();
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $conversation = makeConversation($company, [$alice, $bob]);

    $this->travel(5)->seconds();
    say($conversation, $alice);

    // Bob is not the author, so who-read-it is not his question to ask.
    $res = test()->actingAs($bob)->getJson("/api/chat/{$conversation->id}/messages");

    expect($res->json('data.0.read_by'))->toBeNull();
});

test('a non-participant cannot mark a conversation read', function () {
    $company = chatCompany();
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $outsider = chatUser($company, 'outsider');
    $conversation = makeConversation($company, [$alice, $bob]);

    test()->actingAs($outsider)
        ->postJson("/api/chat/{$conversation->id}/read")
        ->assertStatus(404);
});

test('another company cannot mark a conversation read', function () {
    $company = chatCompany('Mine');
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $conversation = makeConversation($company, [$alice, $bob]);

    $other = chatCompany('Theirs');

    test()->actingAs(chatUser($other, 'intruder'))
        ->postJson("/api/chat/{$conversation->id}/read")
        ->assertStatus(404);
});

test('someone added to an existing group does not inherit its backlog', function () {
    $company = chatCompany();
    $alice = chatUser($company, 'alice');
    $bob = chatUser($company, 'bob');
    $conversation = makeConversation($company, [$alice, $bob], 'group');

    say($conversation, $alice, 'old news');
    say($conversation, $alice, 'older news');

    // Joins now, with no last_read_at — the join time is the starting point.
    $this->travel(10)->seconds();
    $dave = chatUser($company, 'dave');
    $conversation->participants()->attach($dave->id, ['role' => 'member']);

    expect($conversation->fresh('participants')->unreadCountFor($dave))->toBe(0);

    $this->travel(5)->seconds();
    say($conversation, $alice, 'something new');

    expect($conversation->fresh('participants')->unreadCountFor($dave))->toBe(1);
});
