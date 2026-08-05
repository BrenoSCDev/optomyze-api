<?php

namespace App\Http\Controllers;

use App\Models\WidgetIntegration;
use App\Models\FacebookMessengerIntegration;
use App\Models\GoogleAdsIntegration;
use App\Models\InstagramIntegration;
use App\Models\MetaAdsIntegration;
use App\Models\N8nIntegration;
use App\Models\OpenAiIntegration;
use App\Models\WhatsAppEvoIntegration;
use App\Models\WhatsAppInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Funnel;
use App\Models\OptomyzeErpIntegration;

class IntegrationController extends Controller
{
    /**
     * Return available integrations for the authenticated user's company
     */
    public function available()
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        // Mapeamento de integrações => Model
        $integrations = [
            'n8n'      => N8nIntegration::class,
            'meta_ads' => MetaAdsIntegration::class,
            'google_ads' => GoogleAdsIntegration::class,
            'wpp_evo' => WhatsAppEvoIntegration::class,
            'optomyze_erp'   => OptomyzeErpIntegration::class,
            'instagram' => InstagramIntegration::class,
            'facebook' => FacebookMessengerIntegration::class,
            'openai' => OpenAiIntegration::class,
        ];

        $availability = [];

        foreach ($integrations as $key => $model) {
            $availability[$key . '_available'] = $model::where('company_id', $user->company_id)
                ->active()
                ->exists();
        }

        // Widget types live in one table keyed by type rather than a table
        // each, so they don't fit the map above. The response keys match what
        // the cards look for: `<key with - as _>_available`.
        foreach (WidgetIntegration::TYPES as $type) {
            $availability[$type . '_widget_available'] = WidgetIntegration::fromCompany($user->company_id)
                ->ofType($type)
                ->active()
                ->exists();
        }

        return response()->json($availability, 200);
    }

    public function availableWithSettings()
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        // Integration key => Model mapping
        $integrations = [
            'wpp_evo'    => WhatsAppEvoIntegration::class,
        ];

        $data = [];

        foreach ($integrations as $key => $model) {
            $isAvailable = $model::where('company_id', $user->company_id)
                ->active()
                ->exists();

            $data[$key . '_available'] = $isAvailable;

            // Special case: WhatsApp Evolution
            if ($key === 'wpp_evo' && $isAvailable) {

                $integration = $model::where('company_id', $user->company_id)
                    ->active()
                    ->first();

                /*
                 * "Configured" no longer means the customer filled in a form —
                 * they never see one. It means the integration has a working
                 * Evolution server behind it, which for everyone is the one we
                 * host. The server's URL is deliberately not sent back: it is
                 * our infrastructure, not something a tenant needs or should
                 * be able to read out of an API response.
                 */
                $data['wpp_evo_configured'] = !empty($integration->base_url) && !empty($integration->api_key);

                $data['wpp_evo_instances'] = WhatsAppInstance::where(
                    'company_id',
                    $user->company_id
                )
                ->where(
                    'whats_app_evo_integration_id',
                    $integration->id
                )
                ->select(
                    'instance_name', 'id', 'created_at', 'funnel_id', 'status',
                    'profile_name', 'profile_pic_url', 'phone_number'
                )
                ->with(
                    'funnel:id,name'
                )
                ->get();
            }
        }

        /*
         * Contact funnels only.
         *
         * This list is what a channel integration gets attached to, and an
         * inbound message creates a *contact*. Deal funnels were offered too,
         * so it was possible to point WhatsApp at a pipeline whose entry stage
         * leads could never enter — and 'model' funnels are templates, not
         * pipelines anything can live in.
         */
        $data['funnels'] = Funnel::fromCompany($user->company_id)
            ->where('type', 'contact')
            ->select('id', 'name')
            ->get();

        return response()->json(
            $data, 200
        );
    }

}
