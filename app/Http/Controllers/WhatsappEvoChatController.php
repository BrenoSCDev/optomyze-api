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
use App\Events\WhatsAppChatUpdated;
use App\Events\WhatsAppMessageReceived;

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
        $remoteJid = $payload['data']['key']['remoteJid'] ?? null;

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

        if (!$lead) {
            $lead = Lead::create([
                'company_id' => $companyId,
                'first_name' => $firstName ?: $phoneNumber,
                'last_name'  => $lastName,
                'phone'      => $phoneNumber,
                'funnel_id'  => $funnel->id,
                'stage_id'   => $entryStage->id,
            ]);
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

        // 6️⃣ Store message
        if (
            isset($payload['data']['message']) &&
            isset($payload['data']['messageType'])
        ) {
            $messageData = $payload['data']['message'];
            $messageType = $payload['data']['messageType'];
            $waMessageId = Str::random(20);

            // Prepare common message attributes
            $messageAttributes = [
                'whatsapp_evo_chat_id' => $chat->id,
                'wa_message_id'        => $waMessageId,
            ];

            $messageValues = [
                'direction' => 'incoming',
                'status'    => 'delivered',
                'sent_at'   => now(),
            ];

            // Handle different message types
            if ($messageType === 'imageMessage') {
                
                // Extract image data
                $imageMessage = $messageData['imageMessage'] ?? null;
                
                if ($imageMessage) {
                    // Store the base64 image
                    $base64Image = $payload['data']['message']['base64'] ?? null;
                    $mediaPath = null;
                    
                    if ($base64Image) {
                        // Generate unique filename
                        $filename = 'whatsapp_' . time() . '_' . uniqid() . '.jpg';
                        
                        // Define storage path
                        $storagePath = 'whatsapp/images/' . $companyId;
                        
                        // Decode and store the image
                        $imageData = base64_decode($base64Image);
                        
                        // Store in Laravel storage
                        Storage::disk('public')->put($storagePath . '/' . $filename, $imageData);
                        
                        // Get the full path
                        $mediaPath = $storagePath . '/' . $filename;
                    }
                        
                    $messageValues['type'] = 'image';
                    $messageValues['media_url'] = $mediaPath;
                    $messageValues['media_mime'] = $imageMessage['mimetype'] ?? 'image/jpeg';
                    $messageValues['media_size'] = $imageMessage['fileLength'] ?? null;
                    $messageValues['text'] = null;
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
                // 🔹 Instance info
                'instance:id,instance_name,company_id',

                // 🔹 Lead + Funnel + Stage
                'lead:id,company_id,first_name,last_name,phone,funnel_id,stage_id',
                'lead.funnel:id,name',
                'lead.stage:id,name',

                // 🔹 Load only last 20 messages per chat
                'messages' => function ($query) {
                    $query
                        ->orderBy('sent_at');
                }
            ])
            ->orderByDesc('last_message_at')
            ->get();

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

        // Basic validation
        $request->validate([
            'lead_id' => 'required|integer',
            'text'    => 'required|string',
        ]);

        // 1️⃣ Fetch instance (company-safe)
        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        // 2️⃣ Fetch integration
        $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        // 3️⃣ Fetch lead
        $lead = Lead::where('id', $request->lead_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $phoneNumber = $lead->phone;
        $remoteJid   = $phoneNumber . '@s.whatsapp.net';

        // 4️⃣ Find or create chat
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
            // 5️⃣ Send message via Evolution API
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

            // 6️⃣ Store outgoing message
            $message = WhatsappEvoMessage::create([
                'whatsapp_evo_chat_id' => $chat->id,
                'wa_message_id'        => $response->json()['key']['id'] ?? Str::uuid(),
                'direction'            => 'outgoing',
                'type'                 => 'text',
                'text'                 => $request->text,
                'status'               => 'sent',
                'sent_at'              => now(),
            ]);

            // 7️⃣ Update chat activity
            $chat->update([
                'last_message_at' => now(),
            ]);

            return response()->json([
                'status'  => 'sent',
                'message' => $message,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send WhatsApp message',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

}
