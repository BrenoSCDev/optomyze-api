<?php

use App\Models\Company;
use App\Models\OptusConversation;
use App\Models\OptusMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

function optusUser(string $slug = 'acme', string $role = 'admin'): User
{
    $company = Company::create(['name' => ucfirst($slug), 'email' => "{$slug}-optus@example.test"]);

    return User::factory()->create([
        'company_id' => $company->id,
        'role' => $role,
        'email' => "{$slug}-user@example.test",
    ]);
}

/** A successful OpenAI chat completion. */
function fakeOpenAi(string $reply = 'Open **Contacts** in the sidebar.'): void
{
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['content' => $reply],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 40],
        ]),
    ]);
}

beforeEach(function () {
    config(['services.openai.key' => 'sk-test-key']);
    RateLimiter::clear('optus:1');
});

test('asking a question stores both turns and returns the answer', function () {
    $user = optusUser();
    fakeOpenAi('Go to **Contacts**.');

    $res = $this->actingAs($user)->postJson('/api/optus/messages', [
        'message' => 'Where do I find my contacts?',
    ]);

    $res->assertCreated()
        ->assertJsonPath('data.role', 'assistant')
        ->assertJsonPath('data.content', 'Go to **Contacts**.');

    $conversation = OptusConversation::firstOrFail();
    expect($conversation->user_id)->toBe($user->id)
        ->and($conversation->company_id)->toBe($user->company_id)
        ->and($conversation->messages()->pluck('role')->all())->toBe(['user', 'assistant'])
        // Usage is attributed so spend can be traced back to a turn.
        ->and($conversation->messages()->where('role', 'assistant')->value('prompt_tokens'))->toBe(1200);
});

test('the api key is never sent to the client', function () {
    $user = optusUser();
    fakeOpenAi();

    $send = $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'Hi']);
    $show = $this->actingAs($user)->getJson('/api/optus/conversation');

    foreach ([$send, $show] as $res) {
        expect($res->getContent())->not->toContain('sk-test-key');
    }
});

test('the system prompt carries the knowledge base and the user role', function () {
    $user = optusUser('acme', 'agent');
    fakeOpenAi();

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'How do pipelines work?']);

    Http::assertSent(function ($request) {
        $system = collect($request->data()['messages'])->firstWhere('role', 'system')['content'];

        return str_contains($system, 'Agent')
            && str_contains($system, 'Optomyze CRM — product knowledge')
            // The grounding rule is the whole point of the feature.
            && str_contains($system, 'Never invent a feature');
    });
});

test('no tools are offered while the assistant is informational only', function () {
    $user = optusUser();
    fakeOpenAi();

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'Create a lead for me']);

    // Not a prompt instruction — the model is given no mechanism to act.
    Http::assertSent(fn ($request) => !array_key_exists('tools', $request->data()));
});

test('history is replayed so follow-up questions keep context', function () {
    $user = optusUser();
    fakeOpenAi();

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'How do I create a deal?']);
    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'And for contacts?']);

    Http::assertSent(function ($request) {
        $sent = collect($request->data()['messages']);
        $roles = $sent->pluck('role')->all();
        $contents = $sent->pluck('content')->all();

        // Content order, not just roles: [user, assistant, user] is palindromic
        // by role, so a reversed history passes a role-only assertion while the
        // model reads the conversation backwards and answers the earlier turn.
        return $roles === ['system', 'user', 'assistant', 'user']
            && $contents[1] === 'How do I create a deal?'
            && $contents[3] === 'And for contacts?';
    });
});

test('a long thread replays the newest messages, not the oldest', function () {
    $user = optusUser();
    fakeOpenAi();
    $conversation = OptusConversation::create([
        'company_id' => $user->company_id,
        'user_id' => $user->id,
    ]);

    // Well past the 20-message window.
    foreach (range(1, 30) as $i) {
        $conversation->messages()->create(['role' => 'user', 'content' => "old {$i}"]);
    }

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'the latest question']);

    Http::assertSent(function ($request) {
        $contents = collect($request->data()['messages'])->pluck('content');

        // The window must slide with the conversation. Truncating from the
        // wrong end drops what was just said and keeps what nobody remembers.
        return $contents->last() === 'the latest question'
            && $contents->contains('old 30')
            && !$contents->contains('old 1');
    });
});

test('a conversation is private to its owner', function () {
    $mine = optusUser('mine');
    $theirs = optusUser('theirs');
    fakeOpenAi('Secret answer');

    $this->actingAs($mine)->postJson('/api/optus/messages', ['message' => 'Private question']);

    $res = $this->actingAs($theirs)->getJson('/api/optus/conversation');

    $res->assertOk();
    // A fresh, empty thread — not a window onto someone else's.
    expect($res->json('data.messages'))->toBe([])
        ->and($res->json('data.id'))->not->toBe(OptusConversation::where('user_id', $mine->id)->value('id'));
});

test('clearing removes only the caller conversation', function () {
    $mine = optusUser('mine');
    $theirs = optusUser('theirs');
    fakeOpenAi();

    $this->actingAs($mine)->postJson('/api/optus/messages', ['message' => 'Mine']);
    $this->actingAs($theirs)->postJson('/api/optus/messages', ['message' => 'Theirs']);

    $this->actingAs($mine)->deleteJson('/api/optus/conversation')->assertOk();

    expect(OptusConversation::where('user_id', $mine->id)->count())->toBe(0)
        ->and(OptusConversation::where('user_id', $theirs->id)->count())->toBe(1)
        // Cascade: the deleted thread takes its messages with it.
        ->and(OptusMessage::count())->toBe(2);
});

test('a missing platform key reports a configuration problem, not a failure', function () {
    config(['services.openai.key' => '']);
    $user = optusUser();

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'Hi'])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'not_configured');
});

test('a provider failure keeps the question in the thread', function () {
    $user = optusUser();
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['error' => ['message' => 'rate limited']], 429),
    ]);

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'Where are appointments?'])
        ->assertStatus(502)
        ->assertJsonPath('reason', 'provider_error');

    // What the user typed must survive, so a retry does not mean retyping.
    expect(OptusMessage::where('role', 'user')->value('content'))->toBe('Where are appointments?')
        ->and(OptusMessage::where('role', 'assistant')->count())->toBe(0);
});

test('an empty completion is treated as a failure rather than an empty bubble', function () {
    $user = optusUser();
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '   '], 'finish_reason' => 'stop']],
        ]),
    ]);

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'Hi'])->assertStatus(502);

    expect(OptusMessage::where('role', 'assistant')->count())->toBe(0);
});

test('the assistant is rate limited per user', function () {
    $user = optusUser();
    fakeOpenAi();

    foreach (range(1, 12) as $i) {
        $this->actingAs($user)->postJson('/api/optus/messages', ['message' => "Q{$i}"])->assertCreated();
    }

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => 'One too many'])
        ->assertStatus(429);
});

test('an unauthenticated caller gets nothing', function () {
    $this->getJson('/api/optus/conversation')->assertStatus(401);
    $this->postJson('/api/optus/messages', ['message' => 'Hi'])->assertStatus(401);
});

test('an empty or oversized message is rejected', function () {
    $user = optusUser();
    fakeOpenAi();

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => '  '])
        ->assertStatus(422);

    $this->actingAs($user)->postJson('/api/optus/messages', ['message' => str_repeat('a', 4001)])
        ->assertStatus(422);
});
