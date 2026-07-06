<?php

namespace App\Services\Ai\Dto;

/**
 * One turn in the LLM conversation. Content is either a plain string or an
 * array of OpenAI-style content parts ({type: text|image_url, ...}) for
 * multimodal user turns.
 */
class AiChatMessage
{
    public function __construct(
        public readonly string $role,          // system | user | assistant | tool
        public readonly string|array $content,
        public readonly ?string $toolCallId = null,  // set on role=tool results
        public readonly ?array $toolCalls = null,    // set on assistant turns that called tools
    ) {
    }

    public static function user(string|array $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    public static function assistantToolCalls(array $toolCalls, string $content = ''): self
    {
        return new self('assistant', $content, null, $toolCalls);
    }

    public static function toolResult(string $toolCallId, string $content): self
    {
        return new self('tool', $content, $toolCallId);
    }

    public function toOpenAi(): array
    {
        $message = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->toolCallId !== null) {
            $message['tool_call_id'] = $this->toolCallId;
        }

        if ($this->toolCalls !== null) {
            $message['tool_calls'] = $this->toolCalls;
        }

        return $message;
    }
}
