<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\ClosingReason;
use App\Models\ConversationSetting;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\Record;
use App\Models\User;
use App\Models\WelcomeMessageConfig;
use Illuminate\Support\Facades\DB;

class ChatSessionService
{
    /**
     * Default closing reasons seeded for a company the first time it needs one.
     */
    private const DEFAULT_CLOSING_REASONS = [
        'Sale Completed',
        'Appointment Scheduled',
        'Lead Disqualified',
        'No Response',
        'Support Resolved',
        'Information Provided',
        'Other',
    ];

    /**
     * Return the chat's active session, creating one if none exists.
     * Locked to stay safe against concurrent webhook deliveries for the same chat.
     */
    public static function startOrGetActive(InboxChat $chat): ChatSession
    {
        return DB::transaction(function () use ($chat) {
            $session = ChatSession::where('inbox_chat_id', $chat->id)
                ->where('status', ChatSession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($session) {
                return $session;
            }

            $session = ChatSession::create([
                'company_id' => $chat->company_id,
                'inbox_chat_id' => $chat->id,
                'lead_id' => $chat->lead_id,
                'channel' => $chat->channel,
                'channelable_type' => $chat->channelable_type,
                'channelable_id' => $chat->channelable_id,
                'started_at' => now(),
                'status' => ChatSession::STATUS_ACTIVE,
            ]);

            if ($chat->lead) {
                Record::createChatSessionStarted($chat->lead, $session);
            }

            return $session;
        });
    }

    /**
     * Send the configured welcome message for this chat's connected account,
     * if the company has welcome messages enabled and a message is configured.
     *
     * Callers must invoke this AFTER the inbound message that started the
     * session has been persisted, so the welcome message — which lands in
     * the same second — sorts after it in the timeline rather than before.
     * Only fires for a session that was just created by this same request
     * (`$session->wasRecentlyCreated`); reused/already-active sessions never
     * get a second welcome message.
     */
    public static function sendWelcomeMessageIfEnabled(InboxChat $chat, ChatSession $session): void
    {
        if (!$session->wasRecentlyCreated) {
            return;
        }

        if (!$chat->lead || !$chat->channelable_type || !$chat->channelable_id) {
            return;
        }

        $settings = ConversationSetting::where('company_id', $chat->company_id)->first();

        if (!$settings || !$settings->welcome_messages_enabled) {
            return;
        }

        $config = WelcomeMessageConfig::where('channelable_type', $chat->channelable_type)
            ->where('channelable_id', $chat->channelable_id)
            ->where('is_enabled', true)
            ->first();

        if (!$config || !$config->message) {
            return;
        }

        $text = MessageTemplateRenderer::render($config->message, $chat->lead, $chat->lead->assignedUser);

        ChannelMessageSender::send($chat, $session, $text);
    }

    /**
     * Stamp the session's first-message timestamp from its first inbound message.
     */
    public static function recordMessage(ChatSession $session, InboxMessage $message): void
    {
        if ($session->first_message_at === null && $message->direction === 'incoming') {
            $session->update(['first_message_at' => $message->sent_at ?? now()]);
        }
    }

    /**
     * Manually close an active session with a required reason.
     */
    public static function close(
        ChatSession $session,
        ClosingReason $reason,
        ?string $notes,
        ?User $actor
    ): ChatSession {
        $session->update([
            'status' => ChatSession::STATUS_CLOSED,
            'ended_at' => now(),
            'closing_reason_id' => $reason->id,
            'closing_notes' => $notes,
        ]);

        if ($session->lead) {
            Record::createChatSessionEnded($session->lead, $session, $reason, $actor);
        }

        return $session;
    }

    /**
     * Seed the company's default closing reasons the first time they're needed.
     */
    public static function ensureDefaultClosingReasons(int $companyId): void
    {
        if (ClosingReason::where('company_id', $companyId)->exists()) {
            return;
        }

        foreach (self::DEFAULT_CLOSING_REASONS as $index => $name) {
            ClosingReason::create([
                'company_id' => $companyId,
                'name' => $name,
                'is_active' => true,
                'sort_order' => $index,
            ]);
        }
    }
}
