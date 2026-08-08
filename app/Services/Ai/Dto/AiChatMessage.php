<?php

namespace App\Services\Ai\Dto;

/**
 * One turn in the LLM conversation. Content is either a plain string or an
 * array of OpenAI-style content parts ({type: text|image_url, ...}) for
 * multimodal user turns.
 *
 * OpenAI's shape is the internal canonical form; the to*() methods translate
 * it to each vendor's wire format. `toolCalls` always holds the *native*
 * payload of whichever provider produced it, so it round-trips unchanged.
 *
 * OpenAI keeps tool calls in a field beside the content. Anthropic and Gemini
 * instead put them in the same block/part list as the assistant's text, so for
 * those two `toolCalls` holds that whole list — echoed back verbatim, because
 * it can also carry blocks we must not reorder or rewrite (Anthropic thinking
 * blocks). Hence: when tool calls are present, their transcriptions emit only
 * that list and ignore `content`.
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

    /**
     * Anthropic Messages API. Everything is content blocks, and tool results
     * ride on a *user* turn rather than a role of their own. Consecutive
     * same-role messages are merged by the API, so one turn per result is fine.
     */
    public function toAnthropic(): array
    {
        if ($this->role === 'tool') {
            return [
                'role' => 'user',
                'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => (string) $this->toolCallId,
                    'content' => $this->contentAsText(),
                ]],
            ];
        }

        // Already the assistant turn's native block list — replay it as-is.
        $blocks = $this->toolCalls ?: [];

        if (empty($blocks)) {
            foreach ($this->normalizedParts() as $part) {
                $blocks[] = $part['type'] === 'image'
                    // Anthropic fetches image URLs itself, so the signed media
                    // link goes across as-is — no download on our side.
                    ? ['type' => 'image', 'source' => ['type' => 'url', 'url' => $part['url']]]
                    : ['type' => 'text', 'text' => $part['text']];
            }
        }

        if (empty($blocks)) {
            // The API rejects an empty content array.
            $blocks[] = ['type' => 'text', 'text' => '(no content)'];
        }

        return [
            'role' => $this->role === 'assistant' ? 'assistant' : 'user',
            'content' => $blocks,
        ];
    }

    /**
     * Gemini generateContent. Assistant turns are role "model", and tool
     * results are matched back to their call by function *name* — Gemini
     * issues no call ids, so AiToolCall::$id carries the name for this driver.
     *
     * @param callable|null $inlineImage fn(string $url): ?array — resolves a
     *        URL to an inline_data part. Gemini cannot fetch arbitrary URLs,
     *        so the download has to happen on our side; the provider passes it
     *        in rather than the DTO doing I/O.
     */
    public function toGemini(?callable $inlineImage = null): array
    {
        if ($this->role === 'tool') {
            return [
                'role' => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name' => (string) $this->toolCallId,
                        'response' => ['result' => $this->contentAsText()],
                    ],
                ]],
            ];
        }

        // Already the model turn's native part list — replay it as-is.
        $parts = $this->toolCalls ?: [];

        if (empty($parts)) {
            foreach ($this->normalizedParts() as $part) {
                if ($part['type'] === 'text') {
                    $parts[] = ['text' => $part['text']];
                    continue;
                }

                $inline = $inlineImage ? $inlineImage($part['url']) : null;

                $parts[] = $inline ?: ['text' => '[image received]'];
            }
        }

        if (empty($parts)) {
            $parts[] = ['text' => '(no content)'];
        }

        return [
            'role' => $this->role === 'assistant' ? 'model' : 'user',
            'parts' => $parts,
        ];
    }

    /**
     * Content flattened to {type: text|image} parts, dropping empty text.
     *
     * @return array<int, array{type: string, text?: string, url?: string}>
     */
    private function normalizedParts(): array
    {
        if (is_string($this->content)) {
            return trim($this->content) === ''
                ? []
                : [['type' => 'text', 'text' => $this->content]];
        }

        $parts = [];

        foreach ($this->content as $part) {
            if (($part['type'] ?? null) === 'image_url') {
                $url = $part['image_url']['url'] ?? null;

                if ($url) {
                    $parts[] = ['type' => 'image', 'url' => $url];
                }

                continue;
            }

            $text = trim((string) ($part['text'] ?? ''));

            if ($text !== '') {
                $parts[] = ['type' => 'text', 'text' => $text];
            }
        }

        return $parts;
    }

    private function contentAsText(): string
    {
        return is_string($this->content)
            ? $this->content
            : (string) json_encode($this->content);
    }
}
