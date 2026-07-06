<?php

namespace App\Services\Ai;

use App\Events\InboxChatUpdated;
use App\Models\User;
use App\Services\ChannelMessageSender;
use App\Services\LeadQueueAssignmentService;
use App\Services\MessageTemplateRenderer;
use App\Services\NotificationService;
use App\Services\Ai\Tools\ToolContext;

class AiHandoverService
{
    private const DEFAULT_HANDOVER_MESSAGE =
        'Thank you! I\'ve forwarded your request to one of our specialists. They\'ll continue assisting you shortly.';

    /**
     * Transfer the conversation from the AI to a human: keep the existing
     * responsible user when there is one, otherwise pull the next
     * salesperson from the funnel's round-robin queue. Flips the chat's
     * ai_agent_service flag off and sends the agent's final handover
     * message.
     *
     * @return array payload describing the outcome (fed back to the LLM)
     */
    public static function handover(ToolContext $context, ?string $reason = null): array
    {
        $lead = $context->lead;
        $chat = $context->chat;

        // LeadQueueAssignmentService::assign() always rotates the queue, so
        // the "keep the current assignee" rule must be enforced here.
        if ($lead && !$lead->assigned_to) {
            LeadQueueAssignmentService::assign($lead);
            $lead->refresh();
        }

        $assignee = $lead?->assigned_to ? User::find($lead->assigned_to) : null;

        $chat->update(['ai_agent_service' => false]);
        broadcast(new InboxChatUpdated($chat))->toOthers();

        // Final customer-facing message announcing the human takeover.
        $template = $context->agent->handover_message ?: self::DEFAULT_HANDOVER_MESSAGE;
        $messageText = $lead
            ? MessageTemplateRenderer::render($template, $lead, $assignee)
            : $template;

        if ($context->session) {
            $sent = ChannelMessageSender::send(
                $chat,
                $context->session,
                $messageText,
                ['ai_agent_id' => $context->agent->id]
            );
        }

        if ($lead && $assignee) {
            app(NotificationService::class)->dispatch('lead_assigned', $lead->company_id, [
                'lead_owner_id' => $assignee->id,
                'actor_name'    => $context->agent->name . ' (AI Agent)',
                'lead'          => [
                    'id'   => $lead->id,
                    'name' => trim("{$lead->first_name} {$lead->last_name}"),
                ],
            ]);
        }

        return [
            'handed_over' => true,
            'assigned_to' => $assignee?->name,
            'reason' => $reason,
        ];
    }
}
