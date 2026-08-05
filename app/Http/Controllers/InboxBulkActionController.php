<?php

namespace App\Http\Controllers;

use App\Events\InboxChatUpdated;
use App\Events\InboxMessagesCleared;
use App\Models\ChatSession;
use App\Models\ClosingReason;
use App\Models\ConversationSetting;
use App\Models\InboxChat;
use App\Services\ChannelMessageSender;
use App\Services\ChatSessionService;
use App\Services\MessageTemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bulk actions over selected conversations.
 *
 * Deliberately separate from InboxController, which is already very large and
 * is about one conversation at a time. These share the per-chat behaviour —
 * same columns, same broadcasts — but differ in the part that matters: what
 * happens when one item in a batch cannot be done. None of these fail the
 * whole request over a single unusable chat; each reports what it touched and
 * what it passed over, because "37 of 40" is the honest answer and a blanket
 * error would leave the user with no idea which is which.
 */
class InboxBulkActionController extends Controller
{
    /**
     * Most conversations one request may act on.
     *
     * Each chat costs a broadcast, and ending conversations can cost an
     * outbound message per chat, so an unbounded selection is a way to hurt
     * both the queue and the customer. The list UI selects a page at a time,
     * well inside this.
     */
    private const MAX_CHATS = 200;

    /** Archive or restore every selected conversation. */
    public function archive(Request $request)
    {
        return $this->setFlag($request, 'is_archived', 'archived');
    }

    /** Pin or unpin every selected conversation. */
    public function favorite(Request $request)
    {
        return $this->setFlag($request, 'is_favorite', 'favorite');
    }

    /**
     * Delete the messages of every selected conversation.
     *
     * The conversations themselves stay — this empties them, it does not
     * remove them from the inbox.
     */
    public function clearMessages(Request $request)
    {
        [$chats, $error] = $this->resolveChats($request);

        if ($error) {
            return $error;
        }

        $cleared = 0;

        foreach ($chats as $chat) {
            // Per chat rather than one delete over all of them: a single
            // failure should cost that conversation, not the whole batch, and
            // each listener is told about its own chat.
            try {
                DB::transaction(fn () => $chat->messages()->delete());

                broadcast(new InboxMessagesCleared($chat))->toOthers();
                $cleared++;
            } catch (\Throwable $e) {
                Log::error('Bulk clear messages failed for a conversation', [
                    'chat_id' => $chat->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'processed' => $cleared,
            'skipped'   => $chats->count() - $cleared,
        ]);
    }

    /**
     * Close the active session on every selected conversation.
     *
     * A chat with no active session is skipped, not an error: selecting forty
     * conversations and finding three already closed is ordinary, and there is
     * nothing for the user to fix.
     *
     * The closing message is opt-in and off by default. It is a real message to
     * a real customer on WhatsApp or Instagram, and sending dozens of them is
     * not something to do as a side effect of tidying the inbox. When asked
     * for, it is rendered per chat so each contact is addressed by their own
     * name rather than everyone receiving one contact's text.
     */
    public function endConversations(Request $request)
    {
        [$chats, $error] = $this->resolveChats($request, [
            'closing_reason_id'    => ['required', 'integer'],
            'notes'                => ['nullable', 'string'],
            'send_closing_message' => ['sometimes', 'boolean'],
        ]);

        if ($error) {
            return $error;
        }

        $user = Auth::user();

        $reason = ClosingReason::where('id', $request->integer('closing_reason_id'))
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->with('closingMessages')
            ->first();

        if (!$reason) {
            return response()->json(['message' => 'Closing reason not found.'], 422);
        }

        $sendMessage = $request->boolean('send_closing_message')
            && ConversationSetting::where('company_id', $user->company_id)->value('closing_messages_enabled');

        $template = $reason->closingMessages->first()?->message;

        $closed = 0;
        $messagesSent = 0;

        foreach ($chats as $chat) {
            $session = ChatSession::where('inbox_chat_id', $chat->id)
                ->where('status', ChatSession::STATUS_ACTIVE)
                ->first();

            if (!$session) {
                continue;
            }

            try {
                $session = ChatSessionService::close($session, $reason, $request->input('notes'), $user);
                $closed++;

                if ($sendMessage && $template && $chat->lead) {
                    // A send that fails must not undo the close — the session
                    // really is over either way.
                    try {
                        ChannelMessageSender::send(
                            $chat,
                            $session,
                            MessageTemplateRenderer::render($template, $chat->lead, $user)
                        );
                        $messagesSent++;
                    } catch (\Throwable $e) {
                        Log::warning('Bulk end: closing message failed to send', [
                            'chat_id' => $chat->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Bulk end conversation failed for a conversation', [
                    'chat_id' => $chat->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'processed'     => $closed,
            'skipped'       => $chats->count() - $closed,
            'messages_sent' => $messagesSent,
        ]);
    }

    /**
     * Shared body for the two boolean flags.
     *
     * The value is explicit here, unlike the per-chat endpoints which flip
     * whatever they find. Flipping each chat individually across a mixed
     * selection would archive half and unarchive the other half — the user
     * picked an action, not a toggle.
     */
    private function setFlag(Request $request, string $column, string $input)
    {
        [$chats, $error] = $this->resolveChats($request, [
            $input => ['required', 'boolean'],
        ]);

        if ($error) {
            return $error;
        }

        $next = $request->boolean($input);
        $ids = $chats->pluck('id');

        InboxChat::whereIn('id', $ids)->update([$column => $next]);

        // Reloaded so listeners receive the row as it now stands, including
        // whichever flag this call did not touch.
        foreach (InboxChat::whereIn('id', $ids)->get() as $chat) {
            broadcast(new InboxChatUpdated($chat));
        }

        return response()->json([
            'processed' => $ids->count(),
            'skipped'   => 0,
        ]);
    }

    /**
     * Validate the request and load the caller's own conversations.
     *
     * Ids are resolved against the caller's company rather than trusted, so a
     * foreign id in the list is quietly dropped instead of acted on. Returns
     * [chats, errorResponse]; exactly one of the two is meaningful.
     */
    private function resolveChats(Request $request, array $extraRules = []): array
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return [collect(), response()->json(['message' => 'Unauthorized'], 403)];
        }

        $request->validate(array_merge([
            'chat_ids'   => ['required', 'array', 'min:1', 'max:' . self::MAX_CHATS],
            'chat_ids.*' => ['integer'],
        ], $extraRules));

        $chats = InboxChat::whereIn('id', $request->input('chat_ids'))
            ->where('company_id', $user->company_id)
            ->get();

        if ($chats->isEmpty()) {
            return [collect(), response()->json(['message' => 'No conversations found.'], 404)];
        }

        return [$chats, null];
    }
}
