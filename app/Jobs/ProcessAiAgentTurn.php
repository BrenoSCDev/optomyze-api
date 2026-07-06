<?php

namespace App\Jobs;

use App\Models\AiAgent;
use App\Models\AiAgentExecution;
use App\Models\ChatSession;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Services\Ai\AiAgentDispatcher;
use App\Services\Ai\AiAgentTurnService;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Dto\AiChatMessage;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolResult;
use App\Services\ChannelMessageSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Debounced AI response turn. Every inbound message dispatches its own
 * delayed instance carrying that message's id; "resetting the timer" is
 * achieved by later instances invalidating earlier ones (step 2). An
 * atomic cache lock prevents concurrent replies, and the execution
 * watermark makes the job idempotent.
 */
class ProcessAiAgentTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Never auto-retry a partially-sent LLM turn. */
    public $tries = 1;

    public $timeout = 120;

    private const MAX_TOOL_ITERATIONS = 5;
    private const LOCK_SECONDS = 120;
    private const LOCK_RETRY_DELAY_SECONDS = 5;

    public function __construct(
        public int $chatId,
        public int $agentId,
        public int $triggerMessageId,
    ) {
    }

    public function handle(): void
    {
        // 1. Reload state; bail silently when the turn is no longer wanted.
        $chat = InboxChat::with(['company:id,name,timezone', 'lead.stage:id,name', 'lead.assignedUser:id,name', 'channelable'])
            ->find($this->chatId);

        if (!$chat || !$chat->ai_agent_service || $chat->is_archived || !$chat->lead_id) {
            return;
        }

        $agent = AiAgent::find($this->agentId);

        if (!$agent || !$agent->isEnabled() || $agent->company_id !== $chat->company_id) {
            return;
        }

        $boundAgent = $chat->boundAiAgent();

        if (!$boundAgent || $boundAgent->id !== $agent->id) {
            return; // binding changed since dispatch
        }

        $session = $chat->activeChatSession;

        if (!$session || $session->status !== ChatSession::STATUS_ACTIVE) {
            return;
        }

        $provider = AiProviderFactory::forAgent($agent);

        if (!$provider) {
            return;
        }

        // 2. Debounce core: a newer inbound message means a newer job owns
        //    this burst — let it do the reply with fuller context.
        if ($this->latestInboundId($chat->id) > $this->triggerMessageId) {
            return;
        }

        // 3. One reply generation per chat at a time.
        $lock = Cache::lock("ai-turn:{$chat->id}", self::LOCK_SECONDS);

        if (!$lock->get()) {
            self::dispatch($this->chatId, $this->agentId, $this->triggerMessageId)
                ->delay(now()->addSeconds(self::LOCK_RETRY_DELAY_SECONDS));

            return;
        }

        try {
            $this->runTurn($chat, $agent, $session, $provider);
        } finally {
            $lock->release();
        }
    }

    private function runTurn(InboxChat $chat, AiAgent $agent, ChatSession $session, $provider): void
    {
        $startedAt = microtime(true);

        // 4. Pending window: everything the customer sent since the last
        //    answered message.
        $pending = $this->pendingMessages($chat);

        if ($pending->isEmpty()) {
            return; // already answered (duplicate job slipped through)
        }

        $context = new ToolContext($agent, $chat, $session, $chat->lead, $chat->company_id);

        $execution = [
            'company_id' => $chat->company_id,
            'ai_agent_id' => $agent->id,
            'inbox_chat_id' => $chat->id,
            'chat_session_id' => $session->id,
            'last_inbound_message_id' => $pending->max('id'),
        ];

        try {
            // 5. Tool-call loop.
            $turnService = new AiAgentTurnService();
            $request = $turnService->buildRequest($agent, $chat, $pending, $context, $provider);

            $toolCallLog = [];
            $promptTokens = 0;
            $completionTokens = 0;
            $handover = false;
            $responseText = null;

            for ($iteration = 0; $iteration <= self::MAX_TOOL_ITERATIONS; $iteration++) {
                $response = $provider->chat($request);

                $promptTokens += (int) $response->promptTokens;
                $completionTokens += (int) $response->completionTokens;

                if (!$response->hasToolCalls()) {
                    $responseText = trim((string) $response->text);
                    break;
                }

                $request->push(AiChatMessage::assistantToolCalls($response->rawToolCalls, (string) $response->text));

                foreach ($response->toolCalls as $toolCall) {
                    $result = ToolRegistry::execute($toolCall->name, $context, $toolCall->arguments);

                    $toolCallLog[] = [
                        'tool' => $toolCall->name,
                        'arguments' => $toolCall->arguments,
                        'success' => $result->success,
                        'payload' => $result->payload,
                    ];

                    $request->push(AiChatMessage::toolResult($toolCall->id, $result->toLlmContent()));

                    if ($result->signal === ToolResult::SIGNAL_HANDOVER) {
                        $handover = true;
                    }
                }

                // The handover tool already sent the final message and
                // disabled the AI — no further reply belongs to this turn.
                if ($handover) {
                    break;
                }

                // Loop exhausted: force a final text answer without tools.
                if ($iteration === self::MAX_TOOL_ITERATIONS - 1) {
                    $request = new \App\Services\Ai\Dto\AiChatRequest(
                        $request->model,
                        $request->systemPrompt,
                        $request->messages,
                        [],
                        $request->temperature,
                        $request->maxTokens,
                    );
                }
            }

            // 6. Send the reply (skipped after a handover).
            if (!$handover && $responseText !== null && $responseText !== '') {
                // Bail on late arrivals: if the customer sent something new
                // while the LLM was thinking, drop this reply and let the
                // newer message's job answer with full context.
                if ($this->latestInboundId($chat->id) > $pending->max('id')) {
                    AiAgentExecution::create($execution + [
                        'status' => AiAgentExecution::STATUS_SKIPPED,
                        'tool_calls' => $toolCallLog ?: null,
                        'error' => 'Superseded by a newer inbound message.',
                    ]);

                    return;
                }

                ChannelMessageSender::send($chat, $session, $responseText, [
                    'ai_agent_id' => $agent->id,
                ]);
            }

            // 7. Record the execution.
            AiAgentExecution::create($execution + [
                'status' => $handover ? AiAgentExecution::STATUS_HANDOVER : AiAgentExecution::STATUS_COMPLETED,
                'tool_calls' => $toolCallLog ?: null,
                'response_text' => $responseText,
                'prompt_tokens' => $promptTokens ?: null,
                'completion_tokens' => $completionTokens ?: null,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            $agent->recordExecution();

            // 8. Late-arrival re-check: a message that arrived mid-turn may
            //    have had its own job bail on the lock — requeue coverage.
            $chat->refresh();

            $latestInboundId = $this->latestInboundId($chat->id);

            if ($chat->ai_agent_service && !$handover && $latestInboundId > $pending->max('id')) {
                self::dispatch($chat->id, $agent->id, $latestInboundId)
                    ->delay(now()->addSeconds(max(AiAgent::MIN_RESPONSE_DELAY_SECONDS, $agent->response_delay_seconds)));
            }
        } catch (\Throwable $e) {
            // 9. Internal failure (LLM API down, unexpected exception): the
            //    customer must not be stranded with a silent bot — hand the
            //    conversation to a human immediately per the CRM routing
            //    rules (assigned user, else round-robin queue).
            Log::error('ProcessAiAgentTurn failed — handing over to human', [
                'chat_id' => $chat->id,
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            AiAgentExecution::create($execution + [
                'status' => AiAgentExecution::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            try {
                if ($chat->fresh()->ai_agent_service) {
                    \App\Services\Ai\AiHandoverService::handover($context, 'internal error: ' . mb_substr($e->getMessage(), 0, 200));
                }
            } catch (\Throwable $handoverError) {
                Log::error('ProcessAiAgentTurn: failure handover also failed', [
                    'chat_id' => $chat->id,
                    'error' => $handoverError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Watermark = the last message the AI (or anyone) answered up to:
     * the max of the last execution's consumed-inbound id and the last
     * customer-visible outgoing message. Pending = inbound after it.
     */
    private function pendingMessages(InboxChat $chat)
    {
        $lastConsumedId = (int) AiAgentExecution::where('inbox_chat_id', $chat->id)
            ->whereIn('status', [AiAgentExecution::STATUS_COMPLETED, AiAgentExecution::STATUS_HANDOVER])
            ->max('last_inbound_message_id');

        $lastOutgoingId = (int) InboxMessage::where('inbox_chat_id', $chat->id)
            ->where('direction', 'outgoing')
            ->where('message_mode', 'external')
            ->max('id');

        $watermark = max($lastConsumedId, $lastOutgoingId);

        return InboxMessage::where('inbox_chat_id', $chat->id)
            ->where('direction', 'incoming')
            ->where('message_mode', 'external')
            ->when($watermark > 0, fn ($q) => $q->where('id', '>', $watermark))
            ->orderBy('id')
            ->get();
    }

    private function latestInboundId(int $chatId): int
    {
        return (int) InboxMessage::where('inbox_chat_id', $chatId)
            ->where('direction', 'incoming')
            ->where('message_mode', 'external')
            ->max('id');
    }
}
