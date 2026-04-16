<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Funnel;
use App\Models\Stage;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class FunnelController extends Controller
{
    /**
     * Display a listing of funnels for the authenticated user's company.
     */
    public function index()
    {
        $user = Auth::user();

        $funnels = Funnel::fromCompany($user->company_id)
            ->whereIn('type', ['sale', 'deal'])
            ->get() // get results first
            ->map(function ($funnel) {
                $funnel->nleads = $funnel->totalLeadsCount();
                return $funnel;
            });

            return response()->json($funnels);
    }

    /**
     * Store a newly created funnel in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'is_active'       => 'boolean',
            'settings'        => 'nullable|array',
            'model_funnel_id' => 'nullable|exists:funnels,id',
            'type'            => 'nullable',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Create the new funnel
        |--------------------------------------------------------------------------
        */
        $funnel = Funnel::create([
            'company_id'  => $user->company_id,
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active'   => $validated['is_active'] ?? true,
            'created_by'  => $user->id,
            'settings'    => $validated['settings'] ?? null,
            'type'        => $validated['type'] ?? 'sale',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Replicate stages from model funnel (if provided)
        |--------------------------------------------------------------------------
        */
        if (!empty($validated['model_funnel_id'])) {
            $modelFunnel = Funnel::where('id', $validated['model_funnel_id'])
                ->where('type', 'model')
                ->with(['stages' => function ($q) {
                    $q->where('type', 'model')->orderBy('order');
                }])
                ->firstOrFail();

            foreach ($modelFunnel->stages as $modelStage) {
                Stage::create([
                    'company_id'  => $user->company_id,
                    'funnel_id'   => $funnel->id,
                    'name'        => $modelStage->name,
                    'description' => $modelStage->description,
                    'type'        => 'stage',
                    'order'       => $modelStage->order,
                    'color'       => $modelStage->color,
                    'settings'    => $modelStage->settings,
                    'is_active'   => true,
                    'created_by'  => $user->id,
                ]);
            }
        }

        return response()->json($funnel->load('stages'), 201);
    }

    /**
     * Display the specified funnel (only if belongs to company).
     */
    public function show($id)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);

        return response()->json($funnel);
    }

    /**
     * Update the specified funnel.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        $funnel->update($validated);

        return response()->json($funnel);
    }

    /**
     * Remove the specified funnel.
     */
    public function destroy($id)
    {
        $user = Auth::user();

        $funnel = Funnel::fromCompany($user->company_id)->findOrFail($id);

        $funnel->delete();

        return response()->json(['message' => 'Funnel deleted successfully']);
    }

    public function leadsByStages($id)
    {
        $user = Auth::user();

        $funnel = Funnel::with([
            'stages' => function ($query) use ($user) {
                $query->where('is_active', true)
                    ->with([
                        'activeLeads' => function ($query) use ($user) {
                            $query->orderBy('created_at', 'desc')
                                ->visibleToUser($user)
                                ->with([
                                    'assignedUser:id,name',
                                    'products:id,name,base_price',
                                ]);
                        }
                    ]);
            }
        ])->findOrFail($id);

        // 🔢 Funnel total value
        $funnelTotal = 0;

        $funnel->stages->transform(function ($stage) use (&$funnelTotal) {

            $stageTotal = 0;

            $stage->activeLeads->transform(function ($lead) use (&$stageTotal, &$funnelTotal) {

                // Lead estimated value
                $leadValue = $lead->products->sum(function ($product) {
                    return (float) $product->pivot->total_price;
                });

                // Attach to lead
                $lead->value_estimated = $leadValue;

                // Accumulate
                $stageTotal += $leadValue;
                $funnelTotal += $leadValue;

                return $lead;
            });

            // Attach stage total
            $stage->value_estimated = $stageTotal;

            return $stage;
        });

        // Attach funnel total
        $funnel->value_estimated = $funnelTotal;

        $tags = Tag::fromCompany($user->company_id)->get();

        if($funnel->company_id !== $user->company_id) {
            throw ValidationException::withMessages(['funnel' => 'Funnel not found.']);
        }

        return response()->json([
            'funnel' => $funnel,
            'tags'   => $tags,
        ]);
    }

    public function dealsByStages($id)
    {
        $user = Auth::user();

        $funnel = Funnel::with([
            'stages' => function ($query) use ($user) {
                $query->where('is_active', true)
                    ->with([
                        'deals' => function ($query) use ($user) {
                            $query->orderBy('created_at', 'desc')
                                ->visibleToUser($user)
                                ->with([
                                    'lead:id,first_name,last_name,email,phone,stage_id,funnel_id,assigned_to',
                                    'lead.assignedUser:id,name',
                                    'lead.stage:id,name,color',
                                    'lead.funnel:id,name',
                                    'user:id,name',
                                    'items:id,sale_id,product_name,quantity,total_price',
                                    'charges',
                                    'discounts',
                                ]);
                        }
                    ]);
            }
        ])->findOrFail($id);

        if($funnel->company_id !== $user->company_id) {
            throw ValidationException::withMessages(['funnel' => 'Funnel not found.']);
        }

        return response()->json([
            'funnel' => $funnel
        ]);
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $query = Funnel::fromCompany($company->id)
            ->with(['stages:id,funnel_id,name,order,type,color']);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $funnels = $query->paginate($request->input('per_page', 20));

        return response()->json($funnels);
    }

    public function apiShow(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)
            ->with(['stages' => fn ($q) => $q->ordered()])
            ->find($id);

        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        return response()->json(['data' => $funnel]);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'type'        => 'nullable|in:sale,deal',
            'is_active'   => 'boolean',
        ]);

        $funnel = Funnel::create([
            'company_id'  => $company->id,
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active'   => $validated['is_active'] ?? true,
            'type'        => $validated['type'] ?? 'sale',
        ]);

        return response()->json([
            'message' => 'Funnel created successfully',
            'data'    => $funnel,
        ], 201);
    }

    public function apiUpdate(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)->find($id);
        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        $validated = $request->validate([
            'name'        => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        $funnel->update($validated);

        return response()->json([
            'message' => 'Funnel updated successfully',
            'data'    => $funnel->fresh(),
        ]);
    }

    public function apiDestroy(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $funnel = Funnel::fromCompany($company->id)->find($id);
        if (!$funnel) {
            return response()->json(['message' => 'Funnel not found'], 404);
        }

        $funnel->delete();

        return response()->json(['message' => 'Funnel deleted successfully']);
    }
}
