<?php

namespace App\Services\Ai\Tools;

use App\Models\AiAgent;
use App\Models\ChatSession;
use App\Models\InboxChat;
use App\Models\Lead;

/**
 * Everything a tool needs to act safely: all lookups inside tools must be
 * scoped to $companyId — never trust LLM-supplied ids across companies.
 */
class ToolContext
{
    public function __construct(
        public readonly AiAgent $agent,
        public readonly InboxChat $chat,
        public readonly ?ChatSession $session,
        public readonly ?Lead $lead,
        public readonly int $companyId,
    ) {
    }
}
