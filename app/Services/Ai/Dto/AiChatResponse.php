<?php

namespace App\Services\Ai\Dto;

class AiChatResponse
{
    /**
     * @param AiToolCall[] $toolCalls
     * @param array $rawToolCalls provider-native tool_calls payload, echoed
     *                            back on the assistant turn in the tool loop
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls = [],
        public readonly array $rawToolCalls = [],
        public readonly ?string $finishReason = null,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
    ) {
    }

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }
}
