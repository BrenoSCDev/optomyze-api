<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /* ===================== LIST ===================== */
    public function index()
    {
        return Team::with([
            'manager:id,name,email',
            'users:id,name,email',
        ])->latest()->get();
    }

    /* ===================== SHOW ===================== */
    public function show(Team $team)
    {
        return $team->load([
            'manager:id,name,email',
            'users:id,name,email',
        ]);
    }

    /* ===================== CREATE ===================== */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'manager_user_id'  => 'required|exists:users,id',

            'users'            => 'nullable|array',
            'users.*.id'       => 'required|exists:users,id',
            'users.*.role'     => 'nullable|string',
        ]);

        $team = Team::create([
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'manager_user_id' => $data['manager_user_id'],
        ]);

        if (!empty($data['users'])) {
            $team->users()->sync(
                collect($data['users'])->mapWithKeys(fn ($user) => [
                    $user['id'] => [
                        'role' => $user['role'] ?? null,
                    ],
                ])->toArray()
            );
        }

        return $team->load(['manager', 'users']);
    }

    /* ===================== UPDATE ===================== */
    public function update(Request $request, Team $team)
    {
        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'description'      => 'nullable|string',
            'manager_user_id'  => 'sometimes|exists:users,id',

            'users'            => 'nullable|array',
            'users.*.id'       => 'required|exists:users,id',
            'users.*.role'     => 'nullable|string',
        ]);

        $team->update($data);

        if (array_key_exists('users', $data)) {
            $team->users()->sync(
                collect($data['users'])->mapWithKeys(fn ($user) => [
                    $user['id'] => [
                        'role' => $user['role'] ?? null,
                    ],
                ])->toArray()
            );
        }

        return $team->load(['manager', 'users']);
    }

    /* ===================== DELETE ===================== */
    public function destroy(Team $team)
    {
        $team->delete();

        return response()->json([
            'message' => 'Team deleted successfully',
        ]);
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $teams = Team::whereHas('manager', fn ($q) => $q->where('company_id', $company->id))
            ->with(['manager:id,name,email', 'users:id,name,email'])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($teams);
    }

    public function apiShow(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $team = Team::whereHas('manager', fn ($q) => $q->where('company_id', $company->id))
            ->with(['manager:id,name,email', 'users:id,name,email'])
            ->find($id);

        if (!$team) {
            return response()->json(['message' => 'Team not found'], 404);
        }

        return response()->json(['data' => $team]);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'manager_user_id' => 'required|exists:users,id',
            'users'           => 'nullable|array',
            'users.*.id'      => 'required|exists:users,id',
            'users.*.role'    => 'nullable|string',
        ]);

        $manager = User::where('id', $validated['manager_user_id'])
            ->where('company_id', $company->id)
            ->first();

        if (!$manager) {
            return response()->json(['message' => 'Manager user not found in this company'], 422);
        }

        $team = Team::create([
            'name'            => $validated['name'],
            'description'     => $validated['description'] ?? null,
            'manager_user_id' => $validated['manager_user_id'],
        ]);

        if (!empty($validated['users'])) {
            $team->users()->sync(
                collect($validated['users'])->mapWithKeys(fn ($user) => [
                    $user['id'] => ['role' => $user['role'] ?? null],
                ])->toArray()
            );
        }

        return response()->json([
            'message' => 'Team created successfully',
            'data'    => $team->load(['manager:id,name,email', 'users:id,name,email']),
        ], 201);
    }

    public function apiUpdate(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $team = Team::whereHas('manager', fn ($q) => $q->where('company_id', $company->id))->find($id);
        if (!$team) {
            return response()->json(['message' => 'Team not found'], 404);
        }

        $validated = $request->validate([
            'name'            => 'nullable|string|max:255',
            'description'     => 'nullable|string',
            'manager_user_id' => 'nullable|exists:users,id',
            'users'           => 'nullable|array',
            'users.*.id'      => 'required|exists:users,id',
            'users.*.role'    => 'nullable|string',
        ]);

        if (isset($validated['manager_user_id'])) {
            $manager = User::where('id', $validated['manager_user_id'])
                ->where('company_id', $company->id)
                ->first();

            if (!$manager) {
                return response()->json(['message' => 'Manager user not found in this company'], 422);
            }
        }

        $team->update($validated);

        if (array_key_exists('users', $validated)) {
            $team->users()->sync(
                collect($validated['users'])->mapWithKeys(fn ($user) => [
                    $user['id'] => ['role' => $user['role'] ?? null],
                ])->toArray()
            );
        }

        return response()->json([
            'message' => 'Team updated successfully',
            'data'    => $team->fresh()->load(['manager:id,name,email', 'users:id,name,email']),
        ]);
    }

    public function apiDestroy(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $team = Team::whereHas('manager', fn ($q) => $q->where('company_id', $company->id))->find($id);
        if (!$team) {
            return response()->json(['message' => 'Team not found'], 404);
        }

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully']);
    }
}
