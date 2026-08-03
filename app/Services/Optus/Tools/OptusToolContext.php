<?php

namespace App\Services\Optus\Tools;

use App\Models\OptusConversation;
use App\Models\User;

/**
 * Everything an Optus tool is allowed to know about who is asking.
 *
 * Distinct from the AI Agent ToolContext: that one is built around a customer
 * conversation (chat, lead, agent config), while Optus acts for an internal
 * user. Sharing one context would force each to carry the other's nulls.
 *
 * Tools must scope every lookup to $companyId and must respect $user's role —
 * the model is allowed to ask for anything, so the boundary lives here, not in
 * the prompt.
 */
class OptusToolContext
{
    public function __construct(
        public readonly User $user,
        public readonly OptusConversation $conversation,
        public readonly int $companyId,
    ) {
    }
}
