<?php

namespace App\Http\Controllers;

use App\Models\CompanyFile;
use App\Services\Storage\CompanyStorage;

use App\Models\Lead;
use App\Models\InboxChat;
use App\Models\InboxMessage;
use App\Models\InstagramIntegration;
use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsAppInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Events\InboxChatUpdated;
use App\Events\InboxMessagesCleared;
use App\Events\InboxMessageReceived;
use App\Events\InboxMessageUpdated;
use App\Events\InboxMessagesRead;
use App\Events\LeadLiveUpdate;
use App\Services\Ai\AiAgentDispatcher;
use App\Services\ChatSessionService;
use App\Services\LeadQueueAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class InboxController extends Controller
{
    private const INSTAGRAM_GRAPH_API_BASE = 'https://graph.instagram.com/v23.0';

    public function handleEvolutionWebhook(Request $request)
    {
        $payload = $request->all();

        // Delivery/read receipts for outbound messages arrive as a separate
        // event and don't carry sender/lead info - handle them up front.
        if (($payload['event'] ?? null) === 'messages.update') {
            return $this->handleMessageStatusUpdate($payload);
        }

        // 1️⃣ Identify instance via Evolution instance name
        $instance = WhatsAppInstance::where('instance_name', $payload['instance'] ?? null)->first();

        if (!$instance) {
            return response()->json(['message' => 'Instance not found'], 404);
        }

        $companyId = $instance->company_id;

        // 2️⃣ Resolve sender info
        // Evolution API v3.6.0+: when "lid" addressing is used, key.remoteJid
        // holds an opaque identifier ending in "@lid" and the real WhatsApp
        // number is carried separately in key.remoteJidAlt as
        // "<number>@s.whatsapp.net". Otherwise the number is in key.remoteJid
        // directly, regardless of message direction.
        $rawRemoteJid = $payload['data']['key']['remoteJid'] ?? null;
        $remoteJidAlt = $payload['data']['key']['remoteJidAlt'] ?? null;

        if (is_string($rawRemoteJid) && str_ends_with($rawRemoteJid, '@lid')) {
            if (!$remoteJidAlt) {
                // @lid with no resolvable alt — Evolution sometimes fires a
                // second webhook event for the same message using only the
                // internal lid identifier. There is no usable phone number here,
                // so skip it silently (200 so the webhook doesn't retry) rather
                // than creating a garbage lead with "@lid" as the phone.
                return response()->json(['message' => 'unresolvable @lid jid, skipped'], 200);
            }
            $remoteJid = $remoteJidAlt;
        } else {
            $remoteJid = $rawRemoteJid;
        }

        if (!$remoteJid) {
            return response()->json(['message' => 'remoteJid not found'], 422);
        }

        $phoneNumber = str_replace('@s.whatsapp.net', '', $remoteJid);
        $pushName = trim($payload['data']['pushName'] ?? '');

        // Leads are only ever auto-created from an inbound message. An
        // outbound message sent directly from the connected phone (not via
        // the CRM) to a number with no matching lead must NOT fabricate one —
        // it's just tracked on the chat with no lead attached until a real
        // inbound message arrives for that number.
        $isInbound = !($payload['data']['key']['fromMe'] ?? false);

        // 🔹 Split pushName into first and last name
        $firstName = null;
        $lastName  = null;

        if ($pushName !== '') {
            $nameParts = explode(' ', $pushName, 2);
            $firstName = $nameParts[0];
            $lastName  = $nameParts[1] ?? null;
        }

        // 3️⃣ Find or create lead (inbound only)
        // findByPhone matches both old and new Brazilian mobile formats so a
        // lead created from a web form (new 9-digit number) is correctly
        // associated when the same person messages from a WhatsApp account
        // registered under the older 8-digit format, and vice-versa.
        $lead = Lead::findByPhone($companyId, $phoneNumber);

        $leadCreated = false;
        if (!$lead && $isInbound) {
            /*
             * A funnel is optional.
             *
             * Leads no longer have to live in one — an unfiled contact is
             * reachable from Contacts and can be filed later. Refusing the
             * webhook because the instance had no funnel meant a real customer
             * message was dropped over a configuration detail, which is a
             * worse outcome than a contact with no pipeline.
             */
            $funnel = $instance->funnel;

            $entryStage = $funnel
                ? $funnel->stages()->where('type', 'entry')->orderBy('order')->first()
                : null;

            $lead = Lead::create([
                'company_id'      => $companyId,
                'first_name'      => $firstName ?: $phoneNumber,
                'last_name'       => $lastName,
                'phone'           => $phoneNumber,
                'external_id'     => $remoteJid,
                'source_type'     => 'chat_integration',
                'source_platform' => 'whatsapp',
                // A stage without its funnel is meaningless, so they travel
                // together: both set, or neither.
                'funnel_id'       => $entryStage ? $funnel->id : null,
                'stage_id'        => $entryStage?->id,
            ]);
            LeadQueueAssignmentService::assign($lead);
            $leadCreated = true;

            Log::info("[LeadLive] Broadcasting created for lead {$lead->id} to funnel." . ($lead->funnel_id ?? 'none'));
            broadcast(new LeadLiveUpdate('created', $lead));
        }

        // 4️⃣ Find or create inbox chat — only ever created from an inbound
        // message. An outbound message (fromMe=true) with no existing chat
        // has nothing to attach to yet, so it's dropped instead of
        // fabricating an empty chat.
        //
        // Lookups match across Brazilian 8-/9-digit phone formats so an
        // inbound message (WhatsApp-provided JID) and an outbound reply sent
        // from the CRM (JID rebuilt from lead.phone) converge on the SAME
        // chat instead of splitting into two. See InboxChat::findWhatsApp.
        if ($isInbound) {
            $chat = InboxChat::firstOrCreateWhatsApp($companyId, $instance, $phoneNumber, [
                'lead_id'         => $lead?->id,
                'last_message_at' => now(),
            ]);
        } else {
            $chat = InboxChat::findWhatsApp($companyId, $instance, $phoneNumber);

            if (!$chat) {
                return response()->json(['message' => 'No existing chat for outbound message'], 200);
            }
        }

        // Backfill: this chat may have started lead-less because no lead
        // matched the phone number yet; attach the lead now that we have one.
        if (!$chat->lead_id && $lead) {
            $chat->update(['lead_id' => $lead->id]);
        }

        // 5️⃣ Fire lead records for new lead / new chat
        if ($leadCreated) {
            \App\Models\Record::createLeadCreation(
                $lead,
                null,
                \App\Models\Record::SOURCE_WEBHOOK
            );
        }

        if ($chat->wasRecentlyCreated && $lead) {
            \App\Models\Record::createWhatsAppChatStarted($lead, $chat, $instance);

            // Fetch and store WhatsApp profile picture for the new chat
            $integration = WhatsAppEvoIntegration::where('company_id', $companyId)->first();
            if ($integration) {
                $picPath = $this->fetchAndStoreProfilePicture(
                    $integration,
                    $payload['instance'] ?? null,
                    $phoneNumber,
                    $companyId
                );
                if ($picPath) {
                    $chat->update(['profile_pic_url' => $picPath]);
                }
            }
        }

        // 7️⃣ Store message
        if (
            isset($payload['data']['message']) &&
            isset($payload['data']['messageType'])
        ) {
            $messageData = $payload['data']['message'];
            $messageType = $payload['data']['messageType'];

            // Reactions and message edits update an EXISTING message rather
            // than creating a new bubble in the conversation.
            if ($messageType === 'reactionMessage') {
                $this->handleReactionMessage($chat, $payload, $messageData);
                return response()->json(['status' => 'ok'], 200);
            }

            if ($messageType === 'secretEncryptedMessage') {
                $this->handleMessageEdit($chat, $payload, $messageData);
                return response()->json(['status' => 'ok'], 200);
            }

            // Use the real Evolution message ID for proper deduplication.
            // This also prevents double-storing messages that were sent from the
            // CRM (already saved with this ID) and re-delivered via webhook.
            $waMessageId = $payload['data']['key']['id'] ?? Str::random(20);

            // Prepare common message attributes
            $messageAttributes = [
                'inbox_chat_id'       => $chat->id,
                'external_message_id' => $waMessageId,
            ];

            $msgDirection = $isInbound ? 'incoming' : 'outgoing';

            // A session auto-starts from an inbound lead message only; outgoing
            // messages just attach to whatever session is currently active (if any),
            // and only once the chat actually has a lead attached.
            $chatSession = ($isInbound && $chat->lead_id)
                ? ChatSessionService::startOrGetActive($chat)
                : $chat->activeChatSession;

            $messageValues = [
                'direction'       => $msgDirection,
                'message_mode'    => 'external',
                'user_id'         => null,
                // Inbound = the customer; an outbound (fromMe) event that
                // reaches here is a message sent from the connected phone
                // outside the CRM (CRM sends are deduped by external id).
                'source'          => $isInbound
                    ? InboxMessage::SOURCE_CONTACT
                    : InboxMessage::SOURCE_EXTERNAL,
                'status'          => 'delivered',
                'sent_at'         => now(),
                'chat_session_id' => $chatSession?->id,
            ];

            // Handle different message types.
            // Media types set media_url = null immediately so the message is
            // stored without delay. The actual file download happens AFTER
            // firstOrCreate so a PHP/webhook timeout can never prevent the
            // message record from being created.
            $mediaDownloadType = null; // 'image' | 'audio' | 'document' | null

            if ($messageType === 'imageMessage') {
                $imageMessage = $messageData['imageMessage'] ?? null;
                if ($imageMessage) {
                    $messageValues['type']       = 'image';
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = $imageMessage['mimetype'] ?? 'image/jpeg';
                    $messageValues['media_size'] = self::resolveLongValue($imageMessage['fileLength'] ?? null);
                    $messageValues['text']       = $imageMessage['caption'] ?? null;
                    $mediaDownloadType           = 'image';
                }
            } elseif ($messageType === 'audioMessage') {
                $audioMessage = $messageData['audioMessage'] ?? null;
                if ($audioMessage) {
                    $messageValues['type']       = 'audio';
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = $audioMessage['mimetype'] ?? 'audio/ogg';
                    $messageValues['media_size'] = self::resolveLongValue($audioMessage['fileLength'] ?? null);
                    $messageValues['text']       = null;
                    $mediaDownloadType           = 'audio';
                }
            } elseif ($messageType === 'stickerMessage') {
                $stickerMessage = $messageData['stickerMessage'] ?? null;
                if ($stickerMessage) {
                    $messageValues['type']       = 'sticker';
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = $stickerMessage['mimetype'] ?? 'image/webp';
                    $messageValues['media_size'] = self::resolveLongValue($stickerMessage['fileLength'] ?? null);
                    $messageValues['text']       = null;
                    $mediaDownloadType           = 'sticker';
                }
            } elseif ($messageType === 'videoMessage') {
                // WhatsApp GIFs are sent as a looping videoMessage with
                // gifPlayback=true rather than a distinct message type.
                $videoMessage = $messageData['videoMessage'] ?? null;
                if ($videoMessage) {
                    $messageValues['type']       = 'video';
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = $videoMessage['mimetype'] ?? 'video/mp4';
                    $messageValues['media_size'] = self::resolveLongValue($videoMessage['fileLength'] ?? null);
                    $messageValues['text']       = $videoMessage['caption'] ?? null;
                    $messageValues['metadata']   = ($videoMessage['gifPlayback'] ?? false)
                        ? ['gif_playback' => true]
                        : null;
                    $mediaDownloadType           = 'video';
                }
            } elseif ($messageType === 'locationMessage') {

                $locationMessage = $messageData['locationMessage'] ?? null;

                if ($locationMessage) {
                    $messageValues['type']       = 'location';
                    $messageValues['latitude']   = $locationMessage['degreesLatitude'] ?? null;
                    $messageValues['longitude']  = $locationMessage['degreesLongitude'] ?? null;
                    $messageValues['text']       = null;
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = null;
                    $messageValues['media_size'] = null;
                }

            } elseif ($messageType === 'documentMessage') {
                $documentMessage = $messageData['documentMessage'] ?? null;
                if ($documentMessage) {
                    $messageValues['type']       = 'document';
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = $documentMessage['mimetype'] ?? 'application/octet-stream';
                    $messageValues['media_size'] = self::resolveLongValue($documentMessage['fileLength'] ?? null);
                    $messageValues['text']       = $documentMessage['fileName'] ?? $documentMessage['title'] ?? null;
                    $messageValues['latitude']   = null;
                    $messageValues['longitude']  = null;
                    $mediaDownloadType           = 'document';
                }
            } elseif ($messageType === 'contactMessage') {

                $contactMessage = $messageData['contactMessage'] ?? null;

                if ($contactMessage) {
                    $vcard       = $contactMessage['vcard'] ?? '';
                    $displayName = $contactMessage['displayName'] ?? null;

                    // Extract phone from waid= field which always has clean digits
                    $contactPhone = null;
                    if (preg_match('/waid=(\d+)/', $vcard, $matches)) {
                        $contactPhone = '+' . $matches[1];
                    } elseif (preg_match('/TEL[^:]*:([+\d\s\-\(\)]+)/', $vcard, $matches)) {
                        $contactPhone = trim($matches[1]);
                    }

                    $messageValues['type']      = 'contact';
                    $messageValues['text']      = $displayName;
                    $messageValues['metadata']  = [
                        'contact_name'  => $displayName,
                        'contact_phone' => $contactPhone,
                        'vcard'         => $vcard,
                    ];
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = null;
                    $messageValues['media_size'] = null;
                    $messageValues['latitude']   = null;
                    $messageValues['longitude']  = null;
                }

            } elseif ($messageType === 'contactsArrayMessage') {

                $contactsArray = $messageData['contactsArrayMessage'] ?? null;

                if ($contactsArray) {
                    $contacts = [];
                    foreach ($contactsArray['contacts'] ?? [] as $c) {
                        $vcard       = $c['vcard'] ?? '';
                        $displayName = $c['displayName'] ?? null;

                        $contactPhone = null;
                        if (preg_match('/waid=(\d+)/', $vcard, $matches)) {
                            $contactPhone = '+' . $matches[1];
                        } elseif (preg_match('/TEL[^:]*:([+\d\s\-\(\)]+)/', $vcard, $matches)) {
                            $contactPhone = trim($matches[1]);
                        }

                        $contacts[] = [
                            'contact_name'  => $displayName,
                            'contact_phone' => $contactPhone,
                            'vcard'         => $vcard,
                        ];
                    }

                    $displayName = $contactsArray['displayName'] ?? (count($contacts) . ' contacts');

                    $messageValues['type']      = 'contact';
                    $messageValues['text']      = $displayName;
                    $messageValues['metadata']  = [
                        'contacts' => $contacts,
                    ];
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = null;
                    $messageValues['media_size'] = null;
                    $messageValues['latitude']   = null;
                    $messageValues['longitude']  = null;
                }

            } elseif ($messageType === 'productMessage') {

                /*
                 * A catalog item shared from WhatsApp Business.
                 *
                 * It used to fall through to the text fallback, which read
                 * `conversation` — a key a product message does not have — and
                 * stored an empty bubble: the customer saw they had sent a
                 * product, the CRM showed nothing at all.
                 *
                 * The title becomes the message text so the conversation list
                 * preview, search and notifications all have something to say;
                 * everything else the card needs goes to metadata.
                 */
                $product = $messageData['productMessage']['product'] ?? null;

                if ($product) {
                    $productImage = $product['productImage'] ?? null;

                    $messageValues['type']       = 'product';
                    $messageValues['text']       = $product['title'] ?? null;
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = $productImage['mimetype'] ?? 'image/jpeg';
                    $messageValues['media_size'] = self::resolveLongValue($productImage['fileLength'] ?? null);
                    $messageValues['metadata']   = self::buildProductMetadata($messageData['productMessage']);
                    $messageValues['latitude']   = null;
                    $messageValues['longitude']  = null;

                    // Only worth asking Evolution when there is an image to get.
                    $mediaDownloadType = $productImage ? 'product' : null;
                }

            } elseif ($messageType === 'conversation') {

                // Handle text message
                $messageValues['type'] = 'text';
                $messageValues['text'] = $messageData['conversation'] ?? null;
                $messageValues['media_url'] = null;
                $messageValues['media_mime'] = null;
                $messageValues['media_size'] = null;

            } else {

                // Fallback for unhandled message types
                $messageValues['type'] = 'text';
                $messageValues['text'] = $messageData['conversation'] ?? null;
                $messageValues['media_url'] = null;
                $messageValues['media_mime'] = null;
                $messageValues['media_size'] = null;
            }

            // Reply / quoted-message context - applies on top of any message type above
            $contextInfo = $payload['data']['contextInfo'] ?? null;
            if ($contextInfo && !empty($contextInfo['quotedMessage'])) {
                $stanzaId = $contextInfo['stanzaId'] ?? null;

                $replyToMessage = $stanzaId
                    ? InboxMessage::where('inbox_chat_id', $chat->id)
                        ->where('external_message_id', $stanzaId)
                        ->first()
                    : null;

                $messageValues['reply_to_message_id'] = $replyToMessage?->id;
                $messageValues['reply_to_snapshot'] = $this->buildQuotedPreview(
                    $contextInfo['quotedMessage'],
                    $contextInfo
                );
            }

            // Create the message record immediately (media_url may be null for media types)
            $message = InboxMessage::firstOrCreate(
                $messageAttributes,
                $messageValues
            );

            if ($chatSession) {
                ChatSessionService::recordMessage($chatSession, $message);
            }

            // Download media and update the record (only for newly created media messages)
            if ($message->wasRecentlyCreated && $mediaDownloadType) {
                $integration = WhatsAppEvoIntegration::where('company_id', $companyId)->first();
                if ($integration) {
                    $originalFileName = null;
                    if ($mediaDownloadType === 'document') {
                        $originalFileName = $messageData['documentMessage']['fileName'] ?? null;
                    }
                    $mediaPath = $this->fetchMediaFromEvolution(
                        $integration,
                        $payload['instance'] ?? null,
                        $payload['data']['key'] ?? null,
                        $mediaDownloadType,
                        $companyId,
                        $originalFileName
                    );
                    // A product image Evolution would not decrypt falls back to
                    // the thumbnail WhatsApp already sent inline, so the card
                    // still has a picture rather than an empty frame.
                    $usedThumbnail = false;

                    if (!$mediaPath && $mediaDownloadType === 'product') {
                        $mediaPath = $this->storeProductThumbnail(
                            $messageData['productMessage']['product']['productImage']['jpegThumbnail'] ?? null,
                            $companyId
                        );
                        $usedThumbnail = (bool) $mediaPath;
                    }

                    if ($mediaPath) {
                        $updates = ['media_url' => $mediaPath];

                        /*
                         * media_size came from the payload's fileLength, which
                         * describes the full catalog photo. On the thumbnail
                         * path that is not the file we stored, and a size that
                         * disagrees with the bytes on disk is the kind of thing
                         * a storage report or a download header later gets
                         * wrong. Record what is actually there.
                         */
                        if ($usedThumbnail) {
                            $updates['media_size'] = Storage::disk('local')->size($mediaPath);
                        }

                        $message->update($updates);
                        $message->media_url = $mediaPath;
                    }
                }
            }

            // Update chat's last message timestamp
            $chat->update([
                'last_message_at' => now(),
            ]);

            // Broadcast real-time update
            broadcast(new InboxMessageReceived($message))->toOthers();

            // Sent only after the inbound message's own broadcast above, so
            // live clients receive them in the right order too (not just the
            // DB rows) — a welcome message broadcast firing first would show
            // up before the lead's own message on screen. No-op for a reused
            // session or an outgoing message.
            if ($isInbound && $chatSession) {
                ChatSessionService::sendWelcomeMessageIfEnabled($chat, $chatSession);
            }

            // Queue a debounced AI agent turn for inbound messages on
            // AI-owned chats (no-op when no enabled agent is bound).
            if ($isInbound && $message->wasRecentlyCreated) {
                AiAgentDispatcher::onInboundMessage($chat, $message, $chatSession);
            }
        }

        return response()->json(['status' => 'ok'], 200);
    }


    /**
     * The most recent conversation for one lead, in the same shape the inbox
     * list returns.
     *
     * The Contacts quick-chat modal can't rely on the paginated inbox list —
     * a lead's chat may sit on page 5, or the inbox may never have been
     * opened this session. Returns 204 when the lead has no conversation.
     */
    public function getLeadChat(Request $request, $leadId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        $chat = InboxChat::where('company_id', $user->company_id)
            ->where('lead_id', $leadId)
            ->with([
                'company:id,timezone',
                'channelable',
                'lead:id,company_id,first_name,last_name,phone,username,funnel_id,stage_id',
                'lead.funnel:id,name',
                'lead.stage:id,name',
                'latestMessage' => fn ($q) => $q->with('user:id,name,profile_pic'),
            ])
            ->orderByDesc('last_message_at')
            ->first();

        if (!$chat) {
            return response()->json(['data' => null], 200);
        }

        // Match the list shape: expose latestMessage as a messages array.
        $latest = $chat->latestMessage;
        if ($latest) {
            $latest->setRelation('chat', $chat);
        }
        $chat->setRelation('messages', $latest ? collect([$latest]) : collect([]));
        unset($chat->latest_message);

        return response()->json(['data' => $chat], 200);
    }

    public function getCompanyChats(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company'
            ], 403);
        }

        $companyId = $user->company_id;
        $page      = max(1, (int) $request->query('page', 1));
        $perPage   = 20;
        $channel   = $request->query('channel');
        $search    = trim((string) $request->query('search', ''));
        $readState = $request->query('read_state');          // all | unread | read
        $favorite  = $request->boolean('favorite');
        $archived  = $request->boolean('archived');

        $query = InboxChat::query()
            ->where('company_id', $companyId)
            ->withUnreadCount()
            ->readState($readState)
            ->with([
                'company:id,timezone',
                'channelable',
                'lead:id,company_id,first_name,last_name,phone,username,funnel_id,stage_id',
                'lead.funnel:id,name',
                'lead.stage:id,name',
                // Only the latest message per chat (for list preview)
                'latestMessage' => fn($q) => $q->with('user:id,name,profile_pic'),
            ])
            // Favourites pin to the top whatever their last activity; within
            // each group the newest conversation still leads.
            ->orderByDesc('is_favorite')
            ->orderByDesc('last_message_at');

        // Archiving a lead takes its conversation out of the inbox too.
        // Lead-less chats (an unknown number messaging in) are unaffected.
        $query->where(function ($q) {
            $q->whereNull('lead_id')
                ->orWhereHas('lead', fn ($l) => $l->where('is_active', true));
        });

        // Archived conversations are put away, not deleted: hidden unless
        // asked for, and then shown on their own.
        $query->where('is_archived', $archived);

        if ($favorite) {
            $query->where('is_favorite', true);
        }

        if ($channel) {
            $query->channel($channel);
        }

        // Search across the chat's own contact fields (covers leadless chats)
        // and the linked lead's name/phone - lets agents jump straight to a
        // conversation by typing the lead's first or last name.
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where('contact_name', 'like', $like)
                    ->orWhere('external_contact_id', 'like', $like)
                    ->orWhereHas('lead', function ($leadQuery) use ($like) {
                        // sqlite (tests) has no CONCAT() — use its || operator
                        $fullName = DB::getDriverName() === 'sqlite'
                            ? "first_name || ' ' || last_name"
                            : "CONCAT(first_name, ' ', last_name)";

                        $leadQuery->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhereRaw("{$fullName} LIKE ?", [$like])
                            ->orWhere('phone', 'like', $like);
                    });
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Company AI agent bindings (few rows) so each chat can expose
        // whether an agent handles its channel account — no per-chat query.
        $agentBindings = \App\Models\AiAgentChannelBinding::where('company_id', $companyId)
            ->with('agent:id,name,status')
            ->get();

        // Map: expose latestMessage as messages array for frontend compatibility
        $chats = $paginator->getCollection()->map(function ($chat) use ($agentBindings) {
            $latest = $chat->latestMessage;
            if ($latest) {
                $latest->setRelation('chat', $chat);
            }
            $chat->setRelation('messages', $latest ? collect([$latest]) : collect([]));
            unset($chat->latest_message);

            $binding = $agentBindings->first(fn ($b) => $b->channelable_type === $chat->channelable_type
                && $b->channelable_id === $chat->channelable_id);

            $chat->setAttribute('bound_agent', $binding?->agent ? [
                'id' => $binding->agent->id,
                'name' => $binding->agent->name,
                'status' => $binding->agent->status,
            ] : null);

            return $chat;
        });

        return response()->json([
            'data' => $chats,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ], 200);
    }

    /**
     * Manually toggle AI ownership of a conversation. Re-enabling with
     * unanswered inbound messages queues a fresh AI turn so the agent
     * resumes cleanly.
     */
    public function updateAiAgentService(Request $request, $chatId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $chat = InboxChat::where('id', $chatId)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $enabled = $request->boolean('enabled');

        $chat->update(['ai_agent_service' => $enabled]);

        broadcast(new InboxChatUpdated($chat))->toOthers();

        if ($enabled) {
            $agent = $chat->boundAiAgent();
            $session = $chat->activeChatSession;

            if ($agent && $agent->isEnabled() && $session && AiAgentDispatcher::providerUsable($agent)) {
                $latestInboundId = $chat->messages()
                    ->where('direction', 'incoming')
                    ->where('message_mode', 'external')
                    ->max('id');

                $latestOutgoingId = $chat->messages()
                    ->where('direction', 'outgoing')
                    ->where('message_mode', 'external')
                    ->max('id');

                if ($latestInboundId && $latestInboundId > ($latestOutgoingId ?? 0)) {
                    \App\Jobs\ProcessAiAgentTurn::dispatch($chat->id, $agent->id, $latestInboundId)
                        ->delay(now()->addSeconds(max(
                            \App\Models\AiAgent::MIN_RESPONSE_DELAY_SECONDS,
                            $agent->response_delay_seconds
                        )));
                }
            }
        }

        return response()->json([
            'message' => 'AI agent service updated.',
            'chat' => [
                'id' => $chat->id,
                'ai_agent_service' => $chat->ai_agent_service,
            ],
        ]);
    }

    /**
     * Mark every inbound message in a conversation as read.
     *
     * Called when someone opens the conversation. Idempotent, and silent when
     * there was nothing to mark — otherwise merely clicking through the list
     * would broadcast to everyone with the inbox open.
     */
    public function markChatRead(Request $request, InboxChat $chat)
    {
        $user = Auth::user();

        if (!$user || $chat->company_id !== $user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Captured before the update: afterwards there is nothing left to find.
        $messageIds = $chat->unreadMessages()->pluck('id')->all();

        if ($messageIds === []) {
            return response()->json(['unread_count' => 0, 'marked' => 0]);
        }

        $chat->markInboundRead();

        broadcast(new InboxMessagesRead($chat, InboxMessage::DIRECTION_INCOMING, $messageIds, 0));

        return response()->json(['unread_count' => 0, 'marked' => count($messageIds)]);
    }

    /**
     * Put a conversation away, or bring it back. Archived chats are hidden
     * from the default list rather than deleted.
     */
    public function setChatArchived(Request $request, InboxChat $chat)
    {
        return $this->toggleChatFlag($request, $chat, 'is_archived', 'archived');
    }

    /** Pin a conversation to the top of the list, or unpin it. */
    public function setChatFavorite(Request $request, InboxChat $chat)
    {
        return $this->toggleChatFlag($request, $chat, 'is_favorite', 'favorite');
    }

    /**
     * Shared body for the two per-chat boolean toggles. Omitting the value
     * flips the current one, so the UI can fire and forget.
     */
    private function toggleChatFlag(Request $request, InboxChat $chat, string $column, string $input)
    {
        $user = Auth::user();

        if (!$user || $chat->company_id !== $user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $next = $request->has($input) ? $request->boolean($input) : !$chat->{$column};

        $chat->update([$column => $next]);

        broadcast(new InboxChatUpdated($chat->fresh()));

        return response()->json([
            'data' => [
                'id'          => $chat->id,
                'is_archived' => (bool) $chat->is_archived,
                'is_favorite' => (bool) $chat->is_favorite,
            ],
        ]);
    }

    public function getChatMessages(Request $request, $chatId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $chat = InboxChat::where('id', $chatId)
            ->where('company_id', $user->company_id)
            ->with('company:id,timezone')
            ->firstOrFail();

        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        $paginator = $chat->messages()
            ->with(['user:id,name,profile_pic', 'aiAgent:id,name'])
            // `sent_at` alone isn't a deterministic sort: messages created in
            // the same second (e.g. an inbound message and the welcome reply
            // it triggers) tie, and MySQL doesn't guarantee tie order. `id`
            // breaks the tie consistently in actual insertion order.
            ->orderBy('sent_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $messages = $paginator->getCollection();
        $messages->each(fn($msg) => $msg->setRelation('chat', $chat));

        return response()->json([
            'data' => $messages, // newest first — frontend reverses for display
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ], 200);
    }

    public function sendTextMessage(Request $request, $instanceId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $companyId = $user->company_id;

        $request->validate([
            'lead_id'      => 'required|integer',
            'text'         => 'required|string',
            'message_mode' => 'sometimes|in:external,internal',
        ]);

        $messageMode = $request->input('message_mode', 'external');

        // 1️⃣ Fetch instance (company-safe)
        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        // 2️⃣ Fetch lead
        $lead = Lead::where('id', $request->lead_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $phoneNumber = $lead->phone;

        // 3️⃣ Find or create chat — matched across Brazilian phone-format
        // variants so replying never spawns a duplicate of the inbound chat.
        $chat = InboxChat::firstOrCreateWhatsApp($companyId, $instance, $phoneNumber, [
            'lead_id' => $lead->id,
        ]);

        // Backfill the lead if the chat was started lead-less.
        if (!$chat->lead_id) {
            $chat->update(['lead_id' => $lead->id]);
        }

        try {
            $waMessageId = Str::uuid();

            // 4️⃣ External: call WhatsApp API first to get the real message ID
            if ($messageMode === 'external') {
                $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
                    ->where('company_id', $companyId)
                    ->firstOrFail();

                $response = Http::withHeaders([
                    'apikey' => $integration->api_key,
                ])->post(
                    rtrim($integration->base_url, '/') . '/message/sendText/' . $instance->instance_name,
                    [
                        'number' => $phoneNumber,
                        'text'   => $request->text,
                    ]
                );

                if (!$response->successful()) {
                    return response()->json([
                        'message' => 'Failed to send message via Evolution API',
                        'error'   => $response->json() ?? $response->body(),
                    ], 422);
                }

                $waMessageId = $response->json()['key']['id'] ?? $waMessageId;
            }

            // An agent proactively messaging a lead whose conversation is
            // closed (or never started) starts a new one here too — just
            // without a welcome message, since this isn't lead-initiated.
            $chatSession = $chat->lead_id ? ChatSessionService::startOrGetActive($chat) : $chat->activeChatSession;

            // 5️⃣ Store outgoing message
            $message = InboxMessage::create([
                'inbox_chat_id'       => $chat->id,
                'chat_session_id'     => $chatSession?->id,
                'user_id'             => $user->id,
                'source'              => InboxMessage::SOURCE_USER,
                'external_message_id' => $waMessageId,
                'direction'           => 'outgoing',
                'message_mode'        => $messageMode,
                'type'                => 'text',
                'text'                => $request->text,
                'status'              => 'sent',
                'sent_at'             => now(),
            ]);

            // 6️⃣ Update chat activity
            $chat->update([
                'last_message_at' => now(),
            ]);

            // 7️⃣ Broadcast real-time update
            broadcast(new InboxMessageReceived($message))->toOthers();

            // Human takeover: a customer-visible message from a CRM user
            // immediately revokes AI ownership of the conversation.
            if ($messageMode === 'external') {
                AiAgentDispatcher::onHumanExternalSend($chat);
            }

            $chat->load('company:id,timezone');
            $message->setRelation('chat', $chat);

            return response()->json([
                'status'  => 'sent',
                'message' => $message->load('user:id,name,profile_pic'),
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send WhatsApp message',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send (or remove) an emoji reaction to a WhatsApp message via Evolution's
     * `/message/sendReaction` endpoint, and reflect it locally under the
     * 'agent' key of the message's `reactions` column.
     */
    public function sendReaction(Request $request, $messageId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $companyId = $user->company_id;

        $request->validate([
            'emoji' => 'present|string|max:8',
        ]);

        $emoji = $request->input('emoji');

        $message = InboxMessage::with('chat.channelable')
            ->whereHas('chat', fn ($q) => $q->where('company_id', $companyId))
            ->where('id', $messageId)
            ->firstOrFail();

        $chat = $message->chat;

        if (!$message->external_message_id) {
            return response()->json(['message' => 'This message has no provider message id'], 422);
        }

        if ($chat->channel === InboxChat::CHANNEL_WHATSAPP && $chat->channelable instanceof WhatsAppInstance) {
            $instance = $chat->channelable;

            $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
                ->where('company_id', $companyId)
                ->firstOrFail();

            $response = Http::withHeaders(['apikey' => $integration->api_key])
                ->post(rtrim($integration->base_url, '/') . '/message/sendReaction/' . $instance->instance_name, [
                    'key' => [
                        'remoteJid' => $chat->external_chat_id,
                        'fromMe'    => $message->direction === 'outgoing',
                        'id'        => $message->external_message_id,
                    ],
                    'reaction' => $emoji,
                ]);

            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Failed to send reaction via Evolution API',
                    'error'   => $response->json() ?? $response->body(),
                ], 422);
            }
        } elseif ($chat->channel === InboxChat::CHANNEL_INSTAGRAM && $chat->channelable instanceof InstagramIntegration) {
            $integration = $chat->channelable;

            if (!$this->isUsableInstagramMid($message->external_message_id)) {
                return response()->json([
                    'message' => 'This message has no valid Instagram reference to react to.',
                ], 422);
            }

            // The Instagram Send API takes the literal emoji character as the
            // `reaction` value (not an English keyword like "like"/"wow").
            $payload = $emoji === ''
                ? ['message_id' => $message->external_message_id]
                : ['message_id' => $message->external_message_id, 'reaction' => $emoji];

            $response = Http::withToken($integration->access_token)
                ->post(self::INSTAGRAM_GRAPH_API_BASE . "/{$integration->instagram_user_id}/messages", [
                    'recipient'     => ['id' => $chat->external_contact_id],
                    'sender_action' => $emoji === '' ? 'unreact' : 'react',
                    'payload'       => $payload,
                ]);

            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Failed to send reaction via Instagram API',
                    'error'   => $response->json() ?? $response->body(),
                ], 422);
            }
        } else {
            return response()->json(['message' => 'Reactions are not supported for this chat channel'], 422);
        }

        $reactions = $message->reactions ?? [];

        if ($emoji === '') {
            unset($reactions['agent']);
        } else {
            $reactions['agent'] = [
                'emoji'     => $emoji,
                'timestamp' => now()->getTimestampMs(),
            ];
        }

        $message->update(['reactions' => $reactions]);

        broadcast(new InboxMessageUpdated($message))->toOthers();

        return response()->json([
            'status'  => 'ok',
            'message' => $message,
        ], 200);
    }

    /**
     * Transcribe an audio message via OpenAI's speech-to-text API.
     * The result is cached on the message's `transcription` column so
     * repeat requests don't re-hit the API.
     */
    public function transcribeMessage(Request $request, $messageId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $companyId = $user->company_id;

        $message = InboxMessage::whereHas('chat', fn ($q) => $q->where('company_id', $companyId))
            ->where('id', $messageId)
            ->firstOrFail();

        if ($message->type !== 'audio' || !$message->media_url) {
            return response()->json(['message' => 'This message has no audio to transcribe'], 422);
        }

        if ($message->transcription) {
            return response()->json([
                'status'        => 'ok',
                'transcription' => $message->transcription,
                'message'       => $message,
            ], 200);
        }

        $apiKey = config('services.openai.key');

        if (!$apiKey) {
            return response()->json(['message' => 'OpenAI API key is not configured'], 500);
        }

        if (!Storage::disk('local')->exists($message->media_url)) {
            return response()->json(['message' => 'Audio file not found'], 404);
        }

        $audio     = Storage::disk('local')->get($message->media_url);
        $extension = self::extensionFromMime($message->media_mime ?? 'audio/ogg');

        $response = Http::withToken($apiKey)
            ->attach('file', $audio, 'audio.' . $extension)
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'gpt-4o-mini-transcribe',
            ]);

        if (!$response->successful()) {
            return response()->json([
                'message' => 'Failed to transcribe audio',
                'error'   => $response->json() ?? $response->body(),
            ], 422);
        }

        $transcription = trim($response->json('text') ?? '');

        $message->update(['transcription' => $transcription]);

        broadcast(new InboxMessageUpdated($message))->toOthers();

        return response()->json([
            'status'        => 'ok',
            'transcription' => $transcription,
            'message'       => $message,
        ], 200);
    }

    public function sendMediaMessage(Request $request, $instanceId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $companyId = $user->company_id;

        $request->validate([
            'lead_id'      => 'required|integer',
            'type'         => 'required|in:image,audio',
            'file'         => 'required|file|max:20480',
            'message_mode' => 'sometimes|in:external,internal',
        ]);

        $messageMode = $request->input('message_mode', 'external');
        $type        = $request->input('type');

        // 1️⃣ Fetch instance (company-safe)
        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        // 2️⃣ Fetch lead
        $lead = Lead::where('id', $request->lead_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $phoneNumber = $lead->phone;

        // 3️⃣ Find or create chat — matched across Brazilian phone-format
        // variants so replying never spawns a duplicate of the inbound chat.
        $chat = InboxChat::firstOrCreateWhatsApp($companyId, $instance, $phoneNumber, [
            'lead_id' => $lead->id,
        ]);

        // Backfill the lead if the chat was started lead-less.
        if (!$chat->lead_id) {
            $chat->update(['lead_id' => $lead->id]);
        }

        // 4️⃣ Store file locally
        $uploadedFile = $request->file('file');
        $folder       = $type === 'image' ? "whatsapp/images/{$companyId}" : "whatsapp/audio/{$companyId}";
        $filePath     = $uploadedFile->store($folder, 'local');
        $mime         = $uploadedFile->getMimeType();
        $size         = $uploadedFile->getSize();

        CompanyStorage::record($companyId, $filePath, CompanyFile::CATEGORY_WHATSAPP_MEDIA, mime: $mime);

        try {
            $waMessageId = Str::uuid();

            // 5️⃣ External: send to Evolution API as base64
            if ($messageMode === 'external') {
                $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
                    ->where('company_id', $companyId)
                    ->firstOrFail();

                $base64   = base64_encode(Storage::disk('local')->get($filePath));
                $apiBase  = rtrim($integration->base_url, '/');
                $apiKey   = $integration->api_key;

                if ($type === 'image') {
                    $response = Http::withHeaders(['apikey' => $apiKey])
                        ->post("{$apiBase}/message/sendMedia/{$instance->instance_name}", [
                            'number'    => $phoneNumber,
                            'mediatype' => 'image',
                            'media'     => $base64,
                            'fileName'  => $uploadedFile->getClientOriginalName(),
                        ]);
                } else {
                    $response = Http::withHeaders(['apikey' => $apiKey])
                        ->post("{$apiBase}/message/sendWhatsAppAudio/{$instance->instance_name}", [
                            'number' => $phoneNumber,
                            'audio'  => $base64,
                        ]);
                }

                if (!$response->successful()) {
                    Storage::disk('local')->delete($filePath);
                    CompanyStorage::forget($filePath);
                    return response()->json([
                        'message' => 'Failed to send media via Evolution API',
                        'error'   => $response->json() ?? $response->body(),
                    ], 422);
                }

                $waMessageId = $response->json()['key']['id'] ?? $waMessageId;
            }

            // An agent proactively messaging a lead whose conversation is
            // closed (or never started) starts a new one here too — just
            // without a welcome message, since this isn't lead-initiated.
            $chatSession = $chat->lead_id ? ChatSessionService::startOrGetActive($chat) : $chat->activeChatSession;

            // 6️⃣ Store outgoing message
            $message = InboxMessage::create([
                'inbox_chat_id'       => $chat->id,
                'chat_session_id'     => $chatSession?->id,
                'user_id'             => $user->id,
                'source'              => InboxMessage::SOURCE_USER,
                'external_message_id' => $waMessageId,
                'direction'           => 'outgoing',
                'message_mode'        => $messageMode,
                'type'                => $type,
                'media_url'           => $filePath,
                'media_mime'          => $mime,
                'media_size'          => $size,
                'status'              => 'sent',
                'sent_at'             => now(),
            ]);

            // 7️⃣ Update chat activity
            $chat->update(['last_message_at' => now()]);

            // 8️⃣ Broadcast real-time update to other participants
            broadcast(new InboxMessageReceived($message))->toOthers();

            // Human takeover: a customer-visible message from a CRM user
            // immediately revokes AI ownership of the conversation.
            if ($messageMode === 'external') {
                AiAgentDispatcher::onHumanExternalSend($chat);
            }

            $chat->load('company:id,timezone');
            $message->setRelation('chat', $chat);

            return response()->json([
                'status'  => 'sent',
                'message' => $message->load('user:id,name,profile_pic'),
            ], 200);

        } catch (\Throwable $e) {
            Storage::disk('local')->delete($filePath);
            CompanyStorage::forget($filePath);
            return response()->json([
                'message' => 'Failed to send WhatsApp media',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function sendInstagramTextMessage(Request $request, $integrationId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $companyId = $user->company_id;

        $request->validate([
            'chat_id'             => 'required|integer',
            'text'                => 'required|string',
            'message_mode'        => 'sometimes|in:external,internal',
            'reply_to_message_id' => 'sometimes|nullable|integer',
        ]);

        $messageMode = $request->input('message_mode', 'external');

        $integration = InstagramIntegration::where('id', $integrationId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $chat = InboxChat::where('id', $request->chat_id)
            ->where('company_id', $companyId)
            ->where('channel', InboxChat::CHANNEL_INSTAGRAM)
            ->where('channelable_type', InstagramIntegration::class)
            ->where('channelable_id', $integration->id)
            ->firstOrFail();

        $igsid = $chat->external_contact_id;

        $replyToMessage = $request->filled('reply_to_message_id')
            ? InboxMessage::where('inbox_chat_id', $chat->id)->find($request->input('reply_to_message_id'))
            : null;

        if ($replyToMessage && !$this->isUsableInstagramMid($replyToMessage->external_message_id)) {
            return response()->json([
                'message' => 'This message cannot be replied to (no valid Instagram reference for it).',
            ], 422);
        }

        try {
            $externalMessageId = (string) Str::uuid();

            if ($messageMode === 'external') {
                $body = [
                    'recipient' => ['id' => $igsid],
                    'message'   => ['text' => $request->text],
                ];

                if ($replyToMessage?->external_message_id) {
                    $body['reply_to'] = ['mid' => $replyToMessage->external_message_id];
                }

                $response = Http::withToken($integration->access_token)
                    ->post(self::INSTAGRAM_GRAPH_API_BASE . "/{$integration->instagram_user_id}/messages", $body);

                if (!$response->successful()) {
                    if ($this->isInstagramWindowExpired($response)) {
                        return response()->json(['message' => 'instagram_window_expired'], 422);
                    }

                    return response()->json([
                        'message' => 'Failed to send message via Instagram API',
                        'error'   => $response->json() ?? $response->body(),
                    ], 422);
                }

                $externalMessageId = $response->json('message_id') ?? $externalMessageId;

                if (!$response->json('message_id')) {
                    Log::warning('Instagram send: no message_id in response, using fallback UUID', [
                        'integration_id' => $integration->id,
                        'chat_id'        => $chat->id,
                        'response_body'  => $response->json(),
                    ]);
                }
            }

            // An agent proactively messaging a lead whose conversation is
            // closed (or never started) starts a new one here too — just
            // without a welcome message, since this isn't lead-initiated.
            $chatSession = $chat->lead_id ? ChatSessionService::startOrGetActive($chat) : $chat->activeChatSession;

            $message = InboxMessage::create([
                'inbox_chat_id'       => $chat->id,
                'chat_session_id'     => $chatSession?->id,
                'user_id'             => $user->id,
                'source'              => InboxMessage::SOURCE_USER,
                'external_message_id' => $externalMessageId,
                'direction'           => 'outgoing',
                'message_mode'        => $messageMode,
                'type'                => 'text',
                'text'                => $request->text,
                'reply_to_message_id' => $replyToMessage?->id,
                'reply_to_snapshot'   => $replyToMessage ? [
                    'type'        => $replyToMessage->type,
                    'text'        => $replyToMessage->text,
                    'participant' => null,
                ] : null,
                'status'              => 'sent',
                'sent_at'             => now(),
            ]);

            $chat->update(['last_message_at' => now()]);

            broadcast(new InboxMessageReceived($message))->toOthers();

            // Human takeover: a customer-visible message from a CRM user
            // immediately revokes AI ownership of the conversation.
            if ($messageMode === 'external') {
                AiAgentDispatcher::onHumanExternalSend($chat);
            }

            $chat->load('company:id,timezone');
            $message->setRelation('chat', $chat);

            return response()->json([
                'status'  => 'sent',
                'message' => $message->load('user:id,name,profile_pic'),
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send Instagram message',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function sendInstagramMediaMessage(Request $request, $integrationId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $companyId = $user->company_id;

        $request->validate([
            'chat_id'             => 'required|integer',
            'type'                => 'required|in:image,audio',
            'file'                => 'required|file|max:20480',
            'message_mode'        => 'sometimes|in:external,internal',
            'reply_to_message_id' => 'sometimes|nullable|integer',
        ]);

        $messageMode = $request->input('message_mode', 'external');
        $type        = $request->input('type');

        $integration = InstagramIntegration::where('id', $integrationId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $chat = InboxChat::where('id', $request->chat_id)
            ->where('company_id', $companyId)
            ->where('channel', InboxChat::CHANNEL_INSTAGRAM)
            ->where('channelable_type', InstagramIntegration::class)
            ->where('channelable_id', $integration->id)
            ->firstOrFail();

        $igsid = $chat->external_contact_id;

        $replyToMessage = $request->filled('reply_to_message_id')
            ? InboxMessage::where('inbox_chat_id', $chat->id)->find($request->input('reply_to_message_id'))
            : null;

        if ($replyToMessage && !$this->isUsableInstagramMid($replyToMessage->external_message_id)) {
            return response()->json([
                'message' => 'This message cannot be replied to (no valid Instagram reference for it).',
            ], 422);
        }

        $uploadedFile = $request->file('file');
        $filePath     = $uploadedFile->store("instagram/outbound/{$companyId}", 'local');
        CompanyStorage::record($companyId, $filePath, CompanyFile::CATEGORY_INSTAGRAM_MEDIA, mime: $uploadedFile->getMimeType());
        $mime         = $uploadedFile->getMimeType();
        // getMimeType() inspects magic bytes: WebM containers always resolve to
        // video/webm even when they only carry audio tracks.
        if ($type === 'audio' && str_starts_with($mime, 'video/')) {
            $mime = 'audio/' . substr($mime, strlen('video/'));
        }
        $size = $uploadedFile->getSize();

        try {
            $externalMessageId = (string) Str::uuid();

            if ($messageMode === 'external') {
                // Instagram doesn't support WebM audio (error 2534080). OGG Opus
                // also fails in practice despite the docs listing it. AAC in an
                // M4A container (audio/mp4) is reliably accepted and uses
                // ffmpeg's built-in aac encoder — no external library needed.
                // $filePath / $mime / $size are updated in place so the
                // InboxMessage record below reflects the actually-stored file.
                if ($type === 'audio' && $mime === 'audio/webm') {
                    $absoluteInput  = Storage::disk('local')->path($filePath);
                    $m4aRelPath     = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.m4a';
                    $absoluteOutput = Storage::disk('local')->path($m4aRelPath);

                    exec(
                        'ffmpeg -y -i ' . escapeshellarg($absoluteInput) . ' -c:a aac -b:a 128k ' . escapeshellarg($absoluteOutput) . ' 2>/dev/null',
                        $_ffmpegOut,
                        $ffmpegExit
                    );

                    if ($ffmpegExit === 0 && file_exists($absoluteOutput)) {
                        Storage::disk('local')->delete($filePath);
                        CompanyStorage::forget($filePath);
                        $filePath = $m4aRelPath;
                        $mime     = 'audio/mp4';
                        $size     = filesize($absoluteOutput);
                    } else {
                        Storage::disk('local')->delete($filePath);
                        CompanyStorage::forget($filePath);
                        Log::error('Instagram audio: ffmpeg WebM→M4A conversion failed', [
                            'company_id' => $companyId,
                            'exit_code'  => $ffmpegExit,
                        ]);
                        return response()->json([
                            'message' => 'Audio conversion failed. Ensure ffmpeg is installed on the server.',
                        ], 422);
                    }
                }

                // Instagram's servers fetch this URL directly and can't present
                // our Sanctum auth header, so it can't go through the normal
                // private /api/files route. A signed URL lets us hand them a
                // real public link to this one file, time-limited, without
                // relying on the public disk / storage:link being set up
                // correctly in every environment (which is what was 404ing).
                //
                // The signature is generated *relative* (absolute: false) so
                // it only covers the path + query string, not the scheme/host
                // - prod sits behind a proxy that terminates TLS and forwards
                // plain HTTP internally, so an absolute signature would be
                // signed as https:// but validated as http://, and fail.
                // URL::to() then prepends the correct external host/scheme
                // (forced to https in production, see AppServiceProvider).
                //
                // The mime rides along as a signed query param so the route
                // can serve the exact Content-Type. WebM audio is converted to
                // OGG above, so by this point $mime is always a format Instagram
                // accepts (audio/ogg, audio/mpeg, etc.).
                $signedPath = URL::temporarySignedRoute(
                    'files.signed',
                    now()->addMinutes(30),
                    ['path' => $filePath, 'mime' => $mime],
                    false
                );
                $publicUrl  = URL::to($signedPath);

                $attachment = [
                    'type'    => $type,
                    'payload' => [
                        'url' => $publicUrl,
                    ],
                ];

                // Instagram's Send API is inconsistent: images go under the
                // plural "attachments" key as an array, while audio/video/file
                // go under the singular "attachment" key as a plain object.
                // Using the wrong key or wrong shape produces either an
                // "Upload failed" (2018007) or a "must be a valid json object
                // with string keys" (code 100) validation error.
                $body = [
                    'recipient' => ['id' => $igsid],
                    'message'   => $type === 'image'
                        ? ['attachments' => [$attachment]]
                        : ['attachment' => $attachment],
                ];

                if ($replyToMessage?->external_message_id) {
                    $body['reply_to'] = ['mid' => $replyToMessage->external_message_id];
                }

                $response = Http::withToken($integration->access_token)
                    ->post(self::INSTAGRAM_GRAPH_API_BASE . "/{$integration->instagram_user_id}/messages", $body);

                if (!$response->successful()) {
                    Storage::disk('local')->delete($filePath);
                    CompanyStorage::forget($filePath);

                    if ($this->isInstagramWindowExpired($response)) {
                        return response()->json(['message' => 'instagram_window_expired'], 422);
                    }

                    return response()->json([
                        'message'         => 'Failed to send media via Instagram API',
                        'error'           => $response->json() ?? $response->body(),
                        'request_url'     => self::INSTAGRAM_GRAPH_API_BASE . "/{$integration->instagram_user_id}/messages",
                        'request_payload' => $body,
                    ], 422);
                }

                $externalMessageId = $response->json('message_id') ?? $externalMessageId;

                if (!$response->json('message_id')) {
                    Log::warning('Instagram media send: no message_id in response, using fallback UUID', [
                        'integration_id' => $integration->id,
                        'chat_id'        => $chat->id,
                        'response_body'  => $response->json(),
                    ]);
                }
            }

            // An agent proactively messaging a lead whose conversation is
            // closed (or never started) starts a new one here too — just
            // without a welcome message, since this isn't lead-initiated.
            $chatSession = $chat->lead_id ? ChatSessionService::startOrGetActive($chat) : $chat->activeChatSession;

            $message = InboxMessage::create([
                'inbox_chat_id'       => $chat->id,
                'chat_session_id'     => $chatSession?->id,
                'user_id'             => $user->id,
                'source'              => InboxMessage::SOURCE_USER,
                'external_message_id' => $externalMessageId,
                'direction'           => 'outgoing',
                'message_mode'        => $messageMode,
                'type'                => $type,
                'media_url'           => $filePath,
                'media_mime'          => $mime,
                'media_size'          => $size,
                'reply_to_message_id' => $replyToMessage?->id,
                'reply_to_snapshot'   => $replyToMessage ? [
                    'type'        => $replyToMessage->type,
                    'text'        => $replyToMessage->text,
                    'participant' => null,
                ] : null,
                'status'              => 'sent',
                'sent_at'             => now(),
            ]);

            $chat->update(['last_message_at' => now()]);

            broadcast(new InboxMessageReceived($message))->toOthers();

            // Human takeover: a customer-visible message from a CRM user
            // immediately revokes AI ownership of the conversation.
            if ($messageMode === 'external') {
                AiAgentDispatcher::onHumanExternalSend($chat);
            }

            $chat->load('company:id,timezone');
            $message->setRelation('chat', $chat);

            return response()->json([
                'status'  => 'sent',
                'message' => $message->load('user:id,name,profile_pic'),
            ], 200);

        } catch (\Throwable $e) {
            Storage::disk('local')->delete($filePath);
            CompanyStorage::forget($filePath);
            return response()->json([
                'message' => 'Failed to send Instagram media',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Whether a stored external_message_id is a genuine Meta-issued mid that
     * Instagram's `reply_to.mid` will accept, as opposed to one of our own
     * fallback UUIDs (assigned when the Graph API send response didn't
     * include a `message_id`, e.g. for internal-mode/simulated messages).
     * Real Instagram mids are long opaque strings with no dashes.
     */
    private function isUsableInstagramMid(?string $mid): bool
    {
        if (!$mid) {
            return false;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $mid) !== 1;
    }

    /**
     * Determine whether an Instagram API error indicates the 24-hour
     * messaging window for this conversation has expired.
     */
    private function isInstagramWindowExpired($response): bool
    {
        $errorMessage = strtolower((string) ($response->json('error.message') ?? ''));

        return str_contains($errorMessage, 'outside of allowed window')
            || str_contains($errorMessage, 're-engage');
    }

    public function searchLeads(Request $request)
    {
        $companyId = Auth::user()->company_id;
        $q = trim($request->query('q', ''));

        $leads = Lead::where('company_id', $companyId)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%");
                });
            })
            ->select('id', 'first_name', 'last_name', 'phone', 'funnel_id', 'stage_id')
            ->limit(20)
            ->get();

        return response()->json($leads);
    }

    /**
     * Reply to a website chat conversation.
     *
     * Website Chat has no third-party API to call: storing the message is the
     * delivery, and the visitor's widget picks it up on its next poll. The
     * rest of the lifecycle (chat session, broadcast, AI handover) is
     * identical to every other channel.
     */
    public function sendWebsiteChatMessage(Request $request, InboxChat $chat)
    {
        $user = Auth::user();

        if (!$user || $user->company_id !== $chat->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($chat->channel !== InboxChat::CHANNEL_WEBSITE) {
            return response()->json(['message' => 'This conversation is not a website chat.'], 422);
        }

        $data = $request->validate([
            'text'         => 'required|string|max:4000',
            'message_mode' => 'sometimes|in:external,internal',
        ]);

        $messageMode = $data['message_mode'] ?? 'external';

        $chatSession = $chat->lead_id
            ? ChatSessionService::startOrGetActive($chat)
            : $chat->activeChatSession;

        $message = InboxMessage::create([
            'inbox_chat_id'       => $chat->id,
            'chat_session_id'     => $chatSession?->id,
            'user_id'             => $user->id,
            'source'              => InboxMessage::SOURCE_USER,
            'external_message_id' => (string) Str::uuid(),
            'direction'           => 'outgoing',
            'message_mode'        => $messageMode,
            'type'                => 'text',
            'text'                => $data['text'],
            'status'              => 'sent',
            'sent_at'             => now(),
        ]);

        $chat->update(['last_message_at' => now()]);

        broadcast(new InboxMessageReceived($message))->toOthers();

        // A customer-visible reply from a human revokes AI ownership, exactly
        // as it does on WhatsApp and Instagram.
        if ($messageMode === 'external') {
            AiAgentDispatcher::onHumanExternalSend($chat);
        }

        $chat->load('company:id,timezone');
        $message->setRelation('chat', $chat);

        return response()->json([
            'status'  => 'sent',
            'message' => $message->load('user:id,name,profile_pic'),
        ], 200);
    }

    public function clearMessages(InboxChat $chat)
    {
        $user = Auth::user();

        if (!$user || $user->company_id !== $chat->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::transaction(function () use ($chat) {
            $chat->messages()->delete();
        });

        broadcast(new InboxMessagesCleared($chat))->toOthers();

        return response()->json([
            'message' => 'Chat messages cleared successfully.',
        ]);
    }

    /**
     * Call Evolution API to fetch a media file as base64, decode it and store
     * it in local storage. Returns the stored file path or null on failure.
     * Uses a single attempt with a 30s timeout — no sleeps, no retries —
     * so this never blocks the webhook response for long.
     */
    private function fetchMediaFromEvolution(
        WhatsAppEvoIntegration $integration,
        ?string $instanceName,
        ?array $messageKey,
        string $mediaType,
        int $companyId,
        ?string $originalFileName = null
    ): ?string {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'apikey'       => $integration->api_key,
                    'Content-Type' => 'application/json',
                ])
                ->post(
                    rtrim($integration->base_url, '/') . '/chat/getBase64FromMediaMessage/' . $instanceName,
                    [
                        'message'      => ['key' => $messageKey],
                        'convertToMp4' => false,
                    ]
                );

            if (!$response->successful()) {
                Log::warning("fetchMediaFromEvolution: bad response [{$mediaType}]", [
                    'status'     => $response->status(),
                    'message_id' => $messageKey['id'] ?? 'unknown',
                ]);
                return null;
            }

            $mediaData = $response->json();
            $base64    = $mediaData['base64'] ?? null;
            if (!$base64) return null;

            $mimeType  = $mediaData['mimetype'] ?? 'application/octet-stream';
            $fileName  = $mediaData['fileName'] ?? $originalFileName ?? null;
            $extension = $fileName
                ? pathinfo($fileName, PATHINFO_EXTENSION)
                : self::extensionFromMime($mimeType);

            $folder     = match($mediaType) {
                'image'    => 'whatsapp/images/' . $companyId,
                'audio'    => 'whatsapp/audio/' . $companyId,
                'document' => 'whatsapp/documents/' . $companyId,
                'sticker'  => 'whatsapp/stickers/' . $companyId,
                'video'    => 'whatsapp/videos/' . $companyId,
                'product'  => 'whatsapp/products/' . $companyId,
                default    => 'whatsapp/files/' . $companyId,
            };
            $storedName = 'whatsapp_' . time() . '_' . uniqid() . '.' . $extension;

            Storage::disk('local')->put($folder . '/' . $storedName, base64_decode($base64));

            // Inbound media is the largest storage consumer in practice: it
            // arrives unattended, per message, for as long as a chat runs.
            CompanyStorage::record(
                $companyId,
                $folder . '/' . $storedName,
                CompanyFile::CATEGORY_WHATSAPP_MEDIA,
                mime: $mimeType,
            );

            return $folder . '/' . $storedName;

        } catch (\Exception $e) {
            Log::warning("fetchMediaFromEvolution: exception [{$mediaType}]", [
                'error'      => $e->getMessage(),
                'message_id' => $messageKey['id'] ?? 'unknown',
            ]);
            return null;
        }
    }

    /**
     * Everything the product card needs, from the raw productMessage node.
     *
     * Prices arrive as `priceAmount1000` — the amount times a thousand, so
     * integer arithmetic can carry three decimals — and are stored back in
     * ordinary currency units. Catalog items are not required to have one, and
     * the sample that prompted this had only a currency, so every field here
     * is optional and absent rather than zero when WhatsApp omits it.
     */
    private static function buildProductMetadata(array $productMessage): array
    {
        $product = $productMessage['product'] ?? [];

        $price = isset($product['priceAmount1000'])
            ? (int) $product['priceAmount1000'] / 1000
            : null;

        $salePrice = isset($product['salePriceAmount1000'])
            ? (int) $product['salePriceAmount1000'] / 1000
            : null;

        return array_filter([
            'product_id'         => $product['productId'] ?? null,
            'title'              => $product['title'] ?? null,
            'description'        => $product['description'] ?: null,
            'currency'           => $product['currencyCode'] ?? null,
            'price'              => $price,
            'sale_price'         => $salePrice,
            'image_count'        => $product['productImageCount'] ?? null,
            'retailer_id'        => $product['retailerId'] ?? null,
            'url'                => $product['url'] ?? null,
            'business_owner_jid' => $productMessage['businessOwnerJid'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Store the thumbnail WhatsApp already put in the payload.
     *
     * The fallback for a product image Evolution could not hand over. Catalog
     * media is encrypted like any other attachment, but it belongs to the
     * business's catalog rather than the conversation, and Evolution does not
     * always resolve it — leaving a product card with no picture, which is
     * most of what a product card is.
     *
     * `jpegThumbnail` is a complete JPEG delivered inline as a byte map, so
     * this costs no network call and cannot fail the way a fetch can. Low
     * resolution by nature; it is a fallback, not a preference.
     */
    private function storeProductThumbnail(?array $thumbnailBytes, int $companyId): ?string
    {
        if (empty($thumbnailBytes)) {
            return null;
        }

        try {
            // Keys arrive as "0", "1", "2"… — ordered by JSON convention, but
            // sorted numerically here rather than trusting the order they were
            // decoded in, since one swapped byte is a corrupt image.
            $bytes = $thumbnailBytes;
            ksort($bytes, SORT_NUMERIC);

            $binary = pack('C*', ...array_map('intval', array_values($bytes)));

            $folder     = 'whatsapp/products/' . $companyId;
            $storedName = 'whatsapp_product_' . time() . '_' . uniqid() . '.jpg';

            Storage::disk('local')->put($folder . '/' . $storedName, $binary);

            CompanyStorage::record(
                $companyId,
                $folder . '/' . $storedName,
                CompanyFile::CATEGORY_WHATSAPP_MEDIA,
                mime: 'image/jpeg',
            );

            return $folder . '/' . $storedName;
        } catch (\Throwable $e) {
            Log::warning('storeProductThumbnail failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build a lightweight preview of a quoted/replied-to message from the raw
     * `contextInfo.quotedMessage` payload, used to render the reply banner
     * even when the original message isn't stored locally.
     */
    private function buildQuotedPreview(array $quotedMessage, ?array $contextInfo): array
    {
        $type = 'text';
        $text = null;

        if (isset($quotedMessage['conversation'])) {
            $type = 'text';
            $text = $quotedMessage['conversation'];
        } elseif (isset($quotedMessage['extendedTextMessage']['text'])) {
            $type = 'text';
            $text = $quotedMessage['extendedTextMessage']['text'];
        } elseif (isset($quotedMessage['imageMessage'])) {
            $type = 'image';
            $text = $quotedMessage['imageMessage']['caption'] ?? null;
        } elseif (isset($quotedMessage['videoMessage'])) {
            $type = 'video';
            $text = $quotedMessage['videoMessage']['caption'] ?? null;
        } elseif (isset($quotedMessage['audioMessage'])) {
            $type = 'audio';
        } elseif (isset($quotedMessage['stickerMessage'])) {
            $type = 'sticker';
        } elseif (isset($quotedMessage['documentMessage'])) {
            $type = 'document';
            $text = $quotedMessage['documentMessage']['fileName'] ?? $quotedMessage['documentMessage']['title'] ?? null;
        } elseif (isset($quotedMessage['locationMessage'])) {
            $type = 'location';
        } elseif (isset($quotedMessage['contactMessage'])) {
            $type = 'contact';
            $text = $quotedMessage['contactMessage']['displayName'] ?? null;
        } elseif (isset($quotedMessage['productMessage'])) {
            $type = 'product';
            $text = $quotedMessage['productMessage']['product']['title'] ?? null;
        }

        return [
            'type'        => $type,
            'text'        => $text,
            'participant' => $contextInfo['participant'] ?? null,
        ];
    }

    /**
     * Apply a WhatsApp emoji reaction to the message it targets.
     * Reactions live on the target message's `reactions` column, keyed by
     * who reacted ('contact' or 'agent'). An empty `text` means the reactor
     * removed their reaction.
     */
    private function handleReactionMessage(InboxChat $chat, array $payload, array $messageData): void
    {
        $reaction = $messageData['reactionMessage'] ?? null;
        $targetId = $reaction['key']['id'] ?? null;

        if (!$targetId) {
            return;
        }

        $targetMessage = InboxMessage::where('inbox_chat_id', $chat->id)
            ->where('external_message_id', $targetId)
            ->first();

        if (!$targetMessage) {
            return;
        }

        $reactorKey = ($payload['data']['key']['fromMe'] ?? false) ? 'agent' : 'contact';
        $emoji = $reaction['text'] ?? '';

        $reactions = $targetMessage->reactions ?? [];

        if ($emoji === '') {
            unset($reactions[$reactorKey]);
        } else {
            $reactions[$reactorKey] = [
                'emoji'     => $emoji,
                'timestamp' => $reaction['senderTimestampMs'] ?? null,
            ];
        }

        $targetMessage->update(['reactions' => $reactions]);

        broadcast(new InboxMessageUpdated($targetMessage))->toOthers();
    }

    /**
     * Flag the targeted message as edited and try to recover the new text.
     * WhatsApp delivers edits as end-to-end encrypted `secretEncryptedMessage`
     * payloads, so we can't decrypt the new content ourselves - but Evolution
     * (via Baileys) already holds the message secret from the original message
     * and decrypts edits internally, updating its own stored copy. So we just
     * ask Evolution for the current state of the target message via
     * `findMessages`. If that doesn't surface the new text (e.g. Evolution
     * never had the message), we fall back to flagging the edit only.
     */
    private function handleMessageEdit(InboxChat $chat, array $payload, array $messageData): void
    {
        $secret = $messageData['secretEncryptedMessage'] ?? null;

        if (!$secret || ($secret['secretEncType'] ?? null) !== 'MESSAGE_EDIT') {
            return;
        }

        $targetId = $secret['targetMessageKey']['id'] ?? null;

        if (!$targetId) {
            return;
        }

        $targetMessage = InboxMessage::where('inbox_chat_id', $chat->id)
            ->where('external_message_id', $targetId)
            ->first();

        if (!$targetMessage) {
            return;
        }

        $updates = [];

        if (!$targetMessage->is_edited) {
            $updates['is_edited'] = true;
        }

        $instanceName = $payload['instance'] ?? null;
        $integration  = WhatsAppEvoIntegration::where('company_id', $chat->company_id)->first();

        if ($instanceName && $integration) {
            $newText = $this->fetchEditedTextFromEvolution($integration, $instanceName, $targetId);

            if ($newText !== null && $newText !== $targetMessage->text) {
                $updates['text'] = $newText;
            }
        }

        if (empty($updates)) {
            return;
        }

        $targetMessage->update($updates);

        broadcast(new InboxMessageUpdated($targetMessage))->toOthers();
    }

    /**
     * Fetch the current text of a message from Evolution's own stored copy
     * via `findMessages`. For real edits, Evolution/Baileys decrypts the
     * `MESSAGE_EDIT` payload internally and updates this stored copy, so its
     * `message.conversation` / `extendedTextMessage.text` reflects the
     * post-edit content.
     */
    private function fetchEditedTextFromEvolution(
        WhatsAppEvoIntegration $integration,
        string $instanceName,
        string $messageId
    ): ?string {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'apikey'       => $integration->api_key,
                    'Content-Type' => 'application/json',
                ])
                ->post(
                    rtrim($integration->base_url, '/') . '/chat/findMessages/' . $instanceName,
                    ['where' => ['key' => ['id' => $messageId]]]
                );

            if (!$response->successful()) {
                return null;
            }

            $record = $response->json('messages.records.0');

            if (!$record) {
                return null;
            }

            $message = $record['message'] ?? [];

            return $message['conversation']
                ?? $message['extendedTextMessage']['text']
                ?? null;

        } catch (\Exception $e) {
            Log::warning('fetchEditedTextFromEvolution: exception', [
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Maps Evolution/Baileys ack statuses to our internal message statuses.
     */
    private const MESSAGE_STATUS_MAP = [
        'ERROR'        => 'failed',
        'PENDING'      => 'pending',
        'SERVER_ACK'   => 'sent',
        'DELIVERY_ACK' => 'delivered',
        'READ'         => 'read',
        'PLAYED'       => 'read',
    ];

    /**
     * Ordering of internal message statuses, used to ignore out-of-order
     * webhooks that would regress a message's status (e.g. a late
     * DELIVERY_ACK arriving after a READ).
     */
    private const MESSAGE_STATUS_RANK = [
        'failed'    => -1,
        'pending'   => 0,
        'sent'      => 1,
        'delivered' => 2,
        'read'      => 3,
    ];

    /**
     * Handle a `messages.update` webhook: updates the delivery/read status
     * of an outgoing message previously sent from the CRM.
     */
    private function handleMessageStatusUpdate(array $payload): \Illuminate\Http\JsonResponse
    {
        $instance = WhatsAppInstance::where('instance_name', $payload['instance'] ?? null)->first();

        if (!$instance) {
            return response()->json(['message' => 'Instance not found'], 404);
        }

        $data  = $payload['data'] ?? [];
        $keyId = $data['keyId'] ?? null;

        $newStatus = self::MESSAGE_STATUS_MAP[$data['status'] ?? ''] ?? null;

        if (!$keyId || !$newStatus) {
            return response()->json(['status' => 'ok'], 200);
        }

        $message = InboxMessage::where('external_message_id', $keyId)
            ->where('direction', 'outgoing')
            ->whereHas('chat', function ($q) use ($instance) {
                $q->where('channelable_type', WhatsAppInstance::class)
                    ->where('channelable_id', $instance->id);
            })
            ->first();

        if (!$message) {
            return response()->json(['status' => 'ok'], 200);
        }

        $currentRank = self::MESSAGE_STATUS_RANK[$message->status] ?? -1;
        $newRank     = self::MESSAGE_STATUS_RANK[$newStatus];

        if ($newRank <= $currentRank) {
            return response()->json(['status' => 'ok'], 200);
        }

        $message->update(['status' => $newStatus]);

        broadcast(new InboxMessageUpdated($message))->toOthers();

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Fetch the WhatsApp profile picture for a phone number via Evolution API,
     * download the image from the returned URL, and store it locally.
     * Returns the stored file path or null if unavailable.
     */
    private function fetchAndStoreProfilePicture(
        WhatsAppEvoIntegration $integration,
        ?string $instanceName,
        string $phoneNumber,
        int $companyId
    ): ?string {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'apikey'       => $integration->api_key,
                    'Content-Type' => 'application/json',
                ])
                ->post(
                    rtrim($integration->base_url, '/') . '/chat/fetchProfilePictureUrl/' . $instanceName,
                    ['number' => $phoneNumber]
                );

            if (!$response->successful()) {
                return null;
            }

            $pictureUrl = $response->json()['profilePictureUrl'] ?? null;
            if (!$pictureUrl) {
                return null;
            }

            // Download the image from the public URL
            $imageResponse = Http::timeout(15)->get($pictureUrl);
            if (!$imageResponse->successful()) {
                return null;
            }

            $contentType = $imageResponse->header('Content-Type') ?? 'image/jpeg';
            $extension   = str_contains($contentType, 'png') ? 'png' : 'jpg';
            $folder      = 'whatsapp/profile_pics/' . $companyId;
            $fileName    = 'profile_' . $phoneNumber . '_' . time() . '.' . $extension;

            Storage::disk('local')->put($folder . '/' . $fileName, $imageResponse->body());

            CompanyStorage::record(
                $companyId,
                $folder . '/' . $fileName,
                CompanyFile::CATEGORY_PROFILE_PICTURE,
                mime: $contentType,
            );

            return $folder . '/' . $fileName;

        } catch (\Exception $e) {
            Log::warning('fetchAndStoreProfilePicture: failed', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Evolution API (protobuf Long fields like fileLength) now serializes
     * 64-bit values as {"low": ..., "high": ..., "unsigned": ...} instead of
     * a plain number. Collapse that back into a plain int so it can be
     * stored in an integer column; passes plain numbers through unchanged.
     */
    private static function resolveLongValue($value): ?int
    {
        if (is_array($value) && array_key_exists('low', $value)) {
            $low  = (int) ($value['low'] ?? 0);
            $high = (int) ($value['high'] ?? 0);

            if (($value['unsigned'] ?? false) && $low < 0) {
                $low += 4294967296;
            }

            return $high !== 0 ? ($high * 4294967296 + $low) : $low;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public static function extensionFromMime(string $mimeType): string
    {
        return match(true) {
            str_contains($mimeType, 'pdf')        => 'pdf',
            str_contains($mimeType, 'msword')     => 'doc',
            str_contains($mimeType, 'wordprocessingml') => 'docx',
            str_contains($mimeType, 'spreadsheetml')   => 'xlsx',
            str_contains($mimeType, 'ms-excel')        => 'xls',
            str_contains($mimeType, 'presentationml')  => 'pptx',
            str_contains($mimeType, 'zip')        => 'zip',
            str_contains($mimeType, 'text/plain') => 'txt',
            str_contains($mimeType, 'text/csv')   => 'csv',
            str_contains($mimeType, 'image/jpeg') => 'jpg',
            str_contains($mimeType, 'image/png')  => 'png',
            str_contains($mimeType, 'image/gif')  => 'gif',
            str_contains($mimeType, 'image/webp') => 'webp',
            str_contains($mimeType, 'image/heic') => 'heic',
            str_contains($mimeType, 'audio/mpeg') => 'mp3',
            str_contains($mimeType, 'audio/mp4')  => 'm4a',
            str_contains($mimeType, 'audio/aac')  => 'aac',
            str_contains($mimeType, 'audio/ogg')  => 'ogg',
            str_contains($mimeType, 'audio/wav')  => 'wav',
            str_contains($mimeType, 'audio/x-wav') => 'wav',
            str_contains($mimeType, 'video/mp4')  => 'mp4',
            default                               => 'bin',
        };
    }

}
