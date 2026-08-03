<?php

namespace App\Services\Optus;

use App\Models\OptusConversation;
use App\Models\OptusMessage;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Dto\AiChatMessage;
use App\Services\Ai\Dto\AiChatRequest;
use App\Services\Optus\Tools\OptusToolContext;
use App\Services\Optus\Tools\OptusToolRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Runs one Optus turn: persist the question, ask OpenAI, persist the answer.
 *
 * Sits on the same provider abstraction as the AI Agents feature, so swapping
 * or adding a model provider is a factory change rather than a rewrite here.
 */
class OptusAssistantService
{
    /**
     * How many past messages to replay. Optus answers product questions, where
     * relevance drops off fast; a window keeps the prompt cheap and bounded
     * while still carrying "and for deals?" style follow-ups.
     */
    private const HISTORY_LIMIT = 20;

    /**
     * Guard against a model that keeps calling tools. Unreachable today (the
     * registry is empty) but the loop below is what future tools run in.
     */
    private const MAX_TOOL_ITERATIONS = 5;

    /**
     * @throws OptusUnavailableException when no platform key is configured
     * @throws AiProviderException       when OpenAI itself fails
     */
    public function ask(User $user, OptusConversation $conversation, string $question): OptusMessage
    {
        $provider = AiProviderFactory::platformOpenAi();

        if (!$provider) {
            throw new OptusUnavailableException(
                'The assistant is not configured on this installation.'
            );
        }

        // The question is stored before the call, so a provider failure leaves
        // the user's own message in the thread rather than losing what they
        // typed.
        $conversation->messages()->create([
            'role' => OptusMessage::ROLE_USER,
            'content' => $question,
        ]);
        $conversation->forceFill(['last_message_at' => now()])->save();

        $context = new OptusToolContext($user, $conversation, (int) $user->company_id);
        $tools = OptusToolRegistry::available($context);

        $request = new AiChatRequest(
            model: (string) config('services.openai.optus_model'),
            systemPrompt: OptusPrompt::build($user),
            messages: $this->history($conversation),
            tools: OptusToolRegistry::definitions($tools, $context),
            // Low: this assistant is judged on accuracy, not variety. Creative
            // phrasing here reads as invented product behaviour.
            temperature: 0.3,
            maxTokens: 1200,
        );

        $response = $provider->chat($request);

        // Tool loop. A no-op while the registry is empty — the model cannot
        // call what it was never given.
        $iterations = 0;

        while ($response->hasToolCalls() && $iterations < self::MAX_TOOL_ITERATIONS) {
            $iterations++;

            $request->push(AiChatMessage::assistantToolCalls(
                $response->rawToolCalls,
                $response->text ?? ''
            ));

            foreach ($response->toolCalls as $call) {
                $result = OptusToolRegistry::execute($call->name, $context, $call->arguments);
                $request->push(AiChatMessage::toolResult($call->id, $result->toLlmContent()));
            }

            $response = $provider->chat($request);
        }

        $text = trim((string) $response->text);

        if ($text === '') {
            Log::warning('Optus returned an empty completion', [
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'finish_reason' => $response->finishReason,
            ]);

            throw new AiProviderException('The assistant returned an empty response.');
        }

        $answer = $conversation->messages()->create([
            'role' => OptusMessage::ROLE_ASSISTANT,
            'content' => $text,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'meta' => [
                'model' => $request->model,
                'prompt_version' => OptusPrompt::VERSION,
                'knowledge_version' => OptusKnowledge::VERSION,
            ],
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        return $answer;
    }

    /**
     * The replayed window, oldest first.
     *
     * @return AiChatMessage[]
     */
    private function history(OptusConversation $conversation): array
    {
        return $conversation->messages()
            // reorder(), not latest(): the relation already orders by id, and
            // a second orderBy is appended rather than replacing it — the
            // ascending sort wins, limit keeps the *oldest* messages, and
            // reverse() then hands the model the conversation backwards.
            ->reorder('id', 'desc')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (OptusMessage $message) => $message->role === OptusMessage::ROLE_ASSISTANT
                ? AiChatMessage::assistant($message->content)
                : AiChatMessage::user($message->content))
            ->values()
            ->all();
    }
}
