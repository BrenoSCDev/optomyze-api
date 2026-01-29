<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
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

        // Get limits based on plan
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

            // Apply limits
            'leads_limit'          => $limits['leads_limit'],
            'users_limit'          => $limits['users_limit'],
            'storage_limit_gb'     => $limits['storage_limit_gb'],
            'active_deals_limit'   => $limits['active_deals_limit'],
            'tasks_limit'          => $limits['tasks_limit'],
            'integrations_limit'   => $limits['integrations_limit'],
            'products_limit'       => $limits['products_limit'],
        ]);

        return response()->json($company, 201);
    }
}
