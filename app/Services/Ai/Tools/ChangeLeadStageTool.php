<?php

namespace App\Services\Ai\Tools;

use App\Models\Stage;

class ChangeLeadStageTool implements ToolInterface
{
    public const KEY = 'change_lead_stage';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Change Lead Stage';
    }

    public function description(): string
    {
        return 'Move the current lead to another stage of its sales funnel (e.g. from "Awaiting Service" to "Qualified").';
    }

    public function parametersSchema(ToolContext $context): array
    {
        $stages = $this->funnelStages($context);

        return [
            'type' => 'object',
            'properties' => [
                'stage_id' => [
                    'type' => 'integer',
                    'description' => 'Target stage. Available stages: '
                        . $stages->map(fn ($s) => "{$s->id} = \"{$s->name}\"")->implode(', '),
                    'enum' => $stages->pluck('id')->all(),
                ],
            ],
            'required' => ['stage_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->lead !== null && $context->lead->funnel_id !== null;
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $stageId = (int) ($arguments['stage_id'] ?? 0);

        $stage = Stage::where('id', $stageId)
            ->where('funnel_id', $context->lead->funnel_id)
            ->first();

        if (!$stage) {
            return ToolResult::error('Stage not found in the lead\'s funnel.');
        }

        if ($stage->id === $context->lead->stage_id) {
            return ToolResult::ok(['message' => 'Lead is already in this stage.']);
        }

        // moveToStage validates the funnel again and writes the Record
        // timeline entry (null user = automated actor).
        $moved = $context->lead->moveToStage($stage->id);

        if (!$moved) {
            return ToolResult::error('Could not move the lead to that stage.');
        }

        return ToolResult::ok([
            'stage_id' => $stage->id,
            'stage_name' => $stage->name,
        ]);
    }

    private function funnelStages(ToolContext $context)
    {
        return Stage::where('funnel_id', $context->lead?->funnel_id)
            ->orderBy('order')
            ->get(['id', 'name']);
    }
}
