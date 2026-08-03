<?php

namespace App\Http\Controllers;

use App\Models\CompanyFile;
use App\Services\Storage\CompanyStorage;

use App\Models\LeadCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class LeadCompanyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            LeadCompany::withCount('leads')
                ->where('company_id', Auth::user()->company_id)
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'legal_name' => 'required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'tax_id'     => 'nullable|string|max:100',

            'industry'   => 'nullable|string|max:255',
            'company_size' => 'nullable|string|max:50',
            'website'    => 'nullable|url',

            'email'      => 'nullable|email',
            'phone'      => 'nullable|string|max:50',
            'secondary_phone' => 'nullable|string|max:50',

            'street'     => 'nullable|string|max:255',
            'number'     => 'nullable|string|max:50',
            'complement' => 'nullable|string|max:255',
            'neighborhood' => 'nullable|string|max:255',
            'city'       => 'nullable|string|max:255',
            'state'      => 'nullable|string|max:255',
            'postal_code'=> 'nullable|string|max:50',
            'country'    => 'nullable|string|max:255',

            'source'     => 'nullable|string|max:255',
            'status'     => 'nullable|string|max:50',

            'notes'      => 'nullable|string',

            'logo'       => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo_path'] = $request->file('logo')
                ->store('lead-companies/logos', 'public');
        }

        $leadCompany = LeadCompany::create([
            ...$validated,
            'company_id' => Auth::user()->company_id,
        ]);

        // These logos land in a shared `lead-companies/logos` folder on the
        // public disk rather than a per-company one, so the ledger is the only
        // thing that knows whose bytes they are.
        if (!empty($validated['logo_path'])) {
            CompanyStorage::record(
                $leadCompany->company_id,
                $validated['logo_path'],
                CompanyFile::CATEGORY_COMPANY_BRANDING,
                disk: 'public',
                owner: $leadCompany,
            );
        }

        return response()->json($leadCompany, 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(
            LeadCompany::with('leads')
                ->where('company_id', Auth::user()->company_id)
                ->findOrFail($id)
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $leadCompany = LeadCompany::where('company_id', Auth::user()->company_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'legal_name' => 'sometimes|required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'tax_id'     => 'nullable|string|max:100',

            'industry'   => 'nullable|string|max:255',
            'company_size' => 'nullable|string|max:50',
            'website'    => 'nullable|url',

            'email'      => 'nullable|email',
            'phone'      => 'nullable|string|max:50',
            'secondary_phone' => 'nullable|string|max:50',

            'street'     => 'nullable|string|max:255',
            'number'     => 'nullable|string|max:50',
            'complement' => 'nullable|string|max:255',
            'neighborhood' => 'nullable|string|max:255',
            'city'       => 'nullable|string|max:255',
            'state'      => 'nullable|string|max:255',
            'postal_code'=> 'nullable|string|max:50',
            'country'    => 'nullable|string|max:255',

            'source'     => 'nullable|string|max:255',
            'status'     => 'nullable|string|max:50',

            'notes'      => 'nullable|string',
            'logo'       => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            if ($leadCompany->logo_path) {
                Storage::disk('public')->delete($leadCompany->logo_path);
                CompanyStorage::forget($leadCompany->logo_path, 'public');
            }

            $validated['logo_path'] = $request->file('logo')
                ->store('lead-companies/logos', 'public');
        }

        $leadCompany->update($validated);

        if (!empty($validated['logo_path'])) {
            CompanyStorage::record(
                $leadCompany->company_id,
                $validated['logo_path'],
                CompanyFile::CATEGORY_COMPANY_BRANDING,
                disk: 'public',
                owner: $leadCompany,
            );
        }

        return response()->json($leadCompany);
    }

    public function destroy(int $id): JsonResponse
    {
        $leadCompany = LeadCompany::where('company_id', Auth::user()->company_id)
            ->findOrFail($id);

        if ($leadCompany->logo_path) {
            Storage::disk('public')->delete($leadCompany->logo_path);
        }

        $leadCompany->delete();

        return response()->json(['message' => 'Lead company deleted']);
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $companies = LeadCompany::where('company_id', $company->id)
            ->withCount('leads')
            ->paginate($request->input('per_page', 20));

        return response()->json($companies);
    }

    public function apiShow(Request $request, int $id): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $leadCompany = LeadCompany::where('company_id', $company->id)->find($id);
        if (!$leadCompany) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        return response()->json(['data' => $leadCompany]);
    }

    public function apiStore(Request $request): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $validated = $request->validate([
            'legal_name'      => 'required|string|max:255',
            'trade_name'      => 'nullable|string|max:255',
            'tax_id'          => 'nullable|string|max:100',
            'industry'        => 'nullable|string|max:255',
            'company_size'    => 'nullable|string|max:50',
            'website'         => 'nullable|url',
            'email'           => 'nullable|email',
            'phone'           => 'nullable|string|max:50',
            'secondary_phone' => 'nullable|string|max:50',
            'street'          => 'nullable|string|max:255',
            'number'          => 'nullable|string|max:50',
            'complement'      => 'nullable|string|max:255',
            'neighborhood'    => 'nullable|string|max:255',
            'city'            => 'nullable|string|max:255',
            'state'           => 'nullable|string|max:255',
            'postal_code'     => 'nullable|string|max:50',
            'country'         => 'nullable|string|max:255',
            'source'          => 'nullable|string|max:255',
            'status'          => 'nullable|string|max:50',
            'notes'           => 'nullable|string',
        ]);

        $leadCompany = LeadCompany::create([
            ...$validated,
            'company_id' => $company->id,
        ]);

        return response()->json([
            'message' => 'Company created successfully',
            'data'    => $leadCompany,
        ], 201);
    }

    public function apiUpdate(Request $request, int $id): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $leadCompany = LeadCompany::where('company_id', $company->id)->find($id);
        if (!$leadCompany) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $validated = $request->validate([
            'legal_name'      => 'nullable|string|max:255',
            'trade_name'      => 'nullable|string|max:255',
            'tax_id'          => 'nullable|string|max:100',
            'industry'        => 'nullable|string|max:255',
            'company_size'    => 'nullable|string|max:50',
            'website'         => 'nullable|url',
            'email'           => 'nullable|email',
            'phone'           => 'nullable|string|max:50',
            'secondary_phone' => 'nullable|string|max:50',
            'street'          => 'nullable|string|max:255',
            'number'          => 'nullable|string|max:50',
            'complement'      => 'nullable|string|max:255',
            'neighborhood'    => 'nullable|string|max:255',
            'city'            => 'nullable|string|max:255',
            'state'           => 'nullable|string|max:255',
            'postal_code'     => 'nullable|string|max:50',
            'country'         => 'nullable|string|max:255',
            'source'          => 'nullable|string|max:255',
            'status'          => 'nullable|string|max:50',
            'notes'           => 'nullable|string',
        ]);

        $leadCompany->update($validated);

        return response()->json([
            'message' => 'Company updated successfully',
            'data'    => $leadCompany->fresh(),
        ]);
    }

    public function apiDestroy(Request $request, int $id): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $leadCompany = LeadCompany::where('company_id', $company->id)->find($id);
        if (!$leadCompany) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $leadCompany->delete();

        return response()->json(['message' => 'Company deleted successfully']);
    }
}
