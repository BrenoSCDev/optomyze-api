<?php

namespace App\Services\Optus\Tools;

use App\Services\Ai\Tools\ToolResult;
use Illuminate\Support\Facades\Log;

/**
 * Plugin registry for Optus tools, mirroring the AI Agent ToolRegistry.
 *
 * Empty on purpose. The current release is informational only, and an empty
 * registry means the service sends no `tools` to OpenAI, so the model has no
 * mechanism to act even if it were prompted to.
 *
 * To ship the first action: implement OptusToolInterface, add the class to
 * $tools, and — for anything that writes — build the confirmation step that
 * mutatesData() currently gates on. Nothing else needs to change.
 */
class OptusToolRegistry
{
    /** @var array<class-string<OptusToolInterface>> */
    protected static array $tools = [];

    /** @var array<string, OptusToolInterface>|null */
    protected static ?array $instances = null;

    public static function register(string $toolClass): void
    {
        if (!in_array($toolClass, self::$tools, true)) {
            self::$tools[] = $toolClass;
            self::$instances = null;
        }
    }

    /** @return array<string, OptusToolInterface> */
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
     * The tools this user may actually use right now.
     *
     * Write tools are filtered out unconditionally: until the UI can ask the
     * user to confirm an action, "Optus must not modify CRM data" has to be
     * enforced in code rather than trusted to the prompt.
     *
     * @return array<string, OptusToolInterface>
     */
    public static function available(OptusToolContext $context): array
    {
        return array_filter(
            self::instances(),
            fn (OptusToolInterface $tool) => !$tool->mutatesData() && $tool->authorize($context),
        );
    }

    /**
     * OpenAI function-calling definitions. Empty array = no tools sent.
     *
     * @param array<string, OptusToolInterface> $tools
     */
    public static function definitions(array $tools, OptusToolContext $context): array
    {
        return array_values(array_map(fn (OptusToolInterface $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->key(),
                'description' => $tool->description(),
                'parameters' => $tool->parametersSchema($context),
            ],
        ], $tools));
    }

    /**
     * Run a tool, turning any failure into a result the model can react to
     * rather than an exception that kills the turn.
     */
    public static function execute(string $key, OptusToolContext $context, array $arguments): ToolResult
    {
        $tool = self::available($context)[$key] ?? null;

        if (!$tool) {
            return ToolResult::error("Unknown or unavailable tool: {$key}");
        }

        try {
            return $tool->execute($context, $arguments);
        } catch (\Throwable $e) {
            Log::warning('Optus tool execution failed', [
                'tool' => $key,
                'user_id' => $context->user->id,
                'error' => $e->getMessage(),
            ]);

            return ToolResult::error('The tool failed to execute: ' . $e->getMessage());
        }
    }
}
