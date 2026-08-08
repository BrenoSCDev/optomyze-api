<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Dto\AiChatRequest;
use App\Services\Ai\Dto\AiChatResponse;
use App\Services\Ai\Dto\AiToolCall;
use App\Services\Ai\LlmProviderRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini, over generateContent.
 *
 * The awkward parts, all handled below:
 *  - the model is in the URL, not the body;
 *  - tool results are matched back to their call by function *name*, because
 *    Gemini issues no call ids — so AiToolCall::$id carries the name here;
 *  - it will not fetch image URLs, so media has to be inlined as base64;
 *  - its function-call schema is a strict OpenAPI subset that 400s on JSON
 *    Schema keywords our tools legitimately emit (see sanitizeSchema).
 */
class GeminiProvider implements AiProviderInterface
{
    private const PROVIDER = 'gemini';

    /** Gemini reads audio natively, so voice notes need no separate service. */
    private const TRANSCRIPTION_MODEL = 'gemini-2.5-flash';

    /** Guard against inlining something absurd into a prompt. */
    private const MAX_INLINE_BYTES = 8 * 1024 * 1024;

    /** The only schema keys Gemini's function declarations accept. */
    private const ALLOWED_SCHEMA_KEYS = [
        'type', 'format', 'title', 'description', 'nullable',
        'enum', 'items', 'properties', 'required', 'anyOf',
        'minItems', 'maxItems',
    ];

    public function __construct(private readonly string $apiKey)
    {
    }

    public function chat(AiChatRequest $request): AiChatResponse
    {
        $contents = array_map(
            fn ($message) => $message->toGemini(fn (string $url) => $this->inlineImage($url)),
            $request->messages
        );

        $body = [
            'systemInstruction' => ['parts' => [['text' => $request->systemPrompt]]],
            'contents' => $contents,
            'generationConfig' => [
                // Gemini's range is 0..2, the same as OpenAI's, so the agent's
                // configured value goes across unchanged.
                'temperature' => $request->temperature,
                'maxOutputTokens' => $request->maxTokens,
            ],
        ];

        if (!empty($request->tools)) {
            $body['tools'] = [['functionDeclarations' => $this->toolDeclarations($request->tools)]];
        }

        $response = $this->http()
            ->timeout(90)
            ->post($this->endpoint($request->model, 'generateContent'), $body);

        if (!$response->successful()) {
            throw new AiProviderException(
                'Gemini chat completion failed: '
                . ($response->json('error.message') ?? $response->body())
            );
        }

        return $this->parse($response->json() ?? []);
    }

    public function transcribe(string $absolutePath, string $mime): ?string
    {
        try {
            $audio = @file_get_contents($absolutePath);

            if ($audio === false || strlen($audio) > self::MAX_INLINE_BYTES) {
                Log::warning('GeminiProvider: audio unreadable or too large to inline', [
                    'file' => basename($absolutePath),
                ]);

                return null;
            }

            $response = $this->http()
                ->timeout(60)
                ->post($this->endpoint(self::TRANSCRIPTION_MODEL, 'generateContent'), [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['inline_data' => ['mime_type' => $mime ?: 'audio/ogg', 'data' => base64_encode($audio)]],
                            ['text' => 'Transcribe this audio verbatim. Reply with the transcription only, no commentary.'],
                        ],
                    ]],
                ]);

            if (!$response->successful()) {
                Log::warning('GeminiProvider: transcription failed', [
                    'file' => basename($absolutePath),
                    'mime' => $mime,
                    'status' => $response->status(),
                    'error' => $response->json('error.message') ?? $response->body(),
                ]);

                return null;
            }

            $text = '';

            foreach ($response->json('candidates.0.content.parts') ?? [] as $part) {
                $text .= $part['text'] ?? '';
            }

            $text = trim($text);

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('GeminiProvider: transcription error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Download a signed media URL and inline it. Returns null on any failure
     * so the caller can substitute a text placeholder rather than lose the
     * whole turn to one broken image.
     */
    private function inlineImage(string $url): ?array
    {
        try {
            $response = Http::timeout(20)->get($url);

            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();

            if ($body === '' || strlen($body) > self::MAX_INLINE_BYTES) {
                return null;
            }

            return [
                'inline_data' => [
                    'mime_type' => $response->header('Content-Type') ?: 'image/jpeg',
                    'data' => base64_encode($body),
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('GeminiProvider: could not inline image', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * OpenAI-shaped tool definitions -> Gemini function declarations.
     */
    private function toolDeclarations(array $tools): array
    {
        return array_values(array_map(function (array $tool) {
            $function = $tool['function'] ?? [];

            return [
                'name' => $function['name'] ?? '',
                'description' => $function['description'] ?? '',
                'parameters' => $this->sanitizeSchema($function['parameters'] ?? []),
            ];
        }, $tools));
    }

    /**
     * Reduce a JSON Schema to the OpenAPI subset Gemini accepts.
     *
     * Two rejections our tools would otherwise hit: `additionalProperties`,
     * which every tool sets, and `enum` on a non-string type (the stage picker
     * enumerates integer ids). Dropping a numeric enum is safe here because
     * those tools already spell the valid values out in the description.
     */
    private function sanitizeSchema(array $schema): array
    {
        $clean = array_intersect_key($schema, array_flip(self::ALLOWED_SCHEMA_KEYS));

        if (isset($clean['type']) && is_string($clean['type'])) {
            $clean['type'] = strtoupper($clean['type']);
        }

        if (isset($clean['enum']) && ($clean['type'] ?? null) !== 'STRING') {
            unset($clean['enum']);
        }

        if (isset($clean['properties']) && is_array($clean['properties'])) {
            foreach ($clean['properties'] as $name => $property) {
                $clean['properties'][$name] = is_array($property)
                    ? $this->sanitizeSchema($property)
                    : $property;
            }

            if (empty($clean['properties'])) {
                // Must serialise as {}, not [].
                $clean['properties'] = new \stdClass();
            }
        }

        if (isset($clean['items']) && is_array($clean['items'])) {
            $clean['items'] = $this->sanitizeSchema($clean['items']);
        }

        return $clean;
    }

    private function parse(array $payload): AiChatResponse
    {
        $parts = $payload['candidates'][0]['content']['parts'] ?? [];

        $text = '';
        $toolCalls = [];
        $hasFunctionCall = false;

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $hasFunctionCall = true;
                $args = $part['functionCall']['args'] ?? [];
                $name = $part['functionCall']['name'] ?? '';

                $toolCalls[] = new AiToolCall(
                    // No call ids in this protocol — the name is the handle,
                    // and it is what the functionResponse must echo back.
                    $name,
                    $name,
                    is_array($args) ? $args : [],
                );

                continue;
            }

            $text .= $part['text'] ?? '';
        }

        return new AiChatResponse(
            $text !== '' ? $text : null,
            $toolCalls,
            // Replayed verbatim on the next turn, same as the Anthropic driver.
            $hasFunctionCall ? $parts : [],
            $payload['candidates'][0]['finishReason'] ?? null,
            $payload['usageMetadata']['promptTokenCount'] ?? null,
            $payload['usageMetadata']['candidatesTokenCount'] ?? null,
        );
    }

    private function endpoint(string $model, string $method): string
    {
        $base = rtrim((string) LlmProviderRegistry::config(self::PROVIDER, 'api_base'), '/');

        return "{$base}/models/{$model}:{$method}";
    }

    private function http()
    {
        // Gemini authenticates with a header rather than the older ?key= query
        // param, which keeps the key out of request logs and proxies.
        return Http::withHeaders([
            'x-goog-api-key' => $this->apiKey,
            'content-type' => 'application/json',
        ]);
    }
}
