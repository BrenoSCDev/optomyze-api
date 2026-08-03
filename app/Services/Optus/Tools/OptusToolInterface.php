<?php

namespace App\Services\Optus\Tools;

use App\Services\Ai\Tools\ToolResult;

/**
 * A capability Optus can invoke on the user's behalf.
 *
 * Nothing implements this yet, by design: the current release is informational
 * only. The interface exists so that turning on the first action — creating a
 * lead, scheduling an appointment — is a new class plus a line in
 * OptusToolRegistry, with no change to the service, controller or UI.
 *
 * Reuses ToolResult from the agent stack: the shape of "it worked / it didn't"
 * is the same, and duplicating it would mean two things to keep in step.
 */
interface OptusToolInterface
{
    /** Stable machine key, used as the LLM function name. */
    public function key(): string;

    public function description(): string;

    /** JSON Schema for the arguments. */
    public function parametersSchema(OptusToolContext $context): array;

    /**
     * Whether this user, in this company, may run this tool. Role checks
     * belong here — never in the prompt.
     */
    public function authorize(OptusToolContext $context): bool;

    /**
     * Whether running it changes CRM data. Write tools are expected to be
     * confirmed by the user in the UI before they execute; the registry
     * refuses to expose them while confirmation is unimplemented.
     */
    public function mutatesData(): bool;

    public function execute(OptusToolContext $context, array $arguments): ToolResult;
}
