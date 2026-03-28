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

            // Handle different message types
            if ($messageType === 'imageMessage') {
                $imageMessage = $messageData['imageMessage'] ?? null;
                
                if ($imageMessage) {
                    $mediaPath = null;
                    $integration = WhatsAppEvoIntegration::where('company_id', $companyId)->first();
                    
                    if ($integration) {
                        $evolutionApiUrl = rtrim($integration->base_url, '/');
                        $evolutionApiKey = $integration->api_key;
                        $instanceName = $payload['instance'] ?? null;
                        $messageKey = $payload['data']['key'] ?? null;
                        
                        // Retry up to 3 times with exponential backoff
                        $maxRetries = 3;
                        $retryDelay = 2; // Initial delay in seconds
                        
                        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                            try {
                                $response = Http::timeout(45)
                                    ->retry(2, 1000)
                                    ->withHeaders([
                                        'apikey' => $evolutionApiKey,
                                        'Content-Type' => 'application/json',
                                    ])
                                    ->post("{$evolutionApiUrl}/chat/getBase64FromMediaMessage/{$instanceName}", [
                                        'message' => ['key' => $messageKey]
                                    ]);
                                
                                if ($response->successful()) {
                                    $mediaData = $response->json();
                                    $base64Image = $mediaData['base64'] ?? null;
                                    
                                    if ($base64Image) {
                                        $mimeType = $mediaData['mimetype'] ?? 'image/jpeg';
                                        $extension = match(true) {
                                            str_contains($mimeType, 'png') => 'png',
                                            str_contains($mimeType, 'webp') => 'webp',
                                            str_contains($mimeType, 'gif') => 'gif',
                                            default => 'jpg',
                                        };
                                        
                                        $filename = 'whatsapp_' . time() . '_' . uniqid() . '.' . $extension;
                                        $storagePath = 'whatsapp/images/' . $companyId;
                                        $imageData = base64_decode($base64Image);
                                        
                                        Storage::disk('public')->put($storagePath . '/' . $filename, $imageData);
                                        $mediaPath = $storagePath . '/' . $filename;
                                        
                                        break; // Success - exit retry loop
                                    }
                                }
                            } catch (\Exception $e) {
                                Log::warning("Image download attempt {$attempt} failed", [
                                    'error' => $e->getMessage(),
                                    'message_id' => $messageKey['id'] ?? 'unknown',
                                ]);
                                
                                if ($attempt < $maxRetries) {
                                    sleep($retryDelay);
                                    $retryDelay *= 2; // 2s, 4s, 8s
                                }
                            }
                        }
                    }
                    
                    $messageValues['type'] = 'image';
                    $messageValues['media_url'] = $mediaPath;
                    $messageValues['media_mime'] = $imageMessage['mimetype'] ?? 'image/jpeg';
                    $messageValues['media_size'] = $imageMessage['fileLength'] ?? null;
                    $messageValues['text'] = $imageMessage['caption'] ?? null;
                }
            } elseif ($messageType === 'audioMessage') {
                
                // Extract audio data
                $audioMessage = $messageData['audioMessage'] ?? null;
                
                if ($audioMessage) {
                    // Store the base64 audio
                    $base64Audio = $payload['data']['message']['base64'] ?? null;
                    $mediaPath = null;
                    
                    if ($base64Audio) {
                        // Determine file extension based on mimetype
                        $mimeType = $audioMessage['mimetype'] ?? 'audio/ogg';
                        $extension = 'ogg'; // Default for WhatsApp voice notes
                        
                        // If it's opus codec, use .ogg extension
                        if (str_contains($mimeType, 'opus')) {
                            $extension = 'ogg';
                        } elseif (str_contains($mimeType, 'mp4')) {
                            $extension = 'm4a';
                        } elseif (str_contains($mimeType, 'mpeg')) {
                            $extension = 'mp3';
                        }
                        
                        // Generate unique filename
                        $filename = 'whatsapp_' . time() . '_' . uniqid() . '.' . $extension;
                        
                        // Define storage path
                        $storagePath = 'whatsapp/audio/' . $companyId;
                        
                        // Decode and store the audio
                        $audioData = base64_decode($base64Audio);
                        
                        // Store in Laravel storage
                        Storage::disk('public')->put($storagePath . '/' . $filename, $audioData);
                        
                        // Get the full path
                        $mediaPath = $storagePath . '/' . $filename;
                    }
                        
                    $messageValues['type'] = 'audio';
                    $messageValues['media_url'] = $mediaPath;
                    $messageValues['media_mime'] = $audioMessage['mimetype'] ?? 'audio/ogg';
                    $messageValues['media_size'] = $audioMessage['fileLength'] ?? null;
                    $messageValues['text'] = null;
                }

            } elseif ($messageType === 'locationMessage') {

                $locationMessage = $messageData['locationMessage'] ?? null;

                if ($locationMessage) {
                    $messageValues['type']      = 'location';
                    $messageValues['latitude']  = $locationMessage['degreesLatitude'] ?? null;
                    $messageValues['longitude'] = $locationMessage['degreesLongitude'] ?? null;
                    $messageValues['text']      = null;
                    $messageValues['media_url'] = null;
                    $messageValues['media_mime'] = null;
                    $messageValues['media_size'] = null;
                }

            } elseif ($messageType === 'documentMessage') {

                $documentMessage = $messageData['documentMessage'] ?? null;

                if ($documentMessage) {
                    $mediaPath   = null;
                    $integration = WhatsAppEvoIntegration::where('company_id', $companyId)->first();

                    if ($integration) {
                        $evolutionApiUrl = rtrim($integration->base_url, '/');
                        $evolutionApiKey = $integration->api_key;
                        $instanceName    = $payload['instance'] ?? null;
                        $messageKey      = $payload['data']['key'] ?? null;

                        $maxRetries = 3;
                        $retryDelay = 2;

                        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                            try {
                                $response = Http::timeout(45)
                                    ->retry(2, 1000)
                                    ->withHeaders([
                                        'apikey'       => $evolutionApiKey,
                                        'Content-Type' => 'application/json',
                                    ])
                                    ->post("{$evolutionApiUrl}/chat/getBase64FromMediaMessage/{$instanceName}", [
                                        'message' => ['key' => $messageKey],
                                    ]);

                                if ($response->successful()) {
                                    $mediaData      = $response->json();
                                    $base64Document = $mediaData['base64'] ?? null;

                                    if ($base64Document) {
                                        $mimeType  = $mediaData['mimetype'] ?? $documentMessage['mimetype'] ?? 'application/octet-stream';
                                        $fileName  = $documentMessage['fileName'] ?? null;
                                        $extension = $fileName
                                            ? pathinfo($fileName, PATHINFO_EXTENSION)
                                            : $this->extensionFromMime($mimeType);

                                        $storedName  = 'whatsapp_' . time() . '_' . uniqid() . '.' . $extension;
                                        $storagePath = 'whatsapp/documents/' . $companyId;

                                        Storage::disk('public')->put(
                                            $storagePath . '/' . $storedName,
                                            base64_decode($base64Document)
                                        );

                                        $mediaPath = $storagePath . '/' . $storedName;
                                        break;
                                    }
                                }
                            } catch (\Exception $e) {
                                Log::warning("Document download attempt {$attempt} failed", [
                                    'error'      => $e->getMessage(),
                                    'message_id' => $messageKey['id'] ?? 'unknown',
                                ]);

                                if ($attempt < $maxRetries) {
                                    sleep($retryDelay);
                                    $retryDelay *= 2;
                                }
                            }
                        }
                    }

                    $messageValues['type']       = 'document';
                    $messageValues['media_url']  = $mediaPath;
                    $messageValues['media_mime'] = $documentMessage['mimetype'] ?? 'application/octet-stream';
                    $messageValues['media_size'] = $documentMessage['fileLength'] ?? null;
                    $messageValues['text']       = $documentMessage['fileName'] ?? $documentMessage['title'] ?? null;
                    $messageValues['latitude']   = null;
                    $messageValues['longitude']  = null;
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

            // Create the message
            $message = WhatsappEvoMessage::firstOrCreate(
                $messageAttributes,
                $messageValues
            );

            // Update chat's last message timestamp
            $chat->update([
                'last_message_at' => now(),
            ]);

            // 🔥 7️⃣ BROADCAST REAL-TIME UPDATE (OPTIMIZED)
            // Only broadcast the new message, not the entire chat
            // The event will load necessary relationships efficiently
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

        $chats = WhatsappEvoChat::query()
            ->where('company_id', $companyId)
            ->with([
                // 🔹 Company (needed for timezone resolution on messages)
                'company:id,timezone',

                // 🔹 Instance info
                'instance:id,instance_name,company_id',

                // 🔹 Lead + Funnel + Stage
                'lead:id,company_id,first_name,last_name,phone,funnel_id,stage_id',
                'lead.funnel:id,name',
                'lead.stage:id,name',

                // 🔹 Load only last 20 messages per chat
                'messages' => function ($query) {
                    $query->orderBy('sent_at')->with('user:id,name');
                }
            ])
            ->orderByDesc('last_message_at')
            ->get();

        // Stamp each message with its parent chat so timezone resolves correctly
        $chats->each(function ($chat) {
            $chat->messages->each(fn($msg) => $msg->setRelation('chat', $chat));
        });

        return response()->json([
            'data' => $chats
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
