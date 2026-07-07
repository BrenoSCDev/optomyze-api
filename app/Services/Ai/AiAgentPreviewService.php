<?php

namespace App\Services\Ai;

use App\Models\AiAgent;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\InboxChat;
use App\Models\Lead;
use App\Services\Ai\Dto\AiChatMessage;
use App\Services\Ai\Dto\AiChatRequest;
use App\Services\Ai\Tools\SearchProductsTool;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolResult;

/**
 * Sandbox chat for the agent form: runs the agent's CURRENT (possibly
 * unsaved) configuration through the real provider and tool loop so users
 * can test behavior before deploying to a channel. Nothing is persisted
 * and nothing reaches a customer:
 *  - read-only tools (search_products) execute for real against CRM data;
 *  - mutating tools (stage, tags, notes, assignment, handover) are
 *    simulated — they return success to the model without touching data.
 */
class AiAgentPreviewService
{
    private const MAX_TOOL_ITERATIONS = 5;

    /**
     * @param array $config agent config from the form (validated)
     * @param array $messages [{role: user|assistant, content: string}, ...]
     * @return array{reply: ?string, tool_calls: array, handover: bool, handover_message: ?string}
     */
    public function chat(int $companyId, array $config, array $messages): array
    {
        $agent = new AiAgent([
            'name' => $config['name'] ?? 'Preview Agent',
            'provider' => $config['provider'] ?? AiAgent::PROVIDER_OPENAI,
            'model' => $config['model'],
            'temperature' => $config['temperature'],
            'max_tokens' => $config['max_tokens'],
            'system_prompt' => $config['system_prompt'] ?? null,
            'enabled_tools' => $config['enabled_tools'] ?? [],
            'handover_message' => $config['handover_message'] ?? null,
        ]);
        $agent->company_id = $companyId;

        $provider = AiProviderFactory::forAgent($agent);

        if (!$provider) {
            throw new AiProviderException('The OpenAI integration is not connected.');
        }

        $context = $this->previewContext($agent, $companyId);

        $tools = ToolRegistry::forAgent($agent, $context);

        $chatMessages = [];

        foreach ($messages as $message) {
            $chatMessages[] = ($message['role'] === 'assistant')
                ? AiChatMessage::assistant($message['content'])
                : AiChatMessage::user($message['content']);
        }

        $request = new AiChatRequest(
            $agent->model,
            (new AiAgentTurnService())->systemPrompt($agent, $context->chat, $context),
            $chatMessages,
            ToolRegistry::definitions($tools, $context),
            $agent->temperature,
            $agent->max_tokens,
        );

        $toolCallLog = [];
        $handover = false;
        $reply = null;

        for ($iteration = 0; $iteration <= self::MAX_TOOL_ITERATIONS; $iteration++) {
            $response = $provider->chat($request);

            if (!$response->hasToolCalls()) {
                $reply = trim((string) $response->text);
                break;
            }

            $request->push(AiChatMessage::assistantToolCalls($response->rawToolCalls, (string) $response->text));

            foreach ($response->toolCalls as $toolCall) {
                $result = $this->executePreviewTool($toolCall->name, $context, $toolCall->arguments);

                $toolCallLog[] = [
                    'tool' => $toolCall->name,
                    'arguments' => $toolCall->arguments,
                    'success' => $result->success,
                    'simulated' => (bool) ($result->payload['simulated'] ?? false),
                ];

                $request->push(AiChatMessage::toolResult($toolCall->id, $result->toLlmContent()));

                if ($result->signal === ToolResult::SIGNAL_HANDOVER) {
                    $handover = true;
                }
            }

            if ($handover) {
                break;
            }

            if ($iteration === self::MAX_TOOL_ITERATIONS - 1) {
                $request = new AiChatRequest(
                    $request->model,
                    $request->systemPrompt,
                    $request->messages,
                    [],
                    $request->temperature,
                    $request->maxTokens,
                );
            }
        }

        return [
            'reply' => $handover ? null : $reply,
            'tool_calls' => $toolCallLog,
            'handover' => $handover,
            'handover_message' => $handover
                ? ($agent->handover_message ?: 'Thank you! I\'ve forwarded your request to one of our specialists. They\'ll continue assisting you shortly.')
                : null,
        ];
    }

    /**
     * A realistic but ephemeral context: fake lead/chat (never persisted)
     * carrying real company data so tool schemas enumerate the actual
     * funnel stages, tags and users the deployed agent would see.
     */
    private function previewContext(AiAgent $agent, int $companyId): ToolContext
    {
        $company = Company::find($companyId);
        $funnel = Funnel::fromCompany($companyId)->where('is_active', true)->first();
        $entryStage = $funnel?->stages()->where('type', 'entry')->orderBy('order')->first();

        $lead = new Lead([
            'first_name' => 'Preview',
            'last_name' => 'Customer',
            'phone' => '5500000000000',
            'funnel_id' => $funnel?->id,
            'stage_id' => $entryStage?->id,
        ]);
        $lead->company_id = $companyId;

        if ($funnel) {
            $lead->setRelation('funnel', $funnel);
        }
        if ($entryStage) {
            $lead->setRelation('stage', $entryStage);
        }
        $lead->setRelation('assignedUser', null);

        $chat = new InboxChat([
            'channel' => InboxChat::CHANNEL_WHATSAPP,
            'contact_name' => 'Preview Customer',
        ]);
        $chat->company_id = $companyId;
        $chat->setRelation('company', $company);
        $chat->setRelation('lead', $lead);

        return new ToolContext($agent, $chat, null, $lead, $companyId);
    }

    /**
     * Read-only tools run for real; everything that would mutate CRM data
     * returns a simulated success so the model behaves as in production.
     */
    private function executePreviewTool(string $key, ToolContext $context, array $arguments): ToolResult
    {
        if ($key === SearchProductsTool::KEY) {
            return ToolRegistry::execute($key, $context, $arguments);
        }

        if ($key === 'handover_to_human') {
            return ToolResult::ok([
                'handed_over' => true,
                'simulated' => true,
                'reason' => $arguments['reason'] ?? null,
            ], ToolResult::SIGNAL_HANDOVER);
        }

        return ToolResult::ok([
            'simulated' => true,
            'note' => 'Action simulated in preview mode — no CRM data was changed.',
            'arguments' => $arguments,
        ]);
    }
}
