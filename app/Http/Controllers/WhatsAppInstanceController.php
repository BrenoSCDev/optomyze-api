<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsAppInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class WhatsAppInstanceController extends Controller
{
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

            // Success → return Evolution response as-is
            return response()->json($response->json(), 200);

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
