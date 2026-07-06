<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\AiHandoverService;

class HandoverToHumanTool implements ToolInterface
{
    public const KEY = 'handover_to_human';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Handover to Human';
    }

    public function description(): string
    {
        return 'Transfer this conversation to a human agent and stop responding. Use when the customer asks for a person, wants to buy or negotiate, becomes frustrated, or the request is outside your instructions. After calling this you must not send any further messages.';
    }

    public function parametersSchema(ToolContext $context): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'description' => 'Short internal reason for the handover (not shown to the customer).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return true;
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $payload = AiHandoverService::handover(
            $context,
            isset($arguments['reason']) ? (string) $arguments['reason'] : null
        );

        return ToolResult::ok($payload, ToolResult::SIGNAL_HANDOVER);
    }
}
