<?php

namespace App\Services\Ai\Tools;

class UpdateLeadInfoTool implements ToolInterface
{
    public const KEY = 'update_lead_info';

    /**
     * Fields the LLM is allowed to write. Deliberately excludes ownership,
     * funnel/stage and qualification fields — those have dedicated tools.
     */
    private const ALLOWED_FIELDS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'address_line_1',
        'neighborhood',
        'city',
        'state',
        'postal_code',
        'country',
        'language',
        'notes',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Update Lead Information';
    }

    public function description(): string
    {
        return 'Update the current lead\'s contact and profile information (name, email, phone, address, notes). Only pass the fields the customer provided.';
    }

    public function parametersSchema(ToolContext $context): array
    {
        $properties = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            $properties[$field] = ['type' => 'string'];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->lead !== null;
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $updates = array_filter(
            array_intersect_key($arguments, array_flip(self::ALLOWED_FIELDS)),
            fn ($value) => is_string($value) && trim($value) !== ''
        );

        if (empty($updates)) {
            return ToolResult::error('No valid fields provided.');
        }

        $context->lead->update($updates);

        return ToolResult::ok([
            'updated_fields' => array_keys($updates),
        ]);
    }
}
