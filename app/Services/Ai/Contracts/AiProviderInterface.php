<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Dto\AiChatRequest;
use App\Services\Ai\Dto\AiChatResponse;

interface AiProviderInterface
{
    /**
     * Run one chat completion, optionally with tool definitions.
     *
     * @throws \App\Services\Ai\AiProviderException on API failure
     */
    public function chat(AiChatRequest $request): AiChatResponse;

    /**
     * Transcribe an audio file from the local filesystem. Returns null when
     * transcription fails (never throws — a broken transcription should
     * degrade to a placeholder, not kill the turn).
     */
    public function transcribe(string $absolutePath, string $mime): ?string;
}
