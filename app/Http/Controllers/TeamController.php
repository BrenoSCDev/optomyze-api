<?php

namespace App\Http\Controllers;

use App\Models\Team;
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
}
