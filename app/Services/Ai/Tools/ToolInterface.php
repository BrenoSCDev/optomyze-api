<?php

namespace App\Services\Ai\Tools;

interface ToolInterface
{
    /**
     * Stable machine key, e.g. 'update_lead_info'. Stored in
     * ai_agents.enabled_tools and used as the LLM function name.
     */
    public function key(): string;

    /**
     * Human-readable label for the agent form UI.
     */
    public function label(): string;

    /**
     * Description shown to the LLM (and as UI helper text).
     */
    public function description(): string;

    /**
     * JSON Schema for the tool arguments. Context-aware so schemas can
     * enumerate live values (funnel stages, tag names, ...).
     */
    public function parametersSchema(ToolContext $context): array;

    /**
     * Whether the tool can run in this conversation context.
     */
    public function authorize(ToolContext $context): bool;

    public function execute(ToolContext $context, array $arguments): ToolResult;
}
