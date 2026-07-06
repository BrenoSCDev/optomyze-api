<?php

namespace App\Services\Ai\Tools;

class ToolResult
{
    public const SIGNAL_HANDOVER = 'handover';

    public function __construct(
        public readonly bool $success,
        public readonly array $payload = [],
        public readonly ?string $signal = null,
    ) {
    }

    public static function ok(array $payload = [], ?string $signal = null): self
    {
        return new self(true, $payload, $signal);
    }

    public static function error(string $message): self
    {
        return new self(false, ['error' => $message]);
    }

    /**
     * JSON string fed back to the LLM as the tool-call result.
     */
    public function toLlmContent(): string
    {
        return json_encode(array_merge(['success' => $this->success], $this->payload));
    }
}
