<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Dto\AiChatRequest;
use App\Services\Ai\Dto\AiChatResponse;
use App\Services\Ai\Dto\AiToolCall;
use App\Services\Ai\Dto\OpenAiCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiProvider implements AiProviderInterface
{
    private const API_BASE = 'https://api.openai.com/v1';
    private const TRANSCRIPTION_MODEL = 'gpt-4o-mini-transcribe';

    public function __construct(private readonly OpenAiCredentials $credentials)
    {
    }

    public function chat(AiChatRequest $request): AiChatResponse
    {
        $messages = [
            ['role' => 'system', 'content' => $request->systemPrompt],
        ];

        foreach ($request->messages as $message) {
            $messages[] = $message->toOpenAi();
        }

        $body = [
            'model' => $request->model,
            'messages' => $messages,
            'temperature' => $request->temperature,
            'max_completion_tokens' => $request->maxTokens,
        ];

        if (!empty($request->tools)) {
            $body['tools'] = $request->tools;
        }

        $response = $this->http()
            ->timeout(90)
            ->post(self::API_BASE . '/chat/completions', $body);

        if (!$response->successful()) {
            throw new AiProviderException(
                'OpenAI chat completion failed: '
                . ($response->json('error.message') ?? $response->body())
            );
        }

        $choice = $response->json('choices.0') ?? [];
        $rawToolCalls = $choice['message']['tool_calls'] ?? [];

        $toolCalls = [];

        foreach ($rawToolCalls as $call) {
            $arguments = json_decode($call['function']['arguments'] ?? '{}', true);

            $toolCalls[] = new AiToolCall(
                $call['id'] ?? '',
                $call['function']['name'] ?? '',
                is_array($arguments) ? $arguments : [],
            );
        }

        return new AiChatResponse(
            $choice['message']['content'] ?? null,
            $toolCalls,
            $rawToolCalls,
            $choice['finish_reason'] ?? null,
            $response->json('usage.prompt_tokens'),
            $response->json('usage.completion_tokens'),
        );
    }

    public function transcribe(string $absolutePath, string $mime): ?string
    {
        try {
            $extension = pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'ogg';

            $response = $this->http()
                ->timeout(60)
                ->attach('file', file_get_contents($absolutePath), 'audio.' . $extension)
                ->post(self::API_BASE . '/audio/transcriptions', [
                    'model' => self::TRANSCRIPTION_MODEL,
                ]);

            if (!$response->successful()) {
                Log::warning('OpenAiProvider: transcription failed', [
                    'file' => basename($absolutePath),
                    'mime' => $mime,
                    'status' => $response->status(),
                    'error' => $response->json('error.message') ?? $response->body(),
                ]);

                return null;
            }

            $text = trim((string) $response->json('text'));

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('OpenAiProvider: transcription error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function http()
    {
        $request = Http::withToken($this->credentials->apiKey);

        if ($this->credentials->organizationId) {
            $request = $request->withHeaders([
                'OpenAI-Organization' => $this->credentials->organizationId,
            ]);
        }

        return $request;
    }
}
