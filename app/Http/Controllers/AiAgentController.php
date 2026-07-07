<?php

namespace App\Http\Controllers;

use App\Models\AiAgent;
use App\Models\AiAgentChannelBinding;
use App\Models\InstagramIntegration;
use App\Models\OpenAiIntegration;
use App\Models\WhatsAppInstance;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AiAgentController extends Controller
{
    /**
     * Short channel-type keys accepted from the frontend, mapped to the
     * FQCN morph values used by inbox_chats.channelable_type. Raw class
     * names are never accepted from clients.
     */
    private const CHANNEL_TYPES = [
        'whatsapp_instance' => WhatsAppInstance::class,
        'instagram' => InstagramIntegration::class,
    ];

    public function index()
    {
        $user = Auth::user();

        $agents = AiAgent::fromCompany($user->company_id)
            ->with('channelBindings.channelable')
            ->orderBy('name')
            ->get();

        return response()->json(
            $agents->map(fn (AiAgent $agent) => $this->formatAgent($agent))
        );
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $this->validateAgent($request);

        $agent = AiAgent::create(array_merge($validated, [
            'company_id' => $user->company_id,
            'status' => AiAgent::STATUS_DISABLED,
        ]));

        return response()->json([
            'message' => 'AI Agent created successfully.',
            'agent' => $this->formatAgent($agent->load('channelBindings.channelable')),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $agent = AiAgent::fromCompany($user->company_id)->findOrFail($id);

        $validated = $this->validateAgent($request);

        $agent->update($validated);

        return response()->json([
            'message' => 'AI Agent updated successfully.',
            'agent' => $this->formatAgent($agent->load('channelBindings.channelable')),
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $user = Auth::user();

        $agent = AiAgent::fromCompany($user->company_id)->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in([AiAgent::STATUS_ENABLED, AiAgent::STATUS_DISABLED])],
        ]);

        if ($validated['status'] === AiAgent::STATUS_ENABLED) {
            $integration = OpenAiIntegration::fromCompany($user->company_id)->active()->first();

            if (!$integration || !$integration->isUsable()) {
                return response()->json([
                    'message' => 'The OpenAI integration must be installed and connected before enabling an agent.',
                ], 422);
            }
        }

        $agent->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'AI Agent status updated.',
            'agent' => $this->formatAgent($agent->load('channelBindings.channelable')),
        ]);
    }

    public function destroy($id)
    {
        $user = Auth::user();

        $agent = AiAgent::fromCompany($user->company_id)->findOrFail($id);
        $agent->delete();

        return response()->json(['message' => 'AI Agent deleted.']);
    }

    /**
     * Replace the agent's channel-account bindings.
     * Payload: { bindings: [{ channelable_type: 'whatsapp_instance'|'instagram', channelable_id: int }] }
     */
    public function syncChannels(Request $request, $id)
    {
        $user = Auth::user();

        $agent = AiAgent::fromCompany($user->company_id)->findOrFail($id);

        $validated = $request->validate([
            'bindings' => ['present', 'array'],
            'bindings.*.channelable_type' => ['required', Rule::in(array_keys(self::CHANNEL_TYPES))],
            'bindings.*.channelable_id' => ['required', 'integer'],
        ]);

        $bindings = [];

        foreach ($validated['bindings'] as $binding) {
            $modelClass = self::CHANNEL_TYPES[$binding['channelable_type']];

            // The account must exist and belong to the user's company.
            $account = $modelClass::where('id', $binding['channelable_id'])
                ->where('company_id', $user->company_id)
                ->first();

            if (!$account) {
                return response()->json([
                    'message' => 'Channel account not found.',
                ], 422);
            }

            // One agent per channel account.
            $claimed = AiAgentChannelBinding::where('channelable_type', $modelClass)
                ->where('channelable_id', $account->id)
                ->where('ai_agent_id', '!=', $agent->id)
                ->with('agent:id,name')
                ->first();

            if ($claimed) {
                return response()->json([
                    'message' => "This channel account is already handled by the agent \"{$claimed->agent?->name}\".",
                ], 422);
            }

            $bindings[] = [
                'channelable_type' => $modelClass,
                'channelable_id' => $account->id,
            ];
        }

        DB::transaction(function () use ($agent, $bindings, $user) {
            $agent->channelBindings()->delete();

            foreach ($bindings as $binding) {
                $agent->channelBindings()->create(array_merge($binding, [
                    'company_id' => $user->company_id,
                ]));
            }
        });

        return response()->json([
            'message' => 'Channel bindings updated.',
            'agent' => $this->formatAgent($agent->load('channelBindings.channelable')),
        ]);
    }

    /**
     * The company's connectable channel accounts, annotated with any
     * current agent binding — for the agent form's Channels tab.
     */
    public function channelAccounts()
    {
        $user = Auth::user();

        $accounts = [];

        $whatsAppInstances = WhatsAppInstance::where('company_id', $user->company_id)
            ->get(['id', 'instance_name']);

        foreach ($whatsAppInstances as $instance) {
            $accounts[] = $this->formatChannelAccount(
                'whatsapp_instance',
                WhatsAppInstance::class,
                $instance->id,
                $instance->instance_name ?: "WhatsApp #{$instance->id}",
                'whatsapp'
            );
        }

        $instagramIntegrations = InstagramIntegration::fromCompany($user->company_id)
            ->get(['id', 'username', 'display_name']);

        foreach ($instagramIntegrations as $integration) {
            $accounts[] = $this->formatChannelAccount(
                'instagram',
                InstagramIntegration::class,
                $integration->id,
                $integration->username ? "@{$integration->username}" : "Instagram #{$integration->id}",
                'instagram'
            );
        }

        return response()->json($accounts);
    }

    /**
     * Tool catalog for the agent form's Tools tab.
     */
    public function tools()
    {
        return response()->json(ToolRegistry::catalog());
    }

    /**
     * Sandbox chat: test the agent config currently in the form (saved or
     * not) against the real provider. Read-only tools run for real,
     * mutating tools are simulated — nothing reaches a customer.
     */
    public function previewChat(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'config' => ['required', 'array'],
            'config.provider' => ['required', Rule::in([AiAgent::PROVIDER_OPENAI])],
            'config.model' => ['required', 'string', 'max:100'],
            'config.temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'config.max_tokens' => ['required', 'integer', 'min:64', 'max:16000'],
            'config.system_prompt' => ['nullable', 'string', 'max:100000'],
            'config.enabled_tools' => ['nullable', 'array'],
            'config.enabled_tools.*' => [Rule::in(ToolRegistry::keys())],
            'config.handover_message' => ['nullable', 'string', 'max:2000'],
            'messages' => ['required', 'array', 'min:1', 'max:40'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $result = app(\App\Services\Ai\AiAgentPreviewService::class)->chat(
                $user->company_id,
                $validated['config'],
                $validated['messages'],
            );

            return response()->json($result);
        } catch (\App\Services\Ai\AiProviderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function executions(Request $request, $id)
    {
        $user = Auth::user();

        $agent = AiAgent::fromCompany($user->company_id)->findOrFail($id);

        $executions = $agent->executions()
            ->with('chat:id,contact_name,channel')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($executions);
    }

    private function validateAgent(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'provider' => ['required', Rule::in([AiAgent::PROVIDER_OPENAI])],
            'model' => ['required', 'string', 'max:100'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['required', 'integer', 'min:64', 'max:16000'],
            'system_prompt' => ['nullable', 'string', 'max:100000'],
            'enabled_tools' => ['nullable', 'array'],
            'enabled_tools.*' => [Rule::in(ToolRegistry::keys())],
            'response_delay_seconds' => [
                'required',
                'integer',
                'min:' . AiAgent::MIN_RESPONSE_DELAY_SECONDS,
                'max:600',
            ],
            'handover_message' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function formatChannelAccount(string $typeKey, string $modelClass, int $id, string $label, string $channel): array
    {
        $binding = AiAgentChannelBinding::where('channelable_type', $modelClass)
            ->where('channelable_id', $id)
            ->with('agent:id,name')
            ->first();

        return [
            'channelable_type' => $typeKey,
            'channelable_id' => $id,
            'label' => $label,
            'channel' => $channel,
            'bound_agent' => $binding?->agent ? [
                'id' => $binding->agent->id,
                'name' => $binding->agent->name,
            ] : null,
        ];
    }

    private function formatAgent(AiAgent $agent): array
    {
        $typeKeys = array_flip(self::CHANNEL_TYPES);

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'description' => $agent->description,
            'status' => $agent->status,
            'provider' => $agent->provider,
            'model' => $agent->model,
            'temperature' => $agent->temperature,
            'max_tokens' => $agent->max_tokens,
            'system_prompt' => $agent->system_prompt,
            'enabled_tools' => $agent->enabled_tools ?? [],
            'response_delay_seconds' => $agent->response_delay_seconds,
            'handover_message' => $agent->handover_message,
            'execution_count' => $agent->execution_count,
            'last_executed_at' => $agent->last_executed_at,
            'created_at' => $agent->created_at,
            'channel_bindings' => $agent->channelBindings->map(function (AiAgentChannelBinding $binding) use ($typeKeys) {
                $channelable = $binding->channelable;

                $label = match ($binding->channelable_type) {
                    WhatsAppInstance::class => $channelable?->instance_name ?: "WhatsApp #{$binding->channelable_id}",
                    InstagramIntegration::class => $channelable?->username
                        ? "@{$channelable->username}"
                        : "Instagram #{$binding->channelable_id}",
                    default => "#{$binding->channelable_id}",
                };

                return [
                    'channelable_type' => $typeKeys[$binding->channelable_type] ?? $binding->channelable_type,
                    'channelable_id' => $binding->channelable_id,
                    'label' => $label,
                    'channel' => $binding->channelable_type === WhatsAppInstance::class ? 'whatsapp' : 'instagram',
                ];
            })->values(),
        ];
    }
}
