<?php

namespace App\Services\Ai\Tools;

use App\Models\Observation;
use App\Models\Record;

class AddLeadNoteTool implements ToolInterface
{
    public const KEY = 'add_lead_note';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Add Lead Note';
    }

    public function description(): string
    {
        return 'Attach an internal note (observation) to the current lead, e.g. a summary of what the customer asked for. Notes are visible to the sales team only.';
    }

    public function parametersSchema(ToolContext $context): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'note' => [
                    'type' => 'string',
                    'description' => 'The note content.',
                ],
            ],
            'required' => ['note'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->lead !== null;
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $note = trim((string) ($arguments['note'] ?? ''));

        if ($note === '') {
            return ToolResult::error('Note cannot be empty.');
        }

        $observation = Observation::create([
            'lead_id' => $context->lead->id,
            'user_id' => null,
            'note' => $note,
        ]);

        Record::createObservationCreated($observation, $context->lead, null);

        return ToolResult::ok(['observation_id' => $observation->id]);
    }
}
