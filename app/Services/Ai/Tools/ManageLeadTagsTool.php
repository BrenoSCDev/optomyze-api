<?php

namespace App\Services\Ai\Tools;

use App\Models\Record;
use App\Models\Tag;

class ManageLeadTagsTool implements ToolInterface
{
    public const KEY = 'manage_lead_tags';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Add / Remove Lead Tags';
    }

    public function description(): string
    {
        return 'Add or remove tags on the current lead. Only existing company tags can be used.';
    }

    public function parametersSchema(ToolContext $context): array
    {
        $tagNames = Tag::fromCompany($context->companyId)->pluck('name')->all();

        $tagItems = [
            'type' => 'string',
            'description' => 'Existing tag name.',
        ];

        if (!empty($tagNames)) {
            $tagItems['enum'] = $tagNames;
        }

        return [
            'type' => 'object',
            'properties' => [
                'add' => [
                    'type' => 'array',
                    'items' => $tagItems,
                    'description' => 'Tag names to add to the lead.',
                ],
                'remove' => [
                    'type' => 'array',
                    'items' => $tagItems,
                    'description' => 'Tag names to remove from the lead.',
                ],
            ],
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
        $added = [];
        $removed = [];

        $currentTagIds = $lead->tags()->pluck('tags.id')->all();

        foreach ((array) ($arguments['add'] ?? []) as $name) {
            $tag = $this->findTag($context->companyId, (string) $name);

            if ($tag && !in_array($tag->id, $currentTagIds, true)) {
                $lead->tags()->attach($tag->id);
                Record::createLeadTagChange($lead, $tag, true, null);
                $added[] = $tag->name;
                $currentTagIds[] = $tag->id;
            }
        }

        foreach ((array) ($arguments['remove'] ?? []) as $name) {
            $tag = $this->findTag($context->companyId, (string) $name);

            if ($tag && in_array($tag->id, $currentTagIds, true)) {
                $lead->tags()->detach($tag->id);
                Record::createLeadTagChange($lead, $tag, false, null);
                $removed[] = $tag->name;
                $currentTagIds = array_diff($currentTagIds, [$tag->id]);
            }
        }

        if (empty($added) && empty($removed)) {
            return ToolResult::error('No matching tags found to add or remove.');
        }

        return ToolResult::ok([
            'added' => $added,
            'removed' => $removed,
        ]);
    }

    private function findTag(int $companyId, string $name): ?Tag
    {
        return Tag::fromCompany($companyId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->first();
    }
}
