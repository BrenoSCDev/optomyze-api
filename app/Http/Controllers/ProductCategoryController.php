<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = ProductCategory::fromCompany($request->user()->company_id)
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:7',
        ]);

        $category = ProductCategory::create([
            'company_id' => $request->user()->company_id,
            ...$validated,
        ]);

        return response()->json($category->loadCount('products'), 201);
    }

    public function show(Request $request, $id)
    {
        $category = ProductCategory::fromCompany($request->user()->company_id)
            ->withCount('products')
            ->findOrFail($id);

        return response()->json($category);
    }

    public function update(Request $request, $id)
    {
        $category = ProductCategory::fromCompany($request->user()->company_id)->findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:7',
        ]);

        $category->update($validated);

        return response()->json($category->loadCount('products'));
    }

    public function destroy(Request $request, $id)
    {
        $category = ProductCategory::fromCompany($request->user()->company_id)->findOrFail($id);
        $category->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    // Sync categories on a product (replaces all)
    public function syncProductCategories(Request $request, $productId)
    {
        $product = \App\Models\Product::fromCompany($request->user()->company_id)->findOrFail($productId);

        $validated = $request->validate([
            'category_ids'   => 'required|array',
            'category_ids.*' => 'integer|exists:product_categories,id',
        ]);

        // Ensure categories belong to company
        $validIds = ProductCategory::fromCompany($request->user()->company_id)
            ->whereIn('id', $validated['category_ids'])
            ->pluck('id');

        $product->categories()->sync($validIds);

        return response()->json([
            'message'    => 'Categories updated.',
            'categories' => $product->categories()->withCount('products')->get(),
        ]);
    }

    // ─── External API ─────────────────────────────────────────────────────────

    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $categories = ProductCategory::fromCompany($company->id)
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }
}
