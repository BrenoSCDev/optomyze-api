<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\FirstAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ManagerUserController extends Controller
{
    public function __construct(private FirstAccessService $firstAccessService)
    {
    }

    /**
     * List all users for a given company.
     *
     * Required query param: ?company_id=X
     * Optional query params: ?active=true|false, ?role=admin|manager|agent
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'active'     => 'sometimes|boolean',
            'role'       => 'sometimes|in:admin,manager,agent',
        ]);

        $query = User::where('company_id', $request->integer('company_id'));

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($request->has('role')) {
            $query->where('role', $request->input('role'));
        }

        $users = $query
            ->select('id', 'name', 'email', 'phone', 'role', 'is_active', 'last_login_at', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($users);
    }

    /**
     * Show a single user (any company).
     */
    public function show(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        return response()->json($user->makeHidden(['password', 'remember_token']));
    }

    /**
     * Create a user inside a specific company.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'name'       => 'required|string|max:255',
            'email'      => [
                'required', 'string', 'email', 'max:255',
                // Scoped to the company being created in: the same person may
                // already hold an account elsewhere.
                User::emailUniqueRule((int) $request->input('company_id') ?: null),
            ],
            'phone'      => 'nullable|string|max:20',
            'role'       => 'required|in:admin,manager,agent',
            'is_active'  => 'sometimes|boolean',
            'password'   => 'sometimes|string|min:8',
        ]);

        $passwordProvided = isset($validated['password']);

        $user = User::create([
            'company_id' => $validated['company_id'],
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'phone'      => $validated['phone'] ?? null,
            'role'       => $validated['role'],
            'is_active'  => $validated['is_active'] ?? true,
            'password'   => $passwordProvided
                ? Hash::make($validated['password'])
                : Hash::make(Str::random(32)),
        ]);

        if (!$passwordProvided) {
            $this->firstAccessService->send($user);
        }

        return response()->json($user->makeHidden(['password', 'remember_token']), 201);
    }

    /**
     * Update a user's details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'email'     => ['sometimes', 'string', 'email', 'max:255', User::emailUniqueRule($user->company_id, $user->id)],
            'phone'     => 'nullable|string|max:20',
            'role'      => 'sometimes|in:admin,manager,agent',
            'is_active' => 'sometimes|boolean',
            'password'  => 'sometimes|string|min:8',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json($user->fresh()->makeHidden(['password', 'remember_token']));
    }

    /**
     * Deactivate a user (sets is_active = false).
     */
    public function deactivate(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['is_active' => false]);
        $user->revokeSessions();

        return response()->json([
            'message' => 'User deactivated successfully',
            'user'    => $user->fresh()->makeHidden(['password', 'remember_token']),
        ]);
    }

    /**
     * Permanently delete a user.
     */
    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->revokeSessions();
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}
