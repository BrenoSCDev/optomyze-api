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
            ->select('id', 'instance_name', 'status', 'profile_name', 'profile_pic_url', 'phone_number')
            ->get();

        return response()->json($instances);
    }

    /**
     * Provision a WhatsApp account.
     *
     * Takes no input at all. The user asked for an account, and everything the
     * old form made them supply — a name, a server URL, an API key — is either
     * generated here or already on the integration, so connecting WhatsApp is
     * one click followed by a QR code.
     */
    public function createInstance(Request $request)
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        if (!WhatsAppEvoIntegration::platformServerConfigured()) {
            Log::error('WhatsApp account requested but no Evolution server is configured for the platform', [
                'company_id' => $companyId,
            ]);

            return response()->json([
                'message' => 'WhatsApp is not available right now. Please contact support.',
            ], 503);
        }

        $integration = WhatsAppEvoIntegration::forCompany($companyId);

        $instanceName = WhatsAppInstance::generateIdentifier();

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
                Log::error('Failed to create WhatsApp account in Evolution API', [
                    'company_id'    => $companyId,
                    'instance_name' => $instanceName,
                    'status'        => $response->status(),
                ]);

                // The response body can carry Evolution's own URL and key back
                // to a customer who is not supposed to know we run one.
                return response()->json([
                    'message' => 'Failed to create WhatsApp account',
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
            Log::error('Failed to create WhatsApp account', [
                'company_id' => $companyId,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create WhatsApp account',
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
                $this->logEvolutionFailure('delete account', $instance, $response->status());

                return response()->json([
                    'message' => 'Failed to delete WhatsApp account',
                ], 422);
            }

            // Only now delete locally
            $instance->delete();

            return response()->json([
                'message' => 'WhatsApp account deleted successfully',
            ], 200);

        } catch (\Throwable $e) {
            $this->logEvolutionFailure('delete account', $instance, null, $e);

            return response()->json([
                'message' => 'Failed to delete WhatsApp account',
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
                $this->logEvolutionFailure('send test message', $instance, $response->status());

                return response()->json([
                    'message' => 'Failed to send WhatsApp message',
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
            $this->logEvolutionFailure('send test message', $instance, null, $e);

            return response()->json([
                'message' => 'Failed to send WhatsApp message',
            ], 500);
        }
    }

    /**
     * Evolution's own errors quote the URL and API key of the server we run, so
     * they are logged for us and never handed back to the customer — who, by
     * design, does not know an Evolution server is involved at all.
     */
    private function logEvolutionFailure(
        string $action,
        WhatsAppInstance $instance,
        ?int $status = null,
        ?\Throwable $exception = null
    ): void {
        Log::error("WhatsApp: failed to {$action} via Evolution API", array_filter([
            'company_id'    => $instance->company_id,
            'instance_id'   => $instance->id,
            'instance_name' => $instance->instance_name,
            'status'        => $status,
            'error'         => $exception?->getMessage(),
        ], fn ($value) => $value !== null));
    }

    /**
     * Ask Evolution for the live connection state of a single instance and
     * persist it. This is the canonical way to know whether an instance is
     * connected — sending a test message is no longer required.
     */
    public function connectionStatus($instanceId)
    {
        $companyId = Auth::user()->company_id;

        $instance = WhatsAppInstance::where('id', $instanceId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $integration = WhatsAppEvoIntegration::where('id', $instance->whats_app_evo_integration_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        try {
            return response()->json($this->syncConnectionState($instance, $integration), 200);
        } catch (\Throwable $e) {
            Log::error('WhatsApp connection status check failed', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to check WhatsApp connection status',
            ], 500);
        }
    }

    /**
     * Same check for every instance of the company, so the integrations panel
     * can show live statuses in a single round trip instead of one per card.
     */
    public function connectionStatusAll()
    {
        $companyId = Auth::user()->company_id;

        $instances = WhatsAppInstance::where('company_id', $companyId)->get();
        $integrations = WhatsAppEvoIntegration::where('company_id', $companyId)->get()->keyBy('id');

        $results = [];

        foreach ($instances as $instance) {
            $integration = $integrations->get($instance->whats_app_evo_integration_id);

            if (!$integration) {
                continue;
            }

            try {
                $results[] = $this->syncConnectionState($instance, $integration);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp connection status check failed', [
                    'instance_id' => $instance->id,
                    'error' => $e->getMessage(),
                ]);

                $results[] = $this->statePayload($instance, false, 'Failed to reach Evolution API');
            }
        }

        return response()->json(['instances' => $results], 200);
    }

    /**
     * Hit /instance/connectionState, map Evolution's state onto our own status
     * and, when the instance is connected, enrich it with the WhatsApp account
     * profile (name / picture / number) from /instance/fetchInstances.
     */
    private function syncConnectionState(WhatsAppInstance $instance, WhatsAppEvoIntegration $integration): array
    {
        $response = Http::withHeaders([
            'apikey' => $integration->api_key,
        ])->get(
            rtrim($integration->base_url, '/') . '/instance/connectionState/' . $instance->instance_name
        );

        if (!$response->successful()) {
            // A missing instance on Evolution's side means the local record is
            // pointing at something that no longer exists.
            $status = $response->status() === 404 ? 'error' : $instance->status;

            $instance->update([
                'status' => $status,
                'status_checked_at' => now(),
            ]);

            return $this->statePayload(
                $instance->fresh(),
                false,
                $response->status() === 404
                    ? 'Instance not found in Evolution API'
                    : 'Failed to read connection state from Evolution API'
            );
        }

        $state = $response->json('instance.state');
        $connected = $state === 'open';

        $attributes = [
            'status' => match ($state) {
                'open' => 'connected',
                'connecting' => 'qrcode',
                default => 'disconnected',
            },
            'status_checked_at' => now(),
        ];

        if ($connected) {
            if ($instance->status !== 'connected' || !$instance->connected_at) {
                $attributes['connected_at'] = now();
            }

            $attributes = array_merge($attributes, $this->fetchProfile($instance, $integration));
        }

        $instance->update($attributes);

        return $this->statePayload($instance->fresh(), $connected);
    }

    /**
     * Profile of the WhatsApp account currently paired with the instance.
     * Failures here are non-fatal: the connection state is still valid even if
     * the profile lookup fails, so we simply keep whatever we had before.
     */
    private function fetchProfile(WhatsAppInstance $instance, WhatsAppEvoIntegration $integration): array
    {
        try {
            $response = Http::withHeaders([
                'apikey' => $integration->api_key,
            ])->get(
                rtrim($integration->base_url, '/') . '/instance/fetchInstances',
                ['instanceName' => $instance->instance_name]
            );

            if (!$response->successful()) {
                return [];
            }

            $payload = $response->json();
            // Evolution returns an array of matching instances.
            $data = is_array($payload) && array_is_list($payload) ? ($payload[0] ?? null) : $payload;

            if (!is_array($data)) {
                return [];
            }

            $ownerJid = $data['ownerJid'] ?? null;

            return array_filter([
                'profile_name' => $data['profileName'] ?? null,
                'profile_pic_url' => $data['profilePicUrl'] ?? null,
                'owner_jid' => $ownerJid,
                'phone_number' => $ownerJid ? Str::before($ownerJid, '@') : ($data['number'] ?? null),
            ], fn ($value) => $value !== null);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp profile fetch failed', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function statePayload(WhatsAppInstance $instance, bool $connected, ?string $message = null): array
    {
        return [
            'id' => $instance->id,
            'instance_name' => $instance->instance_name,
            'status' => $instance->status,
            'connected' => $connected,
            'profile' => $connected ? [
                'name' => $instance->profile_name,
                'picture_url' => $instance->profile_pic_url,
                'phone_number' => $instance->phone_number,
            ] : null,
            'connected_at' => $instance->connected_at,
            'checked_at' => $instance->status_checked_at,
            'message' => $message,
        ];
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
                $this->logEvolutionFailure('generate QR code', $instance, $response->status());

                return response()->json([
                    'message' => 'Failed to generate WhatsApp QR Code',
                ], 422);
            }

            // Return Evolution response directly to frontend
            return response()->json($response->json(), 200);

        } catch (\Throwable $e) {
            $this->logEvolutionFailure('generate QR code', $instance, null, $e);

            return response()->json([
                'message' => 'Failed to generate WhatsApp QR Code',
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
                $this->logEvolutionFailure('disconnect account', $instance, $response->status());

                return response()->json([
                    'message' => 'Failed to disconnect WhatsApp account',
                ], 422);
            }

            // The session is gone, so the paired account profile no longer applies.
            $instance->update([
                'status' => 'disconnected',
                'profile_name' => null,
                'profile_pic_url' => null,
                'owner_jid' => null,
                'phone_number' => null,
                'status_checked_at' => now(),
            ]);

            $reassignment = null;

            if (!empty($data['reassign_to_instance_id'])) {
                $targetInstance = WhatsAppInstance::where('id', $data['reassign_to_instance_id'])
                    ->where('company_id', $companyId)
                    ->firstOrFail();

                $reassignment = $this->reassignChats($instance, $targetInstance);
            }

            return response()->json([
                'message' => 'WhatsApp account disconnected successfully',
                'status'  => 'disconnected',
                'reassignment' => $reassignment,
            ], 200);

        } catch (\Throwable $e) {
            $this->logEvolutionFailure('disconnect account', $instance, null, $e);

            return response()->json([
                'message' => 'Failed to disconnect WhatsApp account',
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
