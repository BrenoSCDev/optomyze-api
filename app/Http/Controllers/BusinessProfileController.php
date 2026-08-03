<?php

namespace App\Http\Controllers;

use App\Models\CompanyFile;
use App\Services\Storage\CompanyStorage;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * BUSINESS PROFILE
 *
 * Tenant-facing view of the signed-in user's own company. Deliberately
 * separate from CompanyController, which sits behind the manager key and is
 * for platform operators.
 *
 * Commercial and internal fields are never read or written here:
 *   - product_module      (what the tenant bought)
 *   - subscription_plan   (ditto)
 *   - settings            (internal JSON blob)
 *   - *_limit / api_requests_* / storage_use / is_active
 *
 * EDITABLE is the single allow-list. Everything the endpoint returns and
 * everything it accepts is derived from it, so a field can't leak by being
 * added to the model's $fillable later.
 */
class BusinessProfileController extends Controller
{
    /**
     * Company columns the tenant may see and change.
     *
     * `contact_pipeline_enabled` is a product preference the company owns —
     * unlike subscription_plan/product_module, which are commercial and stay
     * out of reach.
     */
    private const EDITABLE = [
        'name', 'email', 'phone', 'website', 'timezone', 'currency', 'language',
        'contact_pipeline_enabled',
    ];

    private const RULES = [
        'name'     => 'sometimes|required|string|max:255',
        'email'    => 'sometimes|required|email|max:255',
        'phone'    => 'sometimes|nullable|string|max:30',
        'website'  => 'sometimes|nullable|string|max:255',
        'timezone' => 'sometimes|nullable|string|max:64',
        'currency' => 'sometimes|nullable|string|max:8',
        'language' => 'sometimes|nullable|string|max:8',
        'contact_pipeline_enabled' => 'sometimes|boolean',
    ];

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()?->company;

        if (!$company) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        return response()->json(['data' => $this->present($company)]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user?->company;

        if (!$company) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        // Only admins and managers may edit the business profile; agents can
        // read it (their sidebar shows the company name and logo).
        if (strtolower((string) $user->role) === 'agent') {
            return response()->json(['message' => 'Not authorised to edit the business profile'], 403);
        }

        $rules = self::RULES;
        $rules['email'] = 'sometimes|required|email|max:255|unique:companies,email,' . $company->id;

        $validated = $request->validate($rules);

        // Belt and braces: even if a rule were loosened, only allow-listed
        // keys reach the model.
        $company->fill(array_intersect_key($validated, array_flip(self::EDITABLE)));
        $company->save();

        return response()->json(['data' => $this->present($company->fresh())]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user?->company;

        if (!$company) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        if (strtolower((string) $user->role) === 'agent') {
            return response()->json(['message' => 'Not authorised to edit the business profile'], 403);
        }

        $request->validate([
            'logo' => 'required|file|image|mimes:jpeg,jpg,png,webp,svg|max:2048',
        ]);

        $this->deleteExistingLogo($company->logo_path);

        $company->logo_path = $request->file('logo')->store('company_logos/' . $company->id, 'local');

        CompanyStorage::record(
            $company->id,
            $company->logo_path,
            CompanyFile::CATEGORY_COMPANY_BRANDING,
            owner: $company,
        );
        $company->save();

        return response()->json(['data' => $this->present($company)]);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user?->company;

        if (!$company) {
            return response()->json(['message' => 'User does not belong to a company'], 403);
        }

        if (strtolower((string) $user->role) === 'agent') {
            return response()->json(['message' => 'Not authorised to edit the business profile'], 403);
        }

        $this->deleteExistingLogo($company->logo_path);

        $company->logo_path = null;
        $company->save();

        return response()->json(['data' => $this->present($company)]);
    }

    private function deleteExistingLogo(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
            CompanyStorage::forget($path);
        }
    }

    /** Shapes the response so nothing outside the allow-list can escape. */
    private function present($company): array
    {
        return array_merge(
            ['id' => $company->id, 'logo_path' => $company->logo_path],
            collect(self::EDITABLE)->mapWithKeys(fn ($f) => [$f => $company->{$f}])->all()
        );
    }
}
