<?php

namespace App\Services\Ai\Tools;

use App\Models\AiAgent;
use Illuminate\Support\Facades\Log;

/**
 * Plugin-style registry of the native CRM tools an AI Agent can call.
 * New tools: implement ToolInterface and add the class here (or call
 * register() from a service provider).
 */
class ToolRegistry
{
    /** @var array<class-string<ToolInterface>> */
    protected static array $tools = [
        UpdateLeadInfoTool::class,
        ChangeLeadStageTool::class,
        AddLeadNoteTool::class,
        ManageLeadTagsTool::class,
        AssignConversationTool::class,
        SearchProductsTool::class,
        HandoverToHumanTool::class,
    ];

    /** @var array<string, ToolInterface>|null */
    protected static ?array $instances = null;

    public static function register(string $toolClass): void
    {
        if (!in_array($toolClass, self::$tools, true)) {
            self::$tools[] = $toolClass;
            self::$instances = null;
        }
    }

    /**
     * @return array<string, ToolInterface> keyed by tool key
     */
    protected static function instances(): array
    {
        if (self::$instances === null) {
            self::$instances = [];

            foreach (self::$tools as $class) {
                $tool = new $class();
                self::$instances[$tool->key()] = $tool;
            }
        }

        return self::$instances;
    }

    /**
     * All valid tool keys (for enabled_tools validation).
     */
    public static function keys(): array
    {
        return array_keys(self::instances());
    }

    /**
     * Catalog for the agent form UI.
     */
    public static function catalog(): array
    {
        return array_values(array_map(fn (ToolInterface $tool) => [
            'key' => $tool->key(),
            'label' => $tool->label(),
            'description' => $tool->description(),
            'always_enabled' => $tool->key() === HandoverToHumanTool::KEY,
        ], self::instances()));
    }

    /**
     * The tools available to an agent in a given context: the agent's
     * enabled tools, filtered by authorize(). Handover is always included
     * so the AI can escalate even with no tools configured.
     *
     * @return array<string, ToolInterface>
     */
    public static function forAgent(AiAgent $agent, ToolContext $context): array
    {
        $enabledKeys = array_unique(array_merge(
            $agent->enabled_tools ?? [],
            [HandoverToHumanTool::KEY],
        ));

        return array_filter(
            self::instances(),
            fn (ToolInterface $tool, string $key) => in_array($key, $enabledKeys, true)
                && $tool->authorize($context),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * OpenAI function-calling definitions for a set of tools.
     *
     * @param array<string, ToolInterface> $tools
     */
    public static function definitions(array $tools, ToolContext $context): array
    {
        return array_values(array_map(fn (ToolInterface $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->key(),
                'description' => $tool->description(),
                'parameters' => $tool->parametersSchema($context),
            ],
        ], $tools));
    }

    /**
     * Execute a tool by key, wrapping authorization and exceptions so a
     * failing tool degrades into an error result the LLM can react to.
     */
    public static function execute(string $key, ToolContext $context, array $arguments): ToolResult
    {
        $tool = self::instances()[$key] ?? null;

        if (!$tool) {
            return ToolResult::error("Unknown tool: {$key}");
        }

        if (!$tool->authorize($context)) {
            return ToolResult::error("Tool {$key} is not available in this conversation.");
        }

        try {
            return $tool->execute($context, $arguments);
        } catch (\Throwable $e) {
            Log::warning('AI tool execution failed', [
                'tool' => $key,
                'chat_id' => $context->chat->id,
                'error' => $e->getMessage(),
            ]);

            return ToolResult::error('The tool failed to execute: ' . $e->getMessage());
        }
    }
}
