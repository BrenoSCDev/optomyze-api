<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Dto\AiChatRequest;
use App\Services\Ai\Dto\AiChatResponse;
use App\Services\Ai\Dto\AiToolCall;
use App\Services\Ai\LlmProviderRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Claude, over the Messages API.
 *
 * Three things differ from the OpenAI driver and drive most of this class:
 *  - the system prompt is a top-level field, not a message;
 *  - tools are flat ({name, description, input_schema}), not wrapped in a
 *    "function" envelope;
 *  - `max_tokens` is required, and on thinking models it is a budget shared
 *    between the model's reasoning and its reply.
 */
class AnthropicProvider implements AiProviderInterface
{
    private const PROVIDER = 'claude';

    /**
     * Claude Opus 4.7 and later, and Sonnet 5, removed the sampling
     * parameters: sending `temperature` to one of these is a 400, not a
     * no-op. Agents keep their temperature setting for other providers; it is
     * simply not transmitted here.
     */
    private const REJECTS_TEMPERATURE = [
        'claude-opus-5',
        'claude-opus-4-8',
        'claude-sonnet-5',
        'claude-fable-5',
    ];

    /**
     * Models that accept `output_config.effort`. Haiku 4.5 does not, and
     * sending it there is also a 400.
     */
    private const SUPPORTS_EFFORT = [
        'claude-opus-5',
        'claude-sonnet-5',
        'claude-opus-4-8',
        'claude-sonnet-4-6',
        'claude-fable-5',
    ];

    /**
     * Models that think by default. An inbox agent wants the reply, not the
     * deliberation, so these run at low effort — and because thinking is
     * billed out of `max_tokens`, they also need a floor under it or a
     * long deliberation truncates the customer-facing answer.
     */
    private const THINKS_BY_DEFAULT = [
        'claude-opus-5',
        'claude-sonnet-5',
        'claude-fable-5',
    ];

    private const THINKING_TOKEN_FLOOR = 4096;

    public function __construct(private readonly string $apiKey)
    {
    }

    public function chat(AiChatRequest $request): AiChatResponse
    {
        $messages = array_map(
            fn ($message) => $message->toAnthropic(),
            $request->messages
        );

        $body = [
            'model' => $request->model,
            'system' => $request->systemPrompt,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens($request),
        ];

        if (!in_array($request->model, self::REJECTS_TEMPERATURE, true)) {
            // Anthropic's range is 0..1; the agent form allows up to 2 for
            // OpenAI's sake, so clamp rather than let the request 400.
            $body['temperature'] = min(1.0, max(0.0, $request->temperature));
        }

        if (in_array($request->model, self::THINKS_BY_DEFAULT, true)
            && in_array($request->model, self::SUPPORTS_EFFORT, true)) {
            $body['output_config'] = ['effort' => 'low'];
        }

        if (!empty($request->tools)) {
            $body['tools'] = $this->toolDefinitions($request->tools);
        }

        $response = $this->http()
            ->timeout(90)
            ->post($this->baseUrl() . '/messages', $body);

        if (!$response->successful()) {
            throw new AiProviderException(
                'Anthropic chat completion failed: '
                . ($response->json('error.message') ?? $response->body())
            );
        }

        return $this->parse($response->json() ?? []);
    }

    /**
     * Anthropic has no speech-to-text endpoint. Rather than blinding a Claude
     * agent to every voice note, fall back to the platform's own OpenAI key —
     * the same path Optus uses. Null when that key is unset, which the caller
     * already degrades gracefully.
     */
    public function transcribe(string $absolutePath, string $mime): ?string
    {
        $fallback = AiProviderFactory::platformOpenAi();

        if (!$fallback) {
            Log::warning('AnthropicProvider: cannot transcribe audio', [
                'reason' => 'Anthropic has no audio endpoint and OPENAI_API_KEY is unset',
                'file' => basename($absolutePath),
            ]);

            return null;
        }

        return $fallback->transcribe($absolutePath, $mime);
    }

    /**
     * Thinking models spend part of max_tokens on reasoning before writing a
     * word of the reply, so the agent's configured ceiling becomes a floor.
     */
    private function maxTokens(AiChatRequest $request): int
    {
        return in_array($request->model, self::THINKS_BY_DEFAULT, true)
            ? max($request->maxTokens, self::THINKING_TOKEN_FLOOR)
            : $request->maxTokens;
    }

    /**
     * OpenAI-shaped tool definitions -> Anthropic's flat shape.
     */
    private function toolDefinitions(array $tools): array
    {
        return array_values(array_map(function (array $tool) {
            $function = $tool['function'] ?? [];
            $schema = $function['parameters'] ?? [];

            // An empty properties map has to serialise as {} rather than [],
            // which is what PHP would emit for an empty array.
            if (empty($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            }

            return [
                'name' => $function['name'] ?? '',
                'description' => $function['description'] ?? '',
                'input_schema' => $schema,
            ];
        }, $tools));
    }

    private function parse(array $payload): AiChatResponse
    {
        $blocks = $payload['content'] ?? [];

        $text = '';
        $toolCalls = [];
        $hasToolUse = false;

        foreach ($blocks as $block) {
            switch ($block['type'] ?? null) {
                case 'text':
                    $text .= $block['text'] ?? '';
                    break;

                case 'tool_use':
                    $hasToolUse = true;
                    $input = $block['input'] ?? [];

                    $toolCalls[] = new AiToolCall(
                        $block['id'] ?? '',
                        $block['name'] ?? '',
                        is_array($input) ? $input : [],
                    );
                    break;
            }
        }

        return new AiChatResponse(
            $text !== '' ? $text : null,
            $toolCalls,
            /*
             * The whole block list, not just the tool_use blocks: continuing a
             * tool-calling turn means replaying the assistant message exactly
             * as it came back, and on thinking models that list includes
             * thinking blocks the API rejects if reordered or edited.
             */
            $hasToolUse ? $blocks : [],
            $payload['stop_reason'] ?? null,
            $payload['usage']['input_tokens'] ?? null,
            $payload['usage']['output_tokens'] ?? null,
        );
    }

    private function http()
    {
        return Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => LlmProviderRegistry::config(self::PROVIDER, 'api_version', '2023-06-01'),
            'content-type' => 'application/json',
        ]);
    }

    private function baseUrl(): string
    {
        return rtrim((string) LlmProviderRegistry::config(self::PROVIDER, 'api_base'), '/');
    }
}
