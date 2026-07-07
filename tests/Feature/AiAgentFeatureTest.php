<?php

use App\Jobs\ProcessAiAgentTurn;
use App\Models\AiAgent;
use App\Models\AiAgentChannelBinding;
use App\Models\ChatSession;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\Lead;
use App\Models\OpenAiIntegration;
use App\Models\Stage;
use App\Models\User;
use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsAppInstance;
use App\Services\Ai\AiAgentDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function aiAgentFixtures(): array
{
    $company = Company::create([
        'name' => 'AI Test Co',
        'email' => 'ai-test-co@example.com',
    ]);

    $user = User::factory()->create(['company_id' => $company->id]);

    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name' => 'Sales',
        'is_active' => true,
        'created_by' => $user->id,
        'type' => 'sale',
    ]);

    $stage = Stage::create([
        'funnel_id' => $funnel->id,
        'name' => 'Entry',
        'order' => 1,
        'type' => 'entry',
    ]);

    $evoIntegration = WhatsAppEvoIntegration::create([
        'name' => 'Main',
        'company_id' => $company->id,
        'base_url' => 'https://evolution.example.com',
        'api_key' => 'fake-key',
    ]);

    $instance = WhatsAppInstance::create([
        'company_id' => $company->id,
        'whats_app_evo_integration_id' => $evoIntegration->id,
        'instance_name' => 'INSTANCE_AI',
        'funnel_id' => $funnel->id,
        'status' => 'connected',
    ]);

    $openAi = OpenAiIntegration::create([
        'company_id' => $company->id,
        'api_key' => 'sk-test-key',
        'connection_status' => OpenAiIntegration::CONNECTION_CONNECTED,
        'is_active' => true,
    ]);

    $agent = AiAgent::create([
        'company_id' => $company->id,
        'name' => 'Sales Bot',
        'status' => AiAgent::STATUS_ENABLED,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'response_delay_seconds' => 15,
        'system_prompt' => 'You are a helpful sales assistant.',
        'enabled_tools' => ['search_products'],
    ]);

    AiAgentChannelBinding::create([
        'company_id' => $company->id,
        'ai_agent_id' => $agent->id,
        'channelable_type' => WhatsAppInstance::class,
        'channelable_id' => $instance->id,
    ]);

    $lead = Lead::create([
        'company_id' => $company->id,
        'funnel_id' => $funnel->id,
        'stage_id' => $stage->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'phone' => '556291110001',
    ]);

    $chat = InboxChat::create([
        'company_id' => $company->id,
        'channel' => InboxChat::CHANNEL_WHATSAPP,
        'channelable_type' => WhatsAppInstance::class,
        'channelable_id' => $instance->id,
        'lead_id' => $lead->id,
        'external_chat_id' => '556291110001@s.whatsapp.net',
        'external_contact_id' => '556291110001',
        'is_group' => false,
        'ai_agent_service' => true,
        'last_message_at' => now(),
    ]);

    $session = ChatSession::create([
        'company_id' => $company->id,
        'inbox_chat_id' => $chat->id,
        'lead_id' => $lead->id,
        'channel' => InboxChat::CHANNEL_WHATSAPP,
        'channelable_type' => WhatsAppInstance::class,
        'channelable_id' => $instance->id,
        'started_at' => now(),
        'status' => ChatSession::STATUS_ACTIVE,
    ]);

    return compact('company', 'user', 'funnel', 'stage', 'instance', 'openAi', 'agent', 'lead', 'chat', 'session');
}

function inboundMessage(array $fx, string $text, array $overrides = []): InboxMessage
{
    return InboxMessage::create(array_merge([
        'inbox_chat_id' => $fx['chat']->id,
        'chat_session_id' => $fx['session']->id,
        'external_message_id' => uniqid('msg_'),
        'direction' => 'incoming',
        'message_mode' => 'external',
        'type' => 'text',
        'text' => $text,
        'status' => 'delivered',
        'sent_at' => now(),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// OpenAI integration
// ---------------------------------------------------------------------------

it('stores the openai api key encrypted at rest and hides it in json', function () {
    $fx = aiAgentFixtures();

    $raw = DB::table('openai_integrations')->where('id', $fx['openAi']->id)->value('api_key');

    expect($raw)->not->toBe('sk-test-key');
    expect($fx['openAi']->api_key)->toBe('sk-test-key');
    expect($fx['openAi']->toArray())->not->toHaveKey('api_key');
});

it('reports openai availability for the company', function () {
    $fx = aiAgentFixtures();

    $response = $this->actingAs($fx['user'])->getJson('/api/integrations/available');

    $response->assertOk()->assertJsonPath('openai_available', true);
});

// ---------------------------------------------------------------------------
// Agent CRUD
// ---------------------------------------------------------------------------

it('rejects a response delay below 10 seconds', function () {
    $fx = aiAgentFixtures();

    $response = $this->actingAs($fx['user'])->postJson('/api/ai-agents', [
        'name' => 'Fast Bot',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'temperature' => 0.7,
        'max_tokens' => 512,
        'response_delay_seconds' => 5,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('response_delay_seconds');
});

it('rejects unknown tool keys', function () {
    $fx = aiAgentFixtures();

    $response = $this->actingAs($fx['user'])->postJson('/api/ai-agents', [
        'name' => 'Bot',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'temperature' => 0.7,
        'max_tokens' => 512,
        'response_delay_seconds' => 15,
        'enabled_tools' => ['drop_database'],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('enabled_tools.0');
});

it('hides agents from other companies', function () {
    $fx = aiAgentFixtures();

    $otherCompany = Company::create(['name' => 'Other Co', 'email' => 'other-co@example.com']);
    $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($otherUser)->getJson('/api/ai-agents');

    $response->assertOk();
    expect($response->json())->toBeEmpty();
});

it('rejects binding a channel account already claimed by another agent', function () {
    $fx = aiAgentFixtures();

    $second = AiAgent::create([
        'company_id' => $fx['company']->id,
        'name' => 'Second Bot',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'response_delay_seconds' => 15,
    ]);

    $response = $this->actingAs($fx['user'])->putJson("/api/ai-agents/{$second->id}/channels", [
        'bindings' => [
            ['channelable_type' => 'whatsapp_instance', 'channelable_id' => $fx['instance']->id],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Sales Bot');
});

// ---------------------------------------------------------------------------
// Dispatcher / debounce
// ---------------------------------------------------------------------------

it('queues a delayed AI turn for an inbound message on an AI-owned chat', function () {
    Queue::fake();
    $fx = aiAgentFixtures();

    $message = inboundMessage($fx, 'Hello');

    AiAgentDispatcher::onInboundMessage($fx['chat'], $message, $fx['session']);

    Queue::assertPushed(ProcessAiAgentTurn::class, function (ProcessAiAgentTurn $job) use ($fx, $message) {
        return $job->chatId === $fx['chat']->id
            && $job->agentId === $fx['agent']->id
            && $job->triggerMessageId === $message->id
            && (int) $job->delay->diffInSeconds(now()->addSeconds(15), true) <= 1;
    });
});

it('does not queue a turn when ai_agent_service is off', function () {
    Queue::fake();
    $fx = aiAgentFixtures();

    $fx['chat']->update(['ai_agent_service' => false]);
    $message = inboundMessage($fx, 'Hello');

    AiAgentDispatcher::onInboundMessage($fx['chat']->fresh(), $message, $fx['session']);

    Queue::assertNothingPushed();
});

it('does not queue a turn when the agent is disabled', function () {
    Queue::fake();
    $fx = aiAgentFixtures();

    $fx['agent']->update(['status' => AiAgent::STATUS_DISABLED]);
    $message = inboundMessage($fx, 'Hello');

    AiAgentDispatcher::onInboundMessage($fx['chat'], $message, $fx['session']);

    Queue::assertNothingPushed();
});

it('lets a stale job exit without calling the provider when a newer inbound exists', function () {
    Http::fake();
    $fx = aiAgentFixtures();

    $first = inboundMessage($fx, 'Hello');
    inboundMessage($fx, 'One more thing');

    (new ProcessAiAgentTurn($fx['chat']->id, $fx['agent']->id, $first->id))->handle();

    Http::assertNothingSent();
    expect(InboxMessage::where('direction', 'outgoing')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Full turn (mocked provider)
// ---------------------------------------------------------------------------

it('runs a full turn and sends the AI reply through the channel', function () {
    $fx = aiAgentFixtures();

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['content' => 'Hi John! How can I help you today?'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]),
        'evolution.example.com/*' => Http::response([
            'key' => ['id' => 'WA_MSG_1'],
        ]),
    ]);

    $message = inboundMessage($fx, 'Hello, I need help');

    (new ProcessAiAgentTurn($fx['chat']->id, $fx['agent']->id, $message->id))->handle();

    $reply = InboxMessage::where('inbox_chat_id', $fx['chat']->id)
        ->where('direction', 'outgoing')
        ->first();

    expect($reply)->not->toBeNull();
    expect($reply->text)->toBe('Hi John! How can I help you today?');
    expect($reply->user_id)->toBeNull();
    expect($reply->ai_agent_id)->toBe($fx['agent']->id);
    expect($reply->source)->toBe('ai_agent');
    expect($reply->message_mode)->toBe('external');

    $execution = $fx['agent']->executions()->first();
    expect($execution)->not->toBeNull();
    expect($execution->status)->toBe('completed');
    expect($execution->last_inbound_message_id)->toBe($message->id);
    expect($fx['agent']->fresh()->execution_count)->toBe(1);
});

it('executes a tool call then sends the final reply', function () {
    $fx = aiAgentFixtures();

    // A real product so the tool result must SUCCEED with data — this
    // guards against schema drift (e.g. selecting nonexistent columns),
    // which a call-was-made assertion alone would mask.
    $product = \App\Models\Product::create([
        'company_id' => $fx['company']->id,
        'name' => 'BMW 320i',
        'description' => 'Sedan 2.0 turbo',
        'sku' => 'BMW320',
        'base_price' => 250000,
        'type' => 'product',
        'is_active' => true,
    ]);

    \App\Models\CustomProductField::create([
        'company_id' => $fx['company']->id,
        'product_id' => $product->id,
        'type' => 'product',
        'field_type' => 'text',
        'field_key' => 'Motor',
        'field_value' => '2.0 Turbo 184cv',
        'display_order' => 1,
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_products',
                                'arguments' => '{"query":"BMW"}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 15],
            ])
            ->push([
                'choices' => [[
                    'message' => ['content' => 'We do not have laptops in our catalog right now.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 18],
            ]),
        'evolution.example.com/*' => Http::response(['key' => ['id' => 'WA_MSG_2']]),
    ]);

    $message = inboundMessage($fx, 'Do you sell BMWs?');

    (new ProcessAiAgentTurn($fx['chat']->id, $fx['agent']->id, $message->id))->handle();

    $execution = $fx['agent']->executions()->first();
    expect($execution->status)->toBe('completed');
    expect($execution->tool_calls)->toHaveCount(1);
    expect($execution->tool_calls[0]['tool'])->toBe('search_products');
    expect($execution->tool_calls[0]['success'])->toBeTrue();

    $returned = $execution->tool_calls[0]['payload']['products'];
    expect($returned)->toHaveCount(1);
    expect($returned[0]['name'])->toBe('BMW 320i');
    expect($returned[0]['price'])->not->toBeNull();
    expect($returned[0]['specifications']['Motor'])->toBe('2.0 Turbo 184cv');

    $reply = InboxMessage::where('direction', 'outgoing')->first();
    expect($reply->text)->not->toBeNull();
});

it('hands over to a human when the model calls handover_to_human', function () {
    $fx = aiAgentFixtures();

    $fx['agent']->update(['handover_message' => 'A specialist will continue, {lead_name}!']);
    $fx['lead']->update(['assigned_to' => $fx['user']->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_h',
                        'type' => 'function',
                        'function' => [
                            'name' => 'handover_to_human',
                            'arguments' => '{"reason":"customer asked for a person"}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 10],
        ]),
        'evolution.example.com/*' => Http::response(['key' => ['id' => 'WA_MSG_3']]),
    ]);

    $message = inboundMessage($fx, 'I want to talk to a person');

    (new ProcessAiAgentTurn($fx['chat']->id, $fx['agent']->id, $message->id))->handle();

    expect($fx['chat']->fresh()->ai_agent_service)->toBeFalse();
    expect($fx['lead']->fresh()->assigned_to)->toBe($fx['user']->id);

    $handoverMessage = InboxMessage::where('direction', 'outgoing')->first();
    expect($handoverMessage->text)->toBe('A specialist will continue, John Doe!');
    expect($handoverMessage->ai_agent_id)->toBe($fx['agent']->id);

    $execution = $fx['agent']->executions()->first();
    expect($execution->status)->toBe('handover');
});

it('hands over automatically when the AI turn fails internally', function () {
    $fx = aiAgentFixtures();

    $fx['agent']->update(['handover_message' => 'A specialist will continue shortly.']);
    $fx['lead']->update(['assigned_to' => $fx['user']->id]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(['error' => ['message' => 'server exploded']], 500),
        'evolution.example.com/*' => Http::response(['key' => ['id' => 'WA_MSG_FAIL']]),
    ]);

    $message = inboundMessage($fx, 'Hello?');

    (new ProcessAiAgentTurn($fx['chat']->id, $fx['agent']->id, $message->id))->handle();

    // Internal failure => transfer without asking the customer anything.
    expect($fx['chat']->fresh()->ai_agent_service)->toBeFalse();

    $handoverMessage = InboxMessage::where('direction', 'outgoing')->first();
    expect($handoverMessage)->not->toBeNull();
    expect($handoverMessage->text)->toBe('A specialist will continue shortly.');

    $execution = $fx['agent']->executions()->first();
    expect($execution->status)->toBe('failed');
    expect($execution->error)->toContain('server exploded');
});

// ---------------------------------------------------------------------------
// Preview sandbox
// ---------------------------------------------------------------------------

it('runs a preview chat with real product reads and simulated mutations', function () {
    $fx = aiAgentFixtures();

    \App\Models\Product::create([
        'company_id' => $fx['company']->id,
        'name' => 'BMW X5',
        'sku' => 'BMWX5',
        'base_price' => 300000,
        'type' => 'product',
        'is_active' => true,
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_search',
                                'type' => 'function',
                                'function' => ['name' => 'search_products', 'arguments' => '{"query":"BMW"}'],
                            ],
                            [
                                'id' => 'call_stage',
                                'type' => 'function',
                                'function' => ['name' => 'change_lead_stage', 'arguments' => '{"stage_id":1}'],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => ['content' => 'We have the BMW X5 available!'],
                    'finish_reason' => 'stop',
                ]],
            ]),
    ]);

    $response = $this->actingAs($fx['user'])->postJson('/api/ai-agents/preview-chat', [
        'config' => [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 512,
            'system_prompt' => 'You are a car dealership assistant.',
            'enabled_tools' => ['search_products', 'change_lead_stage'],
        ],
        'messages' => [
            ['role' => 'user', 'content' => 'Do you have any BMW?'],
        ],
    ]);

    $response->assertOk();
    expect($response->json('reply'))->toBe('We have the BMW X5 available!');
    expect($response->json('handover'))->toBeFalse();

    $calls = collect($response->json('tool_calls'));
    expect($calls->firstWhere('tool', 'search_products')['simulated'])->toBeFalse();
    expect($calls->firstWhere('tool', 'change_lead_stage')['simulated'])->toBeTrue();

    // Simulated mutation must not touch data: the fixture lead keeps its stage.
    expect($fx['lead']->fresh()->stage_id)->toBe($fx['stage']->id);
});

it('reports a simulated handover in preview chat', function () {
    $fx = aiAgentFixtures();

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_h',
                        'type' => 'function',
                        'function' => ['name' => 'handover_to_human', 'arguments' => '{"reason":"wants a person"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ]),
    ]);

    $response = $this->actingAs($fx['user'])->postJson('/api/ai-agents/preview-chat', [
        'config' => [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 512,
            'handover_message' => 'A specialist will continue!',
        ],
        'messages' => [
            ['role' => 'user', 'content' => 'I want to talk to a human'],
        ],
    ]);

    $response->assertOk();
    expect($response->json('handover'))->toBeTrue();
    expect($response->json('handover_message'))->toBe('A specialist will continue!');
    // Preview never flips real chat flags.
    expect($fx['chat']->fresh()->ai_agent_service)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Human override + manual toggle
// ---------------------------------------------------------------------------

it('disables the AI when a human sends an external message', function () {
    $fx = aiAgentFixtures();

    AiAgentDispatcher::onHumanExternalSend($fx['chat']);

    expect($fx['chat']->fresh()->ai_agent_service)->toBeFalse();
});

it('toggles ai_agent_service through the inbox endpoint', function () {
    $fx = aiAgentFixtures();

    $response = $this->actingAs($fx['user'])
        ->putJson("/api/inbox/chats/{$fx['chat']->id}/ai-agent-service", ['enabled' => false]);

    $response->assertOk()->assertJsonPath('chat.ai_agent_service', false);
    expect($fx['chat']->fresh()->ai_agent_service)->toBeFalse();
});

it('queues a resume turn when re-enabling with unanswered messages', function () {
    Queue::fake();
    $fx = aiAgentFixtures();

    $fx['chat']->update(['ai_agent_service' => false]);
    $message = inboundMessage($fx, 'Anyone there?');

    $this->actingAs($fx['user'])
        ->putJson("/api/inbox/chats/{$fx['chat']->id}/ai-agent-service", ['enabled' => true])
        ->assertOk();

    Queue::assertPushed(ProcessAiAgentTurn::class, fn (ProcessAiAgentTurn $job) => $job->triggerMessageId === $message->id);
});

it('rejects toggling a chat from another company', function () {
    $fx = aiAgentFixtures();

    $otherCompany = Company::create(['name' => 'Other Co 2', 'email' => 'other-co-2@example.com']);
    $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);

    $this->actingAs($otherUser)
        ->putJson("/api/inbox/chats/{$fx['chat']->id}/ai-agent-service", ['enabled' => false])
        ->assertNotFound();
});
