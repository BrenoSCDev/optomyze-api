<?php

namespace App\Http\Controllers;

use App\Models\CustomProductField;
use App\Models\CustomProductFieldGroup;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomProductFieldGroupController extends Controller
{
    /**
     * List groups. Pass ?product_id=X for product-level groups,
     * omit to get model-level groups only.
     */
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;

        $query = CustomProductFieldGroup::fromCompany($companyId)
            ->with(['fields' => fn ($q) => $q->orderBy('display_order')]);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        } else {
            $query->whereNull('product_id');
        }

        return response()->json($query->orderBy('display_order')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'icon'          => 'nullable|string|max:100',
            'is_default'    => 'boolean',
            'display_order' => 'integer',
            'product_id'    => 'nullable|exists:products,id',
        ]);

        $companyId = $request->user()->company_id;

        // If product_id provided, validate it belongs to company
        if (!empty($validated['product_id'])) {
            Product::fromCompany($companyId)->findOrFail($validated['product_id']);
        }

        $group = CustomProductFieldGroup::create([
            'company_id'    => $companyId,
            'product_id'    => $validated['product_id'] ?? null,
            'name'          => $validated['name'],
            'icon'          => $validated['icon'] ?? null,
            'is_default'    => $validated['is_default'] ?? false,
            'display_order' => $validated['display_order'] ?? 0,
        ]);

        return response()->json($group->load('fields'), 201);
    }

    public function show(Request $request, $id)
    {
        $group = CustomProductFieldGroup::fromCompany($request->user()->company_id)
            ->with(['fields' => fn ($q) => $q->orderBy('display_order')])
            ->findOrFail($id);

        return response()->json($group);
    }

    public function update(Request $request, $id)
    {
        $group = CustomProductFieldGroup::fromCompany($request->user()->company_id)->findOrFail($id);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'icon'          => 'nullable|string|max:100',
            'is_default'    => 'boolean',
            'display_order' => 'integer',
        ]);

        $group->update($validated);

        return response()->json($group->load('fields'));
    }

    public function destroy(Request $request, $id)
    {
        $group = CustomProductFieldGroup::fromCompany($request->user()->company_id)->findOrFail($id);

        // Ungroup all fields in this group (set group_id = null)
        CustomProductField::where('group_id', $group->id)->update(['group_id' => null]);

        $group->delete();

        return response()->json(['message' => 'Field group deleted. Its fields have been ungrouped.']);
    }
}
