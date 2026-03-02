<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    /**
     * List all companies (including soft-deleted).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Company::withTrashed()->orderBy('created_at', 'desc');

        if ($request->has('active')) {
            $active = filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $active)->whereNull('deleted_at');
        }

        $companies = $query->get();

        return response()->json($companies);
    }

    /**
     * Show a single company (including soft-deleted).
     */
    public function show(int $id): JsonResponse
    {
        $company = Company::withTrashed()->findOrFail($id);

        return response()->json($company);
    }

    /**
     * Store a newly created company.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'email'             => 'required|email|unique:companies,email',
            'phone'             => 'nullable|string|max:30',
            'website'           => 'nullable|string|max:255',
            'subscription_plan' => 'required|in:basic,premium,enterprise',
            'product_module'    => 'nullable|in:product,ERP',
            'settings'          => 'nullable|array',
        ]);

        $limits = Company::planLimits($validated['subscription_plan']);

        $company = Company::create([
            'name'                 => $validated['name'],
            'email'                => $validated['email'],
            'phone'                => $validated['phone'] ?? null,
            'website'              => $validated['website'] ?? null,
            'subscription_plan'    => $validated['subscription_plan'],
            'product_module'       => $validated['product_module'] ?? 'product',
            'settings'             => $validated['settings'] ?? null,
            'is_active'            => true,
            ...$limits,
        ]);

        RolePermission::seedDefaultsForCompany($company->id);

        return response()->json($company, 201);
    }

    /**
     * Update a company. Re-applies plan limits when subscription_plan changes.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $company = Company::withTrashed()->findOrFail($id);

        $validated = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'email'             => 'sometimes|email|unique:companies,email,' . $id,
            'phone'             => 'nullable|string|max:30',
            'website'           => 'nullable|string|max:255',
            'subscription_plan' => 'sometimes|in:basic,premium,enterprise',
            'product_module'    => 'nullable|in:product,ERP',
            'is_active'         => 'sometimes|boolean',
            'settings'          => 'nullable|array',
        ]);

        // Re-apply plan limits when plan changes
        if (
            isset($validated['subscription_plan']) &&
            $validated['subscription_plan'] !== $company->subscription_plan
        ) {
            $validated = array_merge($validated, Company::planLimits($validated['subscription_plan']));
        }

        $company->update($validated);

        return response()->json($company->fresh());
    }

    /**
     * Soft-delete a company.
     */
    public function destroy(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $company->delete();

        return response()->json(['message' => 'Company deleted successfully']);
    }

    /**
     * Restore a soft-deleted company.
     */
    public function restore(int $id): JsonResponse
    {
        $company = Company::onlyTrashed()->findOrFail($id);
        $company->restore();

        return response()->json($company->fresh());
    }
}
