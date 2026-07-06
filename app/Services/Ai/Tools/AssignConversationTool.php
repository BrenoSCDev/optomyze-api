<?php

namespace App\Services\Ai\Tools;

use App\Models\Record;
use App\Models\User;
use App\Services\NotificationService;

class AssignConversationTool implements ToolInterface
{
    public const KEY = 'assign_conversation';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Assign Conversation to User';
    }

    public function description(): string
    {
        return 'Assign (or reassign) the current lead to a specific CRM user by name. Use handover_to_human instead when the customer should be transferred to whoever is available.';
    }

    public function parametersSchema(ToolContext $context): array
    {
        $users = User::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->get(['id', 'name']);

        return [
            'type' => 'object',
            'properties' => [
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Target CRM user. Available users: '
                        . $users->map(fn ($u) => "{$u->id} = \"{$u->name}\"")->implode(', '),
                    'enum' => $users->pluck('id')->all(),
                ],
            ],
            'required' => ['user_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->lead !== null;
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $lead = $context->lead;
        $userId = (int) ($arguments['user_id'] ?? 0);

        $oldUser = $lead->assigned_to ? User::find($lead->assigned_to) : null;

        // assignTo validates the user belongs to the company and is active.
        if (!$lead->assignTo($userId)) {
            return ToolResult::error('User not found or inactive.');
        }

        $newUser = User::find($userId);

        Record::createLeadAssignment($lead, $oldUser, $newUser, null);

        app(NotificationService::class)->dispatch('lead_assigned', $lead->company_id, [
            'lead_owner_id' => $userId,
            'actor_name'    => $context->agent->name . ' (AI Agent)',
            'lead'          => [
                'id'   => $lead->id,
                'name' => trim("{$lead->first_name} {$lead->last_name}"),
            ],
        ]);

        return ToolResult::ok([
            'assigned_to' => $newUser->name,
        ]);
    }
}
