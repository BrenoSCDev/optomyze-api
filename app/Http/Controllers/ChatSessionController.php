<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ClosingReason;
use App\Models\ConversationSetting;
use App\Models\InboxChat;
use App\Services\ChannelMessageSender;
use App\Services\ChatSessionService;
use App\Services\MessageTemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatSessionController extends Controller
{
    public function active(Request $request, $chatId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $chat = InboxChat::where('id', $chatId)
            ->where('company_id', $user->company_id)
            ->with('lead:id,assigned_to', 'lead.assignedUser:id,name')
            ->firstOrFail();

        $session = $chat->activeChatSession;

        if (!$session) {
            return response()->json(['data' => null], 200);
        }

        return response()->json([
            'data' => $this->formatSession($session, $chat),
        ], 200);
    }

    public function close(Request $request, $chatId, $sessionId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'closing_reason_id' => 'required|integer',
            'notes' => 'nullable|string',
            'closing_message' => 'nullable|string',
        ]);

        $chat = InboxChat::where('id', $chatId)
            ->where('company_id', $user->company_id)
            ->with('lead:id,assigned_to', 'lead.assignedUser:id,name')
            ->firstOrFail();

        $session = ChatSession::where('id', $sessionId)
            ->where('inbox_chat_id', $chat->id)
            ->where('status', ChatSession::STATUS_ACTIVE)
            ->firstOrFail();

        $reason = ClosingReason::where('id', $request->closing_reason_id)
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->firstOrFail();

        $session = ChatSessionService::close($session, $reason, $request->input('notes'), $user);

        $closingMessage = $request->input('closing_message');
        $settings = ConversationSetting::where('company_id', $user->company_id)->first();

        if ($closingMessage && $settings?->closing_messages_enabled) {
            ChannelMessageSender::send($chat, $session, $closingMessage);
        }

        return response()->json([
            'data' => $this->formatSession($session, $chat),
        ], 200);
    }

    public function closingMessagePreview(Request $request, $chatId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'closing_reason_id' => 'required|integer',
        ]);

        $chat = InboxChat::where('id', $chatId)
            ->where('company_id', $user->company_id)
            ->with('lead')
            ->firstOrFail();

        $reason = ClosingReason::where('id', $request->closing_reason_id)
            ->where('company_id', $user->company_id)
            ->with('closingMessages')
            ->firstOrFail();

        $settings = ConversationSetting::where('company_id', $user->company_id)->first();
        $closingMessage = $reason->closingMessages->first();

        if (!$settings?->closing_messages_enabled || !$closingMessage || !$chat->lead) {
            return response()->json(['data' => null], 200);
        }

        $text = MessageTemplateRenderer::render($closingMessage->message, $chat->lead, $user);

        return response()->json(['data' => $text], 200);
    }

    private function formatSession(ChatSession $session, InboxChat $chat): array
    {
        $assignedAgent = $chat->lead && $chat->lead->assignedUser
            ? ['id' => $chat->lead->assignedUser->id, 'name' => $chat->lead->assignedUser->name]
            : null;

        return [
            'id' => $session->id,
            'status' => $session->status,
            'started_at' => $session->started_at?->toIso8601String(),
            'first_message_at' => $session->first_message_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'duration_seconds' => $session->duration_seconds,
            'assigned_agent' => $assignedAgent,
        ];
    }
}
