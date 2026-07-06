<?php

namespace App\Services\Ai\Dto;

class AiChatRequest
{
    /**
     * @param AiChatMessage[] $messages conversation turns (system prompt excluded)
     * @param array $tools OpenAI function-calling tool definitions
     */
    public function __construct(
        public readonly string $model,
        public readonly string $systemPrompt,
        public array $messages,
        public readonly array $tools = [],
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 1024,
    ) {
    }

    public function push(AiChatMessage $message): void
    {
        $this->messages[] = $message;
    }
}
