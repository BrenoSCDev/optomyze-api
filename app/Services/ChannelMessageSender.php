<?php

namespace App\Services;

use App\Events\InboxMessageReceived;
use App\Models\ChatSession;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\InstagramIntegration;
use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsAppInstance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends an automated outgoing message (welcome / automatic closing message)
 * through the chat's channel, outside of any HTTP request context. Never
 * throws — failures are logged so a broken integration can't break the
 * ChatSession lifecycle that triggers these sends.
 */
class ChannelMessageSender
{
    public static function send(InboxChat $chat, ChatSession $session, string $text): ?InboxMessage
    {
        if (!in_array($chat->channel, [InboxChat::CHANNEL_WHATSAPP, InboxChat::CHANNEL_INSTAGRAM], true)) {
            Log::info('ChannelMessageSender: automated sending not implemented for channel', [
                'channel' => $chat->channel,
                'chat_id' => $chat->id,
            ]);

            return null;
        }

        try {
            $externalMessageId = $chat->channel === InboxChat::CHANNEL_WHATSAPP
                ? self::sendWhatsApp($chat, $text)
                : self::sendInstagram($chat, $text);

            if ($externalMessageId === null) {
                return null;
            }

            $message = InboxMessage::create([
                'inbox_chat_id' => $chat->id,
                'chat_session_id' => $session->id,
                'user_id' => null,
                'external_message_id' => $externalMessageId,
                'direction' => 'outgoing',
                'message_mode' => 'external',
                'type' => 'text',
                'text' => $text,
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            $chat->update(['last_message_at' => now()]);

            broadcast(new InboxMessageReceived($message))->toOthers();

            return $message;
        } catch (\Throwable $e) {
            Log::warning('ChannelMessageSender: failed to send automated message', [
                'chat_id' => $chat->id,
                'channel' => $chat->channel,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private static function sendWhatsApp(InboxChat $chat, string $text): ?string
    {
        $instance = $chat->channelable instanceof WhatsAppInstance ? $chat->channelable : null;

        if (!$instance) {
            return null;
        }

        $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
            ->where('company_id', $chat->company_id)
            ->first();

        if (!$integration) {
            return null;
        }

        $waMessageId = (string) Str::uuid();

        $response = Http::withHeaders([
            'apikey' => $integration->api_key,
        ])->post(
            rtrim($integration->base_url, '/') . '/message/sendText/' . $instance->instance_name,
            [
                'number' => $chat->external_contact_id,
                'text' => $text,
            ]
        );

        if (!$response->successful()) {
            Log::warning('ChannelMessageSender: WhatsApp send failed', [
                'chat_id' => $chat->id,
                'error' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        return $response->json()['key']['id'] ?? $waMessageId;
    }

    private static function sendInstagram(InboxChat $chat, string $text): ?string
    {
        $integration = $chat->channelable instanceof InstagramIntegration ? $chat->channelable : null;

        if (!$integration) {
            return null;
        }

        $externalMessageId = (string) Str::uuid();

        $response = Http::withToken($integration->access_token)->post(
            "https://graph.instagram.com/v23.0/{$integration->instagram_user_id}/messages",
            [
                'recipient' => ['id' => $chat->external_contact_id],
                'message' => ['text' => $text],
            ]
        );

        if (!$response->successful()) {
            Log::warning('ChannelMessageSender: Instagram send failed', [
                'chat_id' => $chat->id,
                'error' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        return $response->json('message_id') ?? $externalMessageId;
    }
}
