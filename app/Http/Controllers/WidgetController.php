<?php

namespace App\Http\Controllers;

use App\Models\Funnel;
use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\WidgetTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRM-side management of embeddable widgets. Type-specific behaviour lives in
 * App\Services\Widgets, so this controller stays the same as new widget types
 * are added.
 */
class WidgetController extends Controller
{
    public function index(Request $request)
    {
        $companyId = Auth::user()->company_id;

        $widgets = Widget::where('company_id', $companyId)
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->with('creator:id,name')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $widgets->map(fn ($widget) => $this->present($widget)),
        ]);
    }

    public function show($id)
    {
        $widget = $this->findWidget($id);

        return response()->json(['data' => $this->present($widget)]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $this->validatePayload($request);
        $type = WidgetTypeRegistry::resolve($data['type']);

        $widget = Widget::create([
            'company_id' => $user->company_id,
            'created_by' => $user->id,
            'type' => $data['type'],
            'public_id' => Widget::newPublicId($data['type']),
            'name' => $data['name'],
            'status' => $data['status'] ?? Widget::STATUS_ACTIVE,
            'allowed_domains' => $this->normalizeDomains($data['allowed_domains']),
            'config' => $type->normalizeConfig($data['config'], $user->company_id),
        ]);

        return response()->json(['data' => $this->present($widget)], 201);
    }

    public function update(Request $request, $id)
    {
        $widget = $this->findWidget($id);

        $data = $this->validatePayload($request, $widget);
        $type = WidgetTypeRegistry::resolve($widget->type);

        $widget->update([
            'name' => $data['name'],
            'status' => $data['status'] ?? $widget->status,
            'allowed_domains' => $this->normalizeDomains($data['allowed_domains']),
            'config' => $type->normalizeConfig($data['config'], $widget->company_id),
        ]);

        return response()->json(['data' => $this->present($widget->fresh())]);
    }

    public function destroy($id)
    {
        $widget = $this->findWidget($id);
        $widget->delete();

        return response()->json(['message' => 'Widget deleted successfully.']);
    }

    /**
     * Copy a widget with a fresh public id — the clone is created disabled so
     * it can't collect leads before its domain and mapping are reviewed.
     */
    public function duplicate($id)
    {
        $widget = $this->findWidget($id);

        $copy = Widget::create([
            'company_id' => $widget->company_id,
            'created_by' => Auth::id(),
            'type' => $widget->type,
            'public_id' => Widget::newPublicId($widget->type),
            'name' => $widget->name . ' (copy)',
            'status' => Widget::STATUS_DISABLED,
            'allowed_domains' => $widget->allowed_domains,
            'config' => $widget->config,
        ]);

        return response()->json(['data' => $this->present($copy)], 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $widget = $this->findWidget($id);

        $data = $request->validate([
            'status' => ['required', Rule::in([Widget::STATUS_ACTIVE, Widget::STATUS_DISABLED])],
        ]);

        $widget->update(['status' => $data['status']]);

        return response()->json(['data' => $this->present($widget->fresh())]);
    }

    /**
     * Issue a new public id. The old snippet stops working immediately, which
     * is the point — it's the "revoke a leaked embed" action.
     */
    public function regeneratePublicId($id)
    {
        $widget = $this->findWidget($id);

        $widget->update(['public_id' => Widget::newPublicId($widget->type)]);

        return response()->json(['data' => $this->present($widget->fresh())]);
    }

    /**
     * Everything the builder needs to render its CRM mapping step.
     */
    public function options()
    {
        $funnels = Funnel::where('company_id', Auth::user()->company_id)
            ->where('type', 'sale')
            ->where('is_active', true)
            ->with(['stages' => fn ($query) => $query->select('id', 'funnel_id', 'name', 'type', 'order')])
            ->get(['id', 'name'])
            ->map(fn ($funnel) => [
                'id' => $funnel->id,
                'name' => $funnel->name,
                'stages' => $funnel->stages->map(fn ($stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'type' => $stage->type,
                ])->values(),
            ]);

        // Chat widgets can pin every conversation to one owner.
        $users = User::where('company_id', Auth::user()->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'funnels' => $funnels,
            'users' => $users,
            'embed_base_url' => rtrim(config('app.url'), '/'),
        ]);
    }

    private function validatePayload(Request $request, ?Widget $widget = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([Widget::STATUS_ACTIVE, Widget::STATUS_DISABLED])],
            'allowed_domains' => ['required', 'array', 'min:1', 'max:10'],
            'allowed_domains.*' => ['required', 'string', 'max:255'],
            'config' => ['required', 'array'],
        ];

        // The type is fixed once created — changing it would invalidate the config.
        if (!$widget) {
            $rules['type'] = ['required', 'string', Rule::in(WidgetTypeRegistry::keys())];
        }

        $data = $request->validate($rules);

        foreach ($data['allowed_domains'] as $index => $domain) {
            if (Widget::normalizeHost($domain) === null) {
                throw ValidationException::withMessages([
                    "allowed_domains.{$index}" => 'Enter a valid domain, for example acme.com.',
                ]);
            }
        }

        return $data;
    }

    /** Store bare hosts so matching never depends on how the user typed it. */
    private function normalizeDomains(array $domains): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($domain) => Widget::normalizeHost($domain), $domains)
        )));
    }

    private function findWidget($id): Widget
    {
        return Widget::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();
    }

    private function present(Widget $widget): array
    {
        return [
            'id' => $widget->id,
            'public_id' => $widget->public_id,
            'type' => $widget->type,
            'name' => $widget->name,
            'status' => $widget->status,
            'allowed_domains' => $widget->allowed_domains ?? [],
            'config' => $widget->config,
            'submissions_count' => $widget->submissions_count,
            'last_submission_at' => $widget->last_submission_at,
            'created_by' => $widget->creator?->name,
            'created_at' => $widget->created_at,
            'updated_at' => $widget->updated_at,
        ];
    }
}
