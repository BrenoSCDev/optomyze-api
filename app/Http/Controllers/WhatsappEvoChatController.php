<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\WhatsappEvoChat;
use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsappEvoMessage;
use App\Models\WhatsAppInstance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Events\ChatMessagesCleared;
use App\Events\WhatsAppChatUpdated;
use App\Events\WhatsAppMessageReceived;
use App\Services\LeadQueueAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsappEvoChatController extends Controller
{
    public function handleEvolutionWebhook(Request $request)
    {
        $payload = $request->all();

        // 1️⃣ Identify instance via Evolution instance name
        $instance = WhatsAppInstance::where('instance_name', $payload['instance'] ?? null)->first();

        if (!$instance) {
            return response()->json(['message' => 'Instance not found'], 404);
        }

        $companyId = $instance->company_id;

        // 2️⃣ Resolve sender info
        // For incoming messages senderPn holds the contact JID.
        // For outgoing messages sent from the phone (fromMe=true) senderPn is null;
        // the recipient JID is in key.remoteJid instead.
        $remoteJid = $payload['data']['key']['senderPn']
            ?? $payload['data']['key']['remoteJid']
            ?? null;

        if (!$remoteJid) {
            return response()->json(['message' => 'remoteJid not found'], 422);
        }

        $phoneNumber = str_replace('@s.whatsapp.net', '', $remoteJid);
        $pushName = trim($payload['data']['pushName'] ?? '');

        // 🔹 Split pushName into first and last name
        $firstName = null;
        $lastName  = null;

        if ($pushName !== '') {
            $nameParts = explode(' ', $pushName, 2);
            $firstName = $nameParts[0];
            $lastName  = $nameParts[1] ?? null;
        }

        // 3️⃣ Find funnel + entry stage
        $funnel = $instance->funnel;

        if (!$funnel) {
            return response()->json(['message' => 'No funnel associated'], 422);
        }

        $entryStage = $funnel->stages()
            ->where('type', 'entry')
            ->orderBy('order')
            ->first();

        if (!$entryStage) {
            return response()->json(['message' => 'No entry stage found'], 422);
        }

        // 4️⃣ Find or create lead
        $lead = Lead::where('company_id', $companyId)
            ->where('phone', $phoneNumber)
            ->first();

        $leadCreated = false;
        if (!$lead) {
            $lead = Lead::create([
                'company_id'      => $companyId,
                'first_name'      => $firstName ?: $phoneNumber,
                'last_name'       => $lastName,
                'phone'           => $phoneNumber,
                'source_type'     => 'chat_integration',
                'source_platform' => 'whatsapp',
                'funnel_id'       => $funnel->id,
                'stage_id'        => $entryStage->id,
            ]);
            LeadQueueAssignmentService::assign($lead);
            $leadCreated = true;
        }

        // 5️⃣ Find or create WhatsApp chat
        $chat = WhatsappEvoChat::firstOrCreate(
            [
                'company_id'           => $companyId,
                'whatsapp_instance_id' => $instance->id,
                'remote_jid'           => $remoteJid,
            ],
            [
                'lead_id'        => $lead->id,
                'phone_number'   => $phoneNumber,
                'is_group'       => false,
                'last_message_at'=> now(),
            ]
        );

        // 6️⃣ Fire lead transactions for new lead / new chat
        if ($leadCreated) {
            \App\Models\LeadTransaction::createLeadCreation(
                $lead,
                null,
                \App\Models\LeadTransaction::SOURCE_WEBHOOK
            );
        }

        if ($chat->wasRecentlyCreated) {
            \App\Models\LeadTransaction::createWhatsAppChatStarted($lead, $chat, $instance);
        }

        // 7️⃣ Store message
        if (
            isset($payload['data']['message']) &&
            isset($payload['data']['messageType'])
        ) {
            $messageData = $payload['data']['message'];
            $messageType = $payload['data']['messageType'];
            // Use the real Evolution message ID for proper deduplication.
            // This also prevents double-storing messages that were sent from the
            // CRM (already saved with this ID) and re-delivered via webhook.
            $waMessageId = $payload['data']['key']['id'] ?? Str::random(20);

            // Prepare common message attributes
            $messageAttributes = [
                'whatsapp_evo_chat_id' => $chat->id,
                'wa_message_id'        => $waMessageId,
            ];

            $msgDirection = $payload['data']['key']['fromMe'] ?? false ? 'outgoing' : 'incoming';

            $messageValues = [
                'direction'    => $msgDirection,
                'message_mode' => 'external',
                'user_id'      => null,
                'status'       => 'delivered',
                'sent_at'      => now(),
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
                    $messageValues['media_size'] = $imageMessage['fileLength'] ?? null;
                    $messageValues['text']       = $imageMessage['caption'] ?? null;
                    $mediaDownloadType           = 'image';
                }
            } elseif ($messageType === 'audioMessage') {
                $audioMessage = $messageData['audioMessage'] ?? null;
                if ($audioMessage) {
                    $messageValues['type']       = 'audio';
                    $messageValues['media_url']  = null;
                    $messageValues['media_mime'] = $audioMessage['mimetype'] ?? 'audio/ogg';
                    $messageValues['media_size'] = $audioMessage['fileLength'] ?? null;
                    $messageValues['text']       = null;
                    $mediaDownloadType           = 'audio';
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
                    $messageValues['media_size'] = $documentMessage['fileLength'] ?? null;
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

                    // Extract phone from vcard TEL line
                    $contactPhone = null;
                    if (preg_match('/TEL[^:]*:([+\d]+)/', $vcard, $matches)) {
                        $contactPhone = $matches[1];
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

            // Create the message record immediately (media_url may be null for media types)
            $message = WhatsappEvoMessage::firstOrCreate(
                $messageAttributes,
                $messageValues
            );

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
                    if ($mediaPath) {
                        $message->update(['media_url' => $mediaPath]);
                        $message->media_url = $mediaPath;
                    }
                }
            }

            // Update chat's last message timestamp
            $chat->update([
                'last_message_at' => now(),
            ]);

            // Broadcast real-time update
            broadcast(new WhatsAppMessageReceived($message))->toOthers();
        }

        return response()->json(['status' => 'ok'], 200);
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

        $paginator = WhatsappEvoChat::query()
            ->where('company_id', $companyId)
            ->with([
                'company:id,timezone',
                'instance:id,instance_name,company_id',
                'lead:id,company_id,first_name,last_name,phone,funnel_id,stage_id',
                'lead.funnel:id,name',
                'lead.stage:id,name',
                // Only the latest message per chat (for list preview)
                'latestMessage' => fn($q) => $q->with('user:id,name'),
            ])
            ->orderByDesc('last_message_at')
            ->paginate($perPage, ['*'], 'page', $page);

        // Map: expose latestMessage as messages array for frontend compatibility
        $chats = $paginator->getCollection()->map(function ($chat) {
            $latest = $chat->latestMessage;
            if ($latest) {
                $latest->setRelation('chat', $chat);
            }
            $chat->setRelation('messages', $latest ? collect([$latest]) : collect([]));
            unset($chat->latest_message);
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

    public function getChatMessages(Request $request, $chatId)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $chat = WhatsappEvoChat::where('id', $chatId)
            ->where('company_id', $user->company_id)
            ->with('company:id,timezone')
            ->firstOrFail();

        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        $paginator = $chat->messages()
            ->with('user:id,name')
            ->orderBy('sent_at', 'desc') // newest first
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

    public function sendMessageToLead(Request $request, $instanceId)
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
        $remoteJid   = $phoneNumber . '@s.whatsapp.net';

        // 3️⃣ Find or create chat
        $chat = WhatsappEvoChat::firstOrCreate(
            [
                'company_id'           => $companyId,
                'whatsapp_instance_id' => $instance->id,
                'remote_jid'           => $remoteJid,
            ],
            [
                'lead_id'      => $lead->id,
                'phone_number' => $phoneNumber,
                'is_group'     => false,
            ]
        );

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

            // 5️⃣ Store outgoing message
            $message = WhatsappEvoMessage::create([
                'whatsapp_evo_chat_id' => $chat->id,
                'user_id'              => $user->id,
                'wa_message_id'        => $waMessageId,
                'direction'            => 'outgoing',
                'message_mode'         => $messageMode,
                'type'                 => 'text',
                'text'                 => $request->text,
                'status'               => 'sent',
                'sent_at'              => now(),
            ]);

            // 6️⃣ Update chat activity
            $chat->update([
                'last_message_at' => now(),
            ]);

            // 7️⃣ Broadcast real-time update
            broadcast(new WhatsAppMessageReceived($message))->toOthers();

            $chat->load('company:id,timezone');
            $message->setRelation('chat', $chat);

            return response()->json([
                'status'  => 'sent',
                'message' => $message->load('user:id,name'),
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send WhatsApp message',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function sendMediaToLead(Request $request, $instanceId)
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
        $remoteJid   = $phoneNumber . '@s.whatsapp.net';

        // 3️⃣ Find or create chat
        $chat = WhatsappEvoChat::firstOrCreate(
            [
                'company_id'           => $companyId,
                'whatsapp_instance_id' => $instance->id,
                'remote_jid'           => $remoteJid,
            ],
            [
                'lead_id'      => $lead->id,
                'phone_number' => $phoneNumber,
                'is_group'     => false,
            ]
        );

        // 4️⃣ Store file locally
        $uploadedFile = $request->file('file');
        $folder       = $type === 'image' ? "whatsapp/images/{$companyId}" : "whatsapp/audio/{$companyId}";
        $filePath     = $uploadedFile->store($folder, 'local');
        $mime         = $uploadedFile->getMimeType();
        $size         = $uploadedFile->getSize();

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
                    return response()->json([
                        'message' => 'Failed to send media via Evolution API',
                        'error'   => $response->json() ?? $response->body(),
                    ], 422);
                }

                $waMessageId = $response->json()['key']['id'] ?? $waMessageId;
            }

            // 6️⃣ Store outgoing message
            $message = WhatsappEvoMessage::create([
                'whatsapp_evo_chat_id' => $chat->id,
                'user_id'              => $user->id,
                'wa_message_id'        => $waMessageId,
                'direction'            => 'outgoing',
                'message_mode'         => $messageMode,
                'type'                 => $type,
                'media_url'            => $filePath,
                'media_mime'           => $mime,
                'media_size'           => $size,
                'status'               => 'sent',
                'sent_at'              => now(),
            ]);

            // 7️⃣ Update chat activity
            $chat->update(['last_message_at' => now()]);

            // 8️⃣ Broadcast real-time update to other participants
            broadcast(new WhatsAppMessageReceived($message))->toOthers();

            $chat->load('company:id,timezone');
            $message->setRelation('chat', $chat);

            return response()->json([
                'status'  => 'sent',
                'message' => $message->load('user:id,name'),
            ], 200);

        } catch (\Throwable $e) {
            Storage::disk('local')->delete($filePath);
            return response()->json([
                'message' => 'Failed to send WhatsApp media',
                'error'   => $e->getMessage(),
            ], 500);
        }
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

    public function clearMessages(WhatsappEvoChat $chat)
    {
        $user = Auth::user();

        if (!$user || $user->company_id !== $chat->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::transaction(function () use ($chat) {
            $chat->messages()->delete();
        });

        broadcast(new ChatMessagesCleared($chat))->toOthers();

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
                : $this->extensionFromMime($mimeType);

            $folder     = match($mediaType) {
                'image'    => 'whatsapp/images/' . $companyId,
                'audio'    => 'whatsapp/audio/' . $companyId,
                'document' => 'whatsapp/documents/' . $companyId,
                default    => 'whatsapp/files/' . $companyId,
            };
            $storedName = 'whatsapp_' . time() . '_' . uniqid() . '.' . $extension;

            Storage::disk('local')->put($folder . '/' . $storedName, base64_decode($base64));

            return $folder . '/' . $storedName;

        } catch (\Exception $e) {
            Log::warning("fetchMediaFromEvolution: exception [{$mediaType}]", [
                'error'      => $e->getMessage(),
                'message_id' => $messageKey['id'] ?? 'unknown',
            ]);
            return null;
        }
    }

    private function extensionFromMime(string $mimeType): string
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
            default                               => 'bin',
        };
    }

}
