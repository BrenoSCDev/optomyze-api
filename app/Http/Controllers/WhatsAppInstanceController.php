<?php

namespace App\Http\Controllers;

use App\Models\InboxChat;
use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsAppInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class WhatsAppInstanceController extends Controller
{
    public function index()
    {
        $companyId = Auth::user()->company_id;

        $instances = WhatsAppInstance::where('company_id', $companyId)
            ->select('id', 'instance_name', 'status')
            ->get();

        return response()->json($instances);
    }

    public function createInstance(Request $request)
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        $integration = WhatsAppEvoIntegration::where('company_id', $companyId)
            ->first();

        // Generate instance name
        $instanceName = $request->name . '_' . Str::random(8);

        try {
            // Create instance in Evolution API FIRST
            $response = Http::withHeaders([
                'apikey' => $integration->api_key,
            ])->post(
                rtrim($integration->base_url, '/') . '/instance/create',
                [
                    'instanceName' => $instanceName,
                    'integration'  => 'WHATSAPP-BAILEYS',
                    'webhook' => [
                        'url'    => rtrim(config('app.url'), '/') . '/api/whatsapp-evo-chat',
                        'events' => ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'MESSAGES_DELETE'],
                    ],
                ]
            );

            // If Evolution failed, do NOT create locally
            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Failed to create instance in Evolution API',
                    'error'   => $response->json() ?? $response->body(),
                ], 422);
            }

            // create instance in CRM
            $instance = WhatsAppInstance::create([
                'company_id' => $companyId,
                'whats_app_evo_integration_id' => $integration->id,
                'instance_name' => $instanceName,
                'status' => 'created',
            ]);

            return response()->json($instance, 201);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create WhatsApp instance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteInstance($instanceId)
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        // Fetch instance ensuring company ownership
        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        // Fetch related integration
        $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        try {
            // Delete instance in Evolution API FIRST
            $response = Http::withHeaders([
                'apikey' => $integration->api_key,
            ])->delete(
                rtrim($integration->base_url, '/') . '/instance/delete/' . $instance->instance_name
            );

            // If Evolution failed, do NOT delete locally
            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Failed to delete instance in Evolution API',
                    'error'   => $response->json() ?? $response->body(),
                ], 422);
            }

            // Only now delete locally
            $instance->delete();

            return response()->json([
                'message' => 'WhatsApp instance deleted successfully',
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to delete WhatsApp instance',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function sendMessage(Request $request, $instanceId)
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        // Basic validation (keep it simple)
        $request->validate([
            'number' => 'required|string',
            'text'   => 'required|string',
        ]);

        // Fetch instance (company-safe)
        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        // Fetch integration
        $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        try {
            // Send message via Evolution API
            $response = Http::withHeaders([
                'apikey' => $integration->api_key,
            ])->post(
                rtrim($integration->base_url, '/') . '/message/sendText/' . $instance->instance_name,
                [
                    'number' => $request->number,
                    'text'   => $request->text,
                ]
            );

            // If Evolution failed
            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Failed to send message via Evolution API',
                    'error'   => $response->json() ?? $response->body(),
                ], 422);
            }

            // Update instance status to connected
            $instance->update(['status' => 'connected']);

            // Success → return Evolution response with updated status
            return response()->json([
                'data'   => $response->json(),
                'status' => 'connected',
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send WhatsApp message',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function generateQrCode($instanceId)
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        // Fetch instance (company-safe)
        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        // Fetch integration
        $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        try {
            // Call Evolution API to generate QR Code
            $response = Http::withHeaders([
                'apikey' => $integration->api_key,
            ])->get(
                rtrim($integration->base_url, '/') . '/instance/connect/' . $instance->instance_name
            );

            // If Evolution failed
            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Failed to generate QR Code from Evolution API',
                    'error'   => $response->json() ?? $response->body(),
                ], 422);
            }

            // Return Evolution response directly to frontend
            return response()->json($response->json(), 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to generate WhatsApp QR Code',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Info needed to render the disconnect confirmation modal: how many
     * chats are tied to this instance, and which other instances (any
     * status) in the company could receive them.
     */
    public function disconnectInfo($instanceId)
    {
        $companyId = Auth::user()->company_id;

        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $chatCount = InboxChat::where('channelable_type', WhatsAppInstance::class)
            ->where('channelable_id', $instance->id)
            ->count();

        $otherInstances = WhatsAppInstance::where('company_id', $companyId)
            ->where('id', '!=', $instance->id)
            ->get(['id', 'instance_name', 'label', 'status'])
            ->map(fn ($i) => [
                'id' => $i->id,
                'label' => $i->label ?: $i->instance_name,
                'status' => $i->status,
            ]);

        return response()->json([
            'chat_count' => $chatCount,
            'other_instances' => $otherInstances,
        ], 200);
    }

    public function disconnectInstance(Request $request, $instanceId)
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        $data = $request->validate([
            'reassign_to_instance_id' => 'nullable|integer|exists:whats_app_instances,id',
        ]);

        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        try {
            $response = Http::withHeaders([
                'apikey' => $integration->api_key,
            ])->delete(
                rtrim($integration->base_url, '/') . '/instance/logout/' . $instance->instance_name
            );

            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Failed to disconnect instance in Evolution API',
                    'error'   => $response->json() ?? $response->body(),
                ], 422);
            }

            $instance->update(['status' => 'disconnected']);

            $reassignment = null;

            if (!empty($data['reassign_to_instance_id'])) {
                $targetInstance = WhatsAppInstance::where('id', $data['reassign_to_instance_id'])
                    ->where('company_id', $companyId)
                    ->firstOrFail();

                $reassignment = $this->reassignChats($instance, $targetInstance);
            }

            return response()->json([
                'message' => 'WhatsApp instance disconnected successfully',
                'status'  => 'disconnected',
                'reassignment' => $reassignment,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to disconnect WhatsApp instance',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Re-point every chat from the old instance to the new one so the
     * lead's history and any open conversation continue uninterrupted once
     * the phone number is reconnected under the new instance. Chats that
     * would collide with an existing chat already on the target instance
     * (same external_chat_id) are skipped rather than failing the whole
     * batch, and logged for manual follow-up.
     */
    private function reassignChats(WhatsAppInstance $from, WhatsAppInstance $to): array
    {
        $chats = InboxChat::where('channelable_type', WhatsAppInstance::class)
            ->where('channelable_id', $from->id)
            ->get();

        $moved = 0;
        $skipped = 0;

        foreach ($chats as $chat) {
            $collision = InboxChat::where('channelable_type', WhatsAppInstance::class)
                ->where('channelable_id', $to->id)
                ->where('channel', $chat->channel)
                ->where('external_chat_id', $chat->external_chat_id)
                ->exists();

            if ($collision) {
                $skipped++;
                Log::warning('WhatsApp instance reassignment: skipped chat due to collision on target instance', [
                    'chat_id' => $chat->id,
                    'from_instance_id' => $from->id,
                    'to_instance_id' => $to->id,
                ]);
                continue;
            }

            $chat->update(['channelable_id' => $to->id]);
            $moved++;
        }

        return ['moved' => $moved, 'skipped' => $skipped];
    }

    /**
     * Attach or update funnel for a WhatsApp instance
     */
    public function updateFunnel(Request $request, $instanceId)
    {
        $data = $request->validate([
            'funnel_id' => ['nullable', 'exists:funnels,id'],
        ]);

        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $instance->update([
            'funnel_id' => $data['funnel_id'],
        ]);

        return response()->json([
            'message' => 'Funnel linked to WhatsApp instance successfully.',
            'data' => $instance->fresh(),
        ]);
    }
}
