<?php

namespace App\Services\Ai;

use App\Events\InboxChatUpdated;
use App\Jobs\ProcessAiAgentTurn;
use App\Models\AiAgent;
use App\Models\ChatSession;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\OpenAiIntegration;
use Illuminate\Support\Facades\Log;

/**
 * Entry points connecting the inbox message flow to the AI agent pipeline.
 * Every method is failure-isolated: a broken AI path must never break
 * webhook ingestion or message sending.
 */
class AiAgentDispatcher
{
    /**
     * Called after an inbound message is persisted and broadcast. When the
     * chat is AI-owned and its channel account has an enabled agent, queues
     * a debounced response turn.
     */
    public static function onInboundMessage(InboxChat $chat, InboxMessage $message, ?ChatSession $session): void
    {
        try {
            if ($message->direction !== 'incoming' || $message->message_mode !== 'external') {
                return;
            }

            if (!$chat->lead_id || !$chat->ai_agent_service || $chat->is_archived) {
                return;
            }

            if (!$session || $session->status !== ChatSession::STATUS_ACTIVE) {
                return;
            }

            $agent = $chat->boundAiAgent();

            if (!$agent || !$agent->isEnabled()) {
                return;
            }

            if (!self::providerUsable($agent)) {
                return;
            }

            $delay = max(AiAgent::MIN_RESPONSE_DELAY_SECONDS, $agent->response_delay_seconds);

            ProcessAiAgentTurn::dispatch($chat->id, $agent->id, $message->id)
                ->delay(now()->addSeconds($delay));
        } catch (\Throwable $e) {
            Log::warning('AiAgentDispatcher: failed to queue AI turn', [
                'chat_id' => $chat->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Called when a CRM user sends a customer-visible message: the human
     * takes over and the AI must stop responding immediately. Internal
     * notes do not trigger this — a teammate leaving context must not
     * silently disable the agent.
     */
    public static function onHumanExternalSend(InboxChat $chat): void
    {
        try {
            if (!$chat->ai_agent_service) {
                return;
            }

            $chat->update(['ai_agent_service' => false]);

            broadcast(new InboxChatUpdated($chat))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('AiAgentDispatcher: failed to disable AI on human send', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function providerUsable(AiAgent $agent): bool
    {
        if ($agent->provider !== AiAgent::PROVIDER_OPENAI) {
            return false;
        }

        $integration = OpenAiIntegration::fromCompany($agent->company_id)->active()->first();

        return $integration !== null && $integration->isUsable();
    }
}
