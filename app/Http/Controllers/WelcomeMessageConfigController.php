<?php

namespace App\Http\Controllers;

use App\Models\FacebookMessengerIntegration;
use App\Models\InboxChat;
use App\Models\InstagramIntegration;
use App\Models\WelcomeMessageConfig;
use App\Models\WhatsAppInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WelcomeMessageConfigController extends Controller
{
    /**
     * List every connected account for the company (WhatsApp instances,
     * Instagram integrations, Facebook pages) merged with its welcome
     * message config, if one has been saved.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $companyId = $user->company_id;

        $configs = WelcomeMessageConfig::where('company_id', $companyId)
            ->get()
            ->keyBy(fn ($c) => $c->channelable_type . ':' . $c->channelable_id);

        $accounts = collect();

        WhatsAppInstance::where('company_id', $companyId)->get()->each(function ($instance) use ($accounts, $configs) {
            $accounts->push($this->formatAccount(
                InboxChat::CHANNEL_WHATSAPP,
                WhatsAppInstance::class,
                $instance->id,
                $instance->label ?: $instance->instance_name,
                $configs
            ));
        });

        InstagramIntegration::where('company_id', $companyId)->get()->each(function ($integration) use ($accounts, $configs) {
            $accounts->push($this->formatAccount(
                InboxChat::CHANNEL_INSTAGRAM,
                InstagramIntegration::class,
                $integration->id,
                $integration->name ?: ('@' . $integration->username),
                $configs
            ));
        });

        FacebookMessengerIntegration::where('company_id', $companyId)->get()->each(function ($integration) use ($accounts, $configs) {
            $accounts->push($this->formatAccount(
                InboxChat::CHANNEL_MESSENGER,
                FacebookMessengerIntegration::class,
                $integration->id,
                $integration->name ?: $integration->page_name,
                $configs
            ));
        });

        return response()->json(['data' => $accounts->values()], 200);
    }

    public function upsert(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'channelable_type' => 'required|string|in:' . implode(',', [
                WhatsAppInstance::class,
                InstagramIntegration::class,
                FacebookMessengerIntegration::class,
            ]),
            'channelable_id' => 'required|integer',
            'channel' => 'required|string',
            'is_enabled' => 'boolean',
            'message' => 'nullable|string',
        ]);

        $config = WelcomeMessageConfig::updateOrCreate(
            [
                'channelable_type' => $data['channelable_type'],
                'channelable_id' => $data['channelable_id'],
            ],
            [
                'company_id' => $user->company_id,
                'channel' => $data['channel'],
                'is_enabled' => $data['is_enabled'] ?? false,
                'message' => $data['message'] ?? null,
            ]
        );

        return response()->json(['data' => $config], 200);
    }

    private function formatAccount(string $channel, string $type, int $id, string $label, $configs): array
    {
        $config = $configs->get($type . ':' . $id);

        return [
            'channel' => $channel,
            'channelable_type' => $type,
            'channelable_id' => $id,
            'label' => $label,
            'is_enabled' => $config?->is_enabled ?? false,
            'message' => $config?->message,
        ];
    }
}
