<?php

namespace App\Services\Widgets;

use App\Events\InboxMessageReceived;
use App\Events\LeadLiveUpdate;
use App\Jobs\FireIntegrationWebhook;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\Lead;
use App\Models\Record;
use App\Models\Widget;
use App\Models\WidgetChatVisitor;
use App\Services\Ai\AiAgentDispatcher;
use App\Services\ChatSessionService;
use App\Services\LeadQueueAssignmentService;
use App\Services\NotificationService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The website-chat conversation engine.
 *
 * Website Chat is not a parallel messaging system: a visitor's thread is an
 * ordinary InboxChat on the `website` channel whose channelable is the Widget.
 * Everything the Global Inbox already does — chat sessions, welcome messages,
 * AI agents, notifications, live broadcasts — therefore applies unchanged, and
 * future channels can reuse this same shape.
 */
class WebsiteChatService
{
    /**
     * Resolve the visitor behind a browser token, or mint a new one. Returns
     * null when a token is supplied but doesn't belong to this widget, so a
     * stale/forged token can never read someone else's conversation.
     */
    public function resolveVisitor(Widget $widget, ?string $token, Request $request): ?WidgetChatVisitor
    {
        if ($token) {
            $visitor = WidgetChatVisitor::where('token', $token)
                ->where('widget_id', $widget->id)
                ->first();

            if (!$visitor) {
                return null;
            }

            $visitor->forceFill([
                'last_seen_at' => now(),
                'page_url' => Str::limit((string) $request->input('page_url'), 480, '') ?: $visitor->page_url,
            ])->save();

            return $visitor;
        }

        return WidgetChatVisitor::create([
            'widget_id' => $widget->id,
            'company_id' => $widget->company_id,
            'visitor_id' => WidgetChatVisitor::newVisitorId(),
            'token' => WidgetChatVisitor::newToken(),
            'page_url' => Str::limit((string) $request->input('page_url'), 480, ''),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'identified' => false,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Store the details a visitor gave in the pre-chat form. When the visitor
     * already has a lead (they identified after chatting anonymously), the
     * lead is enriched in place rather than duplicated.
     */
    public function identify(WidgetChatVisitor $visitor, array $data): WidgetChatVisitor
    {
        $visitor->fill(array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ], fn ($value) => filled($value)));

        $visitor->identified = true;
        $visitor->save();

        if ($visitor->lead_id) {
            $this->mergeIdentityIntoLead($visitor);
        }

        return $visitor;
    }

    /**
     * Handle an inbound message from the website visitor: create the lead and
     * conversation on first contact, then run the same lifecycle every other
     * channel runs.
     */
    public function receiveVisitorMessage(Widget $widget, WidgetChatVisitor $visitor, string $text): InboxMessage
    {
        $chat = $this->ensureChat($widget, $visitor);

        $session = ChatSessionService::startOrGetActive($chat);

        $message = InboxMessage::create([
            'inbox_chat_id' => $chat->id,
            'chat_session_id' => $session?->id,
            'user_id' => null,
            'source' => InboxMessage::SOURCE_CONTACT,
            'external_message_id' => (string) Str::uuid(),
            'direction' => 'incoming',
            'message_mode' => 'external',
            'type' => 'text',
            'text' => $text,
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        if ($session) {
            ChatSessionService::recordMessage($session, $message);
        }

        $chat->update(['last_message_at' => now()]);

        broadcast(new InboxMessageReceived($message))->toOthers();

        // Same ordering as the other channels: the visitor's message is
        // broadcast first, then any automated reply follows it.
        if ($session) {
            ChatSessionService::sendWelcomeMessageIfEnabled($chat, $session);
        }

        AiAgentDispatcher::onInboundMessage($chat, $message, $session);

        return $message;
    }

    /**
     * Messages the visitor is allowed to see: their own plus every external
     * reply. Internal notes between agents never leave the CRM.
     */
    public function visitorMessages(WidgetChatVisitor $visitor, ?int $afterId = null): array
    {
        if (!$visitor->inbox_chat_id) {
            return [];
        }

        return InboxMessage::where('inbox_chat_id', $visitor->inbox_chat_id)
            ->where('message_mode', 'external')
            ->when($afterId, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (InboxMessage $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'text' => $message->text,
                'type' => $message->type,
                'sent_at' => optional($message->sent_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Find or create the InboxChat carrying this visitor's conversation, and
     * the lead behind it.
     */
    private function ensureChat(Widget $widget, WidgetChatVisitor $visitor): InboxChat
    {
        if ($visitor->inbox_chat_id && $visitor->chat) {
            return $visitor->chat;
        }

        $lead = $this->resolveLead($widget, $visitor);

        $chat = InboxChat::firstOrCreate(
            [
                'company_id' => $widget->company_id,
                'channel' => InboxChat::CHANNEL_WEBSITE,
                'channelable_type' => Widget::class,
                'channelable_id' => $widget->id,
                'external_chat_id' => $visitor->visitor_id,
            ],
            [
                'external_contact_id' => $visitor->visitor_id,
                'lead_id' => $lead->id,
                'contact_name' => $this->contactName($visitor, $lead),
                'is_group' => false,
                'last_message_at' => now(),
            ]
        );

        if (!$chat->lead_id) {
            $chat->update(['lead_id' => $lead->id]);
        }

        $visitor->forceFill([
            'lead_id' => $lead->id,
            'inbox_chat_id' => $chat->id,
        ])->save();

        // The widget list counts conversations for chat widgets the same way
        // it counts submissions for forms.
        if ($chat->wasRecentlyCreated) {
            $widget->forceFill([
                'submissions_count' => $widget->submissions_count + 1,
                'last_submission_at' => now(),
            ])->save();
        }

        return $chat;
    }

    /**
     * An identified visitor continues their existing lead — matched on email
     * first, then phone, as the spec requires. Anonymous visitors get a fresh
     * lead under a generated placeholder name the team can rename later.
     */
    private function resolveLead(Widget $widget, WidgetChatVisitor $visitor): Lead
    {
        if ($visitor->lead_id && $visitor->lead) {
            return $visitor->lead;
        }

        $existing = $this->findExistingLead($widget->company_id, $visitor->email, $visitor->phone);

        if ($existing) {
            $this->applyIdentityToLead($existing, $visitor);

            return $existing;
        }

        return $this->createLead($widget, $visitor);
    }

    private function findExistingLead(int $companyId, ?string $email, ?string $phone): ?Lead
    {
        if ($email) {
            $lead = Lead::where('company_id', $companyId)->where('email', $email)->first();

            if ($lead) {
                return $lead;
            }
        }

        if ($phone) {
            $digits = PhoneNormalizer::normalize($phone);

            if ($digits !== '') {
                return Lead::where('company_id', $companyId)
                    ->whereIn('phone', PhoneNormalizer::variants($digits))
                    ->first();
            }
        }

        return null;
    }

    private function createLead(Widget $widget, WidgetChatVisitor $visitor): Lead
    {
        $config = $widget->config;
        [$firstName, $lastName] = $this->splitName($visitor->name, $visitor->visitor_id);

        $lead = Lead::create([
            'company_id' => $widget->company_id,
            'funnel_id' => Arr::get($config, 'mapping.funnel_id'),
            'stage_id' => Arr::get($config, 'mapping.stage_id'),
            'assigned_to' => Arr::get($config, 'mapping.assigned_user_id') ?: null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $visitor->email,
            'phone' => $visitor->phone,
            'external_id' => $visitor->visitor_id,
            'source_platform' => Lead::PLATFORM_WEBSITE,
            'source_type' => 'chat_integration',
            'notes' => $this->originNote($widget, $visitor),
        ]);

        // Only fall back to the round-robin queue when no owner was configured.
        if (!$lead->assigned_to) {
            LeadQueueAssignmentService::assign($lead);
        }

        Record::createLeadCreation(
            $lead,
            null,
            Record::SOURCE_WEBHOOK,
            ['widgetName' => $widget->name, 'widget_id' => $widget->id],
            'via_chat_widget'
        );

        broadcast(new LeadLiveUpdate('created', $lead));

        FireIntegrationWebhook::dispatch('lead_created', [
            'id' => $lead->id,
            'company_id' => $lead->company_id,
            'funnel_id' => $lead->funnel_id,
            'stage_id' => $lead->stage_id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'source' => $lead->source_type,
            'created_at' => $lead->created_at?->toISOString(),
        ]);

        $this->notifyLeadCreated($lead);

        return $lead;
    }

    /** Fold newly supplied contact details into the lead behind the chat. */
    private function mergeIdentityIntoLead(WidgetChatVisitor $visitor): void
    {
        $lead = $visitor->lead;

        if (!$lead) {
            return;
        }

        $this->applyIdentityToLead($lead, $visitor);

        if ($visitor->chat) {
            $visitor->chat->update(['contact_name' => $this->contactName($visitor, $lead)]);
        }
    }

    /**
     * Fill blanks on the lead without overwriting anything the CRM already
     * knows — except the generated placeholder name, which exists precisely to
     * be replaced once the visitor tells us who they are.
     */
    private function applyIdentityToLead(Lead $lead, WidgetChatVisitor $visitor): void
    {
        $updates = [];

        if ($visitor->email && !$lead->email) {
            $updates['email'] = $visitor->email;
        }

        if ($visitor->phone && !$lead->phone) {
            $updates['phone'] = $visitor->phone;
        }

        if ($visitor->name && Str::startsWith($lead->first_name, 'chat_lead_')) {
            [$firstName, $lastName] = $this->splitName($visitor->name, $visitor->visitor_id);
            $updates['first_name'] = $firstName;
            $updates['last_name'] = $lastName;
        }

        if ($updates) {
            $lead->update($updates);
        }
    }

    private function notifyLeadCreated(Lead $lead): void
    {
        try {
            app(NotificationService::class)->dispatch('lead_created', $lead->company_id, [
                'lead_owner_id' => $lead->assigned_to,
                'actor_id' => null,
                'actor_name' => 'Website chat',
                'lead' => [
                    'id' => $lead->id,
                    'name' => trim("{$lead->first_name} {$lead->last_name}"),
                ],
                '_lead_model' => $lead,
            ]);
        } catch (\Throwable $e) {
            // A notification problem must never cost the visitor their message.
            Log::warning('Website chat: lead_created notification failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function contactName(WidgetChatVisitor $visitor, Lead $lead): string
    {
        return $visitor->name ?: trim("{$lead->first_name} {$lead->last_name}") ?: $visitor->displayName();
    }

    /**
     * Placeholder names are unique and obviously machine-made, so the team can
     * spot and rename them: chat_lead_493049302.
     */
    private function splitName(?string $name, string $visitorId): array
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));

        if ($name === '') {
            return ['chat_lead_' . random_int(100000000, 999999999), null];
        }

        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    private function originNote(Widget $widget, WidgetChatVisitor $visitor): string
    {
        $domain = $visitor->page_url ?: implode(', ', $widget->allowed_domains ?? []);

        return sprintf(
            "Started a website chat via \"%s\"%s on %s.",
            $widget->name,
            $domain ? " ({$domain})" : '',
            now()->toDayDateTimeString()
        );
    }
}
