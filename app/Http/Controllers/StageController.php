<?php

namespace App\Http\Controllers;

use App\Models\Funnel;
use App\Models\Stage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StageController extends Controller
{
    /**
     * List all stages for a funnel (only if funnel belongs to user's company).
     */
    public function index($funnelId)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($funnelId);

        $stages = $funnel->stages()->ordered()
        ->with('entryRequirement')
        ->get()
        ->map(function ($stage) {
            $stage->nleads = $stage->leads->count();
            return $stage;
        });

        return response()->json([
            'stages' => $stages,
            'funnel' => $funnel
        ]);
    }

    public function stagesList($funnelId)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($funnelId);

        $stages = $funnel->stages()->ordered()
        ->select('id', 'name')
        ->get();

        return response()->json([
            'stages' => $stages
        ]);
    }

    /**
     * Store a new stage in a funnel.
     */
    public function store(Request $request, $funnelId)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($funnelId);

        $validated = $request->validate(Stage::validationRules());

        // Find the current max order in this funnel
        $maxOrder = $funnel->stages()->max('order') ?? 0;

        $stage = Stage::create([
            'funnel_id'   => $funnel->id,
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color'       => $validated['color'],
            'order'       => $maxOrder + 1, // always the last
            'type'        => $validated['type'],
            'is_active'   => $validated['is_active'] ?? true,
            'settings'    => $validated['settings'] ?? null,
            'template_type' =>$validated['template_type'] = $validated['template_type'] ?? 'stage'
        ]);

        $stage->entryRequirement()->create();

        return response()->json($stage->load(['entryRequirement']), 201);
    }


    /**
     * Show a single stage.
     */
    public function show($funnelId, $stageId)
    {
        $user = Auth::user();

        $stage = Stage::fromCompany($user->company_id)
            ->where('funnel_id', $funnelId)
            ->findOrFail($stageId);

        return response()->json($stage);
    }

    /**
     * Update a stage.
     */
    public function update(Request $request, $funnelId, $stageId)
    {
        $user = Auth::user();

        $stage = Stage::fromCompany($user->company_id)
            ->where('funnel_id', $funnelId)
            ->findOrFail($stageId);

        $validated = $request->validate(Stage::updateValidationRules());

        $stage->update($validated);

        return response()->json($stage);
    }

    /**
     * Delete a stage.
     */
    public function destroy($funnelId, $stageId)
    {
        $user = Auth::user();

        $stage = Stage::fromCompany($user->company_id)
            ->where('funnel_id', $funnelId)
            ->findOrFail($stageId);

        $stage->delete();

        return response()->json(['message' => 'Stage deleted successfully']);
    }

    /**
     * Move a stage to a new order within its funnel.
     */
    public function moveOrder(Request $request, $funnelId, $stageId)
    {
        $user = Auth::user();

        $stage = Stage::fromCompany($user->company_id)
            ->where('funnel_id', $funnelId)
            ->findOrFail($stageId);

        $validated = $request->validate([
            'order' => 'required|integer|min:1',
        ]);

        $success = $stage->moveToOrder($validated['order']);

        if (!$success) {
            return response()->json([
                'message' => 'Invalid order position'
            ], 422);
        }

        // Return updated list of ordered stages
        $stages = $stage->funnel->stages()->ordered()->get();

        return response()->json([
            'message' => 'Stage reordered successfully',
            'stages' => $stages,
        ]);
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request, int $funnelId)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)->find($funnelId);
        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        $stages = $funnel->stages()->ordered()->get();

        return response()->json(['data' => $stages]);
    }

    public function apiShow(Request $request, int $funnelId, int $stageId)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)->find($funnelId);
        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        $stage = Stage::where('funnel_id', $funnelId)->find($stageId);
        if (!$stage) {
            return response()->json(['message' => 'Stage not found'], 404);
        }

        return response()->json(['data' => $stage]);
    }

    public function apiStore(Request $request, int $funnelId)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)->find($funnelId);
        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:50',
            'type'        => 'required|in:entry,stage,won,lost',
            'is_active'   => 'boolean',
        ]);

        $maxOrder = $funnel->stages()->max('order') ?? 0;

        $stage = Stage::create([
            'funnel_id'   => $funnel->id,
            'company_id'  => $company->id,
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color'       => $validated['color'] ?? null,
            'order'       => $maxOrder + 1,
            'type'        => $validated['type'],
            'is_active'   => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Stage created successfully',
            'data'    => $stage,
        ], 201);
    }

    public function apiUpdate(Request $request, int $funnelId, int $stageId)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)->find($funnelId);
        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        $stage = Stage::where('funnel_id', $funnelId)->find($stageId);
        if (!$stage) {
            return response()->json(['message' => 'Stage not found'], 404);
        }

        $validated = $request->validate([
            'name'        => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:50',
            'type'        => 'nullable|in:entry,stage,won,lost',
            'is_active'   => 'boolean',
        ]);

        $stage->update($validated);

        return response()->json([
            'message' => 'Stage updated successfully',
            'data'    => $stage->fresh(),
        ]);
    }

    public function apiDestroy(Request $request, int $funnelId, int $stageId)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)->find($funnelId);
        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        $stage = Stage::where('funnel_id', $funnelId)->find($stageId);
        if (!$stage) {
            return response()->json(['message' => 'Stage not found'], 404);
        }

        $stage->delete();

        return response()->json(['message' => 'Stage deleted successfully']);
    }
}