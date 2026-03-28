<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    /* ===================== LIST ===================== */

    public function index(): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $teams = Team::fromCompany($companyId)
            ->with(['manager:id,name,email', 'users:id,name,email'])
            ->latest()
            ->get();

        return response()->json($teams);
    }

    /* ===================== SHOW ===================== */

    public function show(Team $team): JsonResponse
    {
        $this->authorizeCompany($team);

        return response()->json($team->load([
            'manager:id,name,email',
            'users:id,name,email',
        ]));
    }

    /* ===================== CREATE ===================== */

    public function store(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'manager_user_id' => [
                'required',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'users'           => 'nullable|array',
            'users.*.id'      => [
                'required',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'users.*.role'    => 'nullable|string',
        ]);

        $team = Team::create([
            'company_id'      => $companyId,
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'manager_user_id' => $data['manager_user_id'],
        ]);

        if (!empty($data['users'])) {
            $team->users()->sync(
                collect($data['users'])->mapWithKeys(fn ($u) => [
                    $u['id'] => ['role' => $u['role'] ?? null],
                ])->toArray()
            );
        }

        return response()->json($team->load(['manager:id,name,email', 'users:id,name,email']), 201);
    }

    /* ===================== UPDATE ===================== */

    public function update(Request $request, Team $team): JsonResponse
    {
        $this->authorizeCompany($team);

        $companyId = Auth::user()->company_id;

        $data = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'description'     => 'nullable|string',
            'manager_user_id' => [
                'sometimes',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'users'           => 'nullable|array',
            'users.*.id'      => [
                'required',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'users.*.role'    => 'nullable|string',
        ]);

        $team->update($data);

        if (array_key_exists('users', $data)) {
            $team->users()->sync(
                collect($data['users'])->mapWithKeys(fn ($u) => [
                    $u['id'] => ['role' => $u['role'] ?? null],
                ])->toArray()
            );
        }

        return response()->json($team->fresh()->load(['manager:id,name,email', 'users:id,name,email']));
    }

    /* ===================== DELETE ===================== */

    public function destroy(Team $team): JsonResponse
    {
        $this->authorizeCompany($team);

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully']);
    }

    /* ===================== HELPER ===================== */

    /**
     * Abort with 403 if the team does not belong to the authenticated user's company.
     */
    private function authorizeCompany(Team $team): void
    {
        abort_if(
            $team->company_id !== Auth::user()->company_id,
            403,
            'This team does not belong to your company.'
        );
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $teams = Team::fromCompany($company->id)
            ->with(['manager:id,name,email', 'users:id,name,email'])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($teams);
    }

    public function apiShow(Request $request, int $id): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $team = Team::fromCompany($company->id)
            ->with(['manager:id,name,email', 'users:id,name,email'])
            ->find($id);

        if (!$team) {
            return response()->json(['message' => 'Team not found'], 404);
        }

        return response()->json(['data' => $team]);
    }

    public function apiStore(Request $request): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'manager_user_id' => [
                'required',
                Rule::exists('users', 'id')->where('company_id', $company->id),
            ],
            'users'           => 'nullable|array',
            'users.*.id'      => [
                'required',
                Rule::exists('users', 'id')->where('company_id', $company->id),
            ],
            'users.*.role'    => 'nullable|string',
        ]);

        $team = Team::create([
            'company_id'      => $company->id,
            'name'            => $validated['name'],
            'description'     => $validated['description'] ?? null,
            'manager_user_id' => $validated['manager_user_id'],
        ]);

        if (!empty($validated['users'])) {
            $team->users()->sync(
                collect($validated['users'])->mapWithKeys(fn ($u) => [
                    $u['id'] => ['role' => $u['role'] ?? null],
                ])->toArray()
            );
        }

        return response()->json([
            'message' => 'Team created successfully',
            'data'    => $team->load(['manager:id,name,email', 'users:id,name,email']),
        ], 201);
    }

    public function apiUpdate(Request $request, int $id): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $team = Team::fromCompany($company->id)->find($id);
        if (!$team) {
            return response()->json(['message' => 'Team not found'], 404);
        }

        $validated = $request->validate([
            'name'            => 'nullable|string|max:255',
            'description'     => 'nullable|string',
            'manager_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('company_id', $company->id),
            ],
            'users'           => 'nullable|array',
            'users.*.id'      => [
                'required',
                Rule::exists('users', 'id')->where('company_id', $company->id),
            ],
            'users.*.role'    => 'nullable|string',
        ]);

        $team->update($validated);

        if (array_key_exists('users', $validated)) {
            $team->users()->sync(
                collect($validated['users'])->mapWithKeys(fn ($u) => [
                    $u['id'] => ['role' => $u['role'] ?? null],
                ])->toArray()
            );
        }

        return response()->json([
            'message' => 'Team updated successfully',
            'data'    => $team->fresh()->load(['manager:id,name,email', 'users:id,name,email']),
        ]);
    }

    public function apiDestroy(Request $request, int $id): JsonResponse
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $team = Team::fromCompany($company->id)->find($id);
        if (!$team) {
            return response()->json(['message' => 'Team not found'], 404);
        }

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully']);
    }
}
