<?php

namespace App\Http\Controllers;

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
            }

            $validated['logo_path'] = $request->file('logo')
                ->store('lead-companies/logos', 'public');
        }

        $leadCompany->update($validated);

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
}
