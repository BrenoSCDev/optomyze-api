<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CustomProductField;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;

        $query = Product::with(['primaryImage', 'fields'])
            ->fromCompany($companyId);

        // Filters
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('type')) {
            $query->byType($request->type);
        }

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $fields = CustomProductField::where('company_id', $companyId)->get();

        // Pagination
        $perPage = $request->get('per_page', 15);
        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'sku'         => 'required|string',
            'base_price'  => 'required|numeric|min:0',
            'type'        => 'required|in:product,service',
            'category'    => 'nullable|string|max:255',
            'is_active'   => 'boolean',
            'cost_price'  => 'nullable|numeric|min:0',
            'metadata'    => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $companyId = $request->user()->company_id;

        /** @var \App\Models\Product $product */
        $product = null;

        DB::transaction(function () use ($validator, $companyId, &$product) {

            // 1. Create product
            $product = Product::create([
                'company_id' => $companyId,
                ...$validator->validated()
            ]);

            // 2. Replicate model-level custom fields
            $modelFields = CustomProductField::where('company_id', $companyId)
                ->where('type', 'model')
                ->get();

            foreach ($modelFields as $field) {
                CustomProductField::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'type'       => 'product',
                    'field_key'  => $field->field_key,
                    'field_type' => $field->field_type,
                    'field_value'=> $field->field_value,
                ]);
            }
        });

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $product->load(['images', 'fields'])
        ], 201);
    }

    public function show(Request $request, $product)
    {
        $product = Product::with(['images', 'primaryImage', 'fields'])
            ->fromCompany($request->user()->company_id)
            ->findOrFail($product);

        return response()->json(['product' => $product]);
    }

    public function update(Request $request, $id)
    {
        $product = Product::fromCompany($request->user()->company_id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'sku' => 'sometimes|string',
            'base_price' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'type' => 'sometimes|in:product,service',
            'category' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product->update($validator->validated());

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->load(['images'])
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $product = Product::fromCompany($request->user()->company_id)->findOrFail($id);

        // Check if product has sales
        // if ($product->sales()->exists()) {
        //     return response()->json([
        //         'message' => 'Cannot delete product with existing sales. Consider deactivating it instead.'
        //     ], 422);
        // }

        $product->delete();

        return response()->json(['message' => 'Product deleted successfully.']);
    }

    public function toggleActive(Request $request, $id)
    {
        $product = Product::fromCompany($request->user()->company_id)->findOrFail($id);
        $product->update(['is_active' => !$product->is_active]);

        return response()->json([
            'message' => 'Product status updated.',
            'product' => $product
        ]);
    }

    /**
     * Add or update a custom field value on a product
     */
    public function storeCustomField(Request $request, Product $product)
    {
        $validated = $request->validate([
            'field_key'   => 'required|string|exists:custom_product_fields,field_key',
            'field_value' => 'nullable',
        ]);

        $fields = $product->custom_fields ?? [];
        $updated = false;

        foreach ($fields as &$field) {
            if ($field['field_key'] === $validated['field_key']) {
                $field['field_value'] = $validated['field_value'];
                $updated = true;
                break;
            }
        }

        if (! $updated) {
            $fields[] = [
                'field_key'   => $validated['field_key'],
                'field_value' => $validated['field_value'],
            ];
        }

        $product->custom_fields = array_values($fields);
        $product->save();

        return response()->json([
            'message' => 'Custom field saved successfully.',
            'custom_fields' => $product->custom_fields,
        ], 201);
    }

    /**
     * Update a specific custom field value
     */
    public function updateCustomField(Request $request, Product $product, string $fieldKey)
    {
        $validated = $request->validate([
            'field_value' => 'nullable',
        ]);

        $fields = $product->custom_fields ?? [];
        $found = false;

        foreach ($fields as &$field) {
            if ($field['field_key'] === $fieldKey) {
                $field['field_value'] = $validated['field_value'];
                $found = true;
                break;
            }
        }

        if (! $found) {
            return response()->json([
                'message' => 'Custom field not found on product.'
            ], 404);
        }

        $product->custom_fields = array_values($fields);
        $product->save();

        return response()->json([
            'message' => 'Custom field updated successfully.',
            'custom_fields' => $product->custom_fields,
        ]);
    }

    /**
     * Remove a custom field from the product
     */
    public function destroyCustomField(Product $product, string $fieldKey)
    {
        $fields = $product->custom_fields ?? [];

        $filtered = array_filter($fields, function ($field) use ($fieldKey) {
            return $field['field_key'] !== $fieldKey;
        });

        if (count($fields) === count($filtered)) {
            return response()->json([
                'message' => 'Custom field not found on product.'
            ], 404);
        }

        $product->custom_fields = array_values($filtered);
        $product->save();

        return response()->json([
            'message' => 'Custom field removed successfully.',
            'custom_fields' => $product->custom_fields,
        ]);
    }

    public function search(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json([
                'message' => 'User does not belong to a company.'
            ], 400);
        }

        $request->validate([
            'q' => 'required|string|min:3',
        ]);

        $query = $request->input('q');

        $leads = Product::where('company_id', $user->company_id)
            ->where('name', 'like', "%{$query}%")
            ->select('id', 'name', 'base_price')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($leads);
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $query = Product::fromCompany($company->id)->with(['primaryImage']);

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->latest()->paginate($request->input('per_page', 20));

        return response()->json($products);
    }

    public function apiShow(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $product = Product::fromCompany($company->id)->with(['images', 'primaryImage'])->find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json(['data' => $product]);
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
            'sku'         => 'required|string|max:255',
            'base_price'  => 'required|numeric|min:0',
            'type'        => 'required|in:product,service',
            'category'    => 'nullable|string|max:255',
            'is_active'   => 'boolean',
            'cost_price'  => 'nullable|numeric|min:0',
            'metadata'    => 'nullable|array',
        ]);

        $product = Product::create([
            'company_id' => $company->id,
            ...$validated,
        ]);

        return response()->json([
            'message' => 'Product created successfully',
            'data'    => $product,
        ], 201);
    }

    public function apiUpdate(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $product = Product::fromCompany($company->id)->find($id);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $validated = $request->validate([
            'name'        => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'sku'         => 'nullable|string|max:255',
            'base_price'  => 'nullable|numeric|min:0',
            'type'        => 'nullable|in:product,service',
            'category'    => 'nullable|string|max:255',
            'is_active'   => 'boolean',
            'cost_price'  => 'nullable|numeric|min:0',
            'metadata'    => 'nullable|array',
        ]);

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully',
            'data'    => $product->fresh(),
        ]);
    }

    public function apiDestroy(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $product = Product::fromCompany($company->id)->find($id);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }
}