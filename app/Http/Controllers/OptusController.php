<?php

namespace App\Http\Controllers;

use App\Models\OptusConversation;
use App\Models\OptusMessage;
use App\Services\Ai\AiProviderException;
use App\Services\Optus\OptusAssistantService;
use App\Services\Optus\OptusUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * OPTUS — the in-app assistant.
 *
 * The OpenAI key is the platform's and never leaves the server; the browser
 * only ever talks to these endpoints.
 *
 * Every route resolves its conversation from the authenticated user rather
 * than from a client-supplied id, so one user cannot address another's thread
 * even by guessing. When conversation ids do become client-supplied (multiple
 * threads per user), they must be looked up through `ownedBy($user)`.
 */
class OptusController extends Controller
{
    /**
     * Per-user ceiling on turns. Each one spends real money against the
     * platform key, so this is a spend control as much as an abuse control.
     */
    private const MAX_TURNS_PER_MINUTE = 12;

    public function __construct(private readonly OptusAssistantService $assistant)
    {
    }

    /**
     * The user's current thread and its messages.
     */
    public function show(): JsonResponse
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        $conversation = OptusConversation::currentFor($user);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'messages' => $conversation->messages->map($this->present(...))->all(),
            ],
        ]);
    }

    /**
     * Ask a question and get the answer.
     */
    public function send(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $key = "optus:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_TURNS_PER_MINUTE)) {
            return response()->json([
                'message' => 'Too many messages. Please wait a moment.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $conversation = OptusConversation::currentFor($user);

        try {
            $answer = $this->assistant->ask($user, $conversation, $validated['message']);
        } catch (OptusUnavailableException $e) {
            // A deployment problem, not the user's. Say so plainly instead of
            // letting it surface as a generic failure they might retry.
            Log::error('Optus is not configured', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'The assistant is not available on this installation.',
                'reason' => 'not_configured',
            ], 503);
        } catch (AiProviderException $e) {
            Log::warning('Optus provider call failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The assistant could not respond. Please try again.',
                'reason' => 'provider_error',
            ], 502);
        }

        return response()->json(['data' => $this->present($answer)], 201);
    }

    /**
     * Clear the thread. Deletes rather than archives: this is the user's own
     * private history and "clear" should mean gone.
     */
    public function clear(): JsonResponse
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        OptusConversation::ownedBy($user)->delete();

        return response()->json(['message' => 'Conversation cleared']);
    }

    private function present(OptusMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
