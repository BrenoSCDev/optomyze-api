<?php

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A private conversation takes its name from the *other* participant. Deleting
 * that user cascades their `conversation_user` pivot row away (see
 * 2026_02_20_000002_create_conversation_user_table) but leaves the conversation
 * standing, so the name resolves to null.
 *
 * That null crashed the Optomyze Teams page, which called `.toLowerCase()` on
 * it. These tests pin the contract the frontend now codes against:
 * `ChatConversation.name` is nullable.
 */
function makeConversationFixtures(): array
{
    $company = Company::create([
        'name' => 'Acme Inc',
        'email' => 'acme-conversation-name-test@example.com',
    ]);

    $me = User::factory()->create(['company_id' => $company->id, 'name' => 'Me']);
    $other = User::factory()->create(['company_id' => $company->id, 'name' => 'Other Person']);

    $private = Conversation::create([
        'company_id' => $company->id,
        'type' => 'private',
        'name' => null,
        'last_message_at' => now(),
    ]);
    $private->participants()->attach([$me->id, $other->id]);

    return compact('company', 'me', 'other', 'private');
}

test('private conversation is named after the other participant', function () {
    $f = makeConversationFixtures();

    $response = $this->actingAs($f['me'])->getJson('/api/chat/conversations');

    $response->assertOk();
    expect($response->json('data.0.name'))->toBe('Other Person');
});

test('private conversation name is null once the other participant is deleted', function () {
    $f = makeConversationFixtures();

    $f['other']->delete();

    $response = $this->actingAs($f['me'])->getJson('/api/chat/conversations');

    $response->assertOk();
    // The conversation survives the cascade, but has no one left to name it.
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBeNull();
});
