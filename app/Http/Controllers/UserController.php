<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use App\Services\FirstAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private FirstAccessService $firstAccessService)
    {
    }

    /**
     * Get all users from the authenticated user's company.
     */
    public function index()
    {
        $companyId = Auth::user()->company_id;

        $users = User::where('company_id', $companyId)
            ->with(['teams:id,name', 'managedTeams:id,name,manager_user_id'])
            ->get();

        return response()->json($users);
    }

    /**
     * Get a single user from the authenticated user's company.
     */
    public function show($id)
    {
        $companyId = Auth::user()->company_id;

        $user = User::where('company_id', $companyId)
            ->with(['teams:id,name', 'managedTeams:id,name,manager_user_id'])
            ->findOrFail($id);

        return response()->json(['data' => $user]);
    }

    /**
     * Get performance stats for a user (leads/deals assigned & converted, tasks completed).
     */
    public function performance($id)
    {
        $companyId = Auth::user()->company_id;

        // Ensure the user belongs to the same company before computing stats.
        User::where('company_id', $companyId)->findOrFail($id);

        $leadsAssigned = Lead::where('company_id', $companyId)
            ->where('assigned_to', $id)
            ->count();

        $leadsConverted = Lead::where('leads.company_id', $companyId)
            ->where('leads.assigned_to', $id)
            ->join('stages', 'leads.stage_id', '=', 'stages.id')
            ->where('stages.type', 'conversion')
            ->count();

        $dealsAssigned = Sale::where('company_id', $companyId)
            ->where('user_id', $id)
            ->count();

        $dealsClosed = Sale::where('company_id', $companyId)
            ->where('user_id', $id)
            ->where('status', 'closed')
            ->count();

        $tasksCompleted = Task::where('assigned_to', $id)
            ->where('status', 'completed')
            ->whereHas('lead', fn($q) => $q->where('company_id', $companyId))
            ->count();

        return response()->json([
            'data' => [
                'leads_assigned' => $leadsAssigned,
                'deals_assigned' => $dealsAssigned,
                'leads_converted' => $leadsConverted,
                'deals_closed' => $dealsClosed,
                'tasks_completed' => $tasksCompleted,
            ],
        ]);
    }

    /**
     * Create a new user within the same company as the authenticated user.
     */
    public function store(Request $request)
    {
        $companyId = Auth::user()->company_id;

        // Merge company_id into the request
        $request->merge(['company_id' => $companyId])->all();

        $validated = $request->validate(User::validationRules());

        $validated['password'] = Hash::make(Str::random(32));

        $user = User::create($validated);

        $this->firstAccessService->send($user);

        return response()->json($user, 201);
    }

    /**
     * Create a new user from Optomyze Manager.
     */
    public function storeManager(Request $request)
    {
        $validated = $request->validate(User::validationRules());

        $validated['password'] = Hash::make(Str::random(32));

        $user = User::create($validated);

        $this->firstAccessService->send($user);

        return response()->json($user, 201);
    }

    /**
     * Update an existing user.
     */
    public function update(Request $request, $id)
    {
        $companyId = Auth::user()->company_id;

        $user = User::where('company_id', $companyId)->findOrFail($id);

        $rules = User::validationRules();

        // Adjust email unique validation for update
        $rules['email'] = [
            'required',
            'string',
            'email',
            'max:255',
            Rule::unique('users', 'email')->ignore($user->id),
        ];

        // company_id is set once at creation and scoped via $companyId above —
        // this endpoint doesn't let the company be changed, so drop the
        // "required" rule rather than asking the client to resend it.
        unset($rules['company_id']);

        $validated = $request->validate($rules);

        $user->update($validated);

        return response()->json($user);
    }

    /**
     * Enable/Disable a user.
     */
    public function toggleStatus($id)
    {
        $companyId = Auth::user()->company_id;

        $user = User::where('company_id', $companyId)->findOrFail($id);

        $user->is_active = !$user->is_active;
        $user->save();

        return response()->json([
            'message' => $user->is_active ? 'User enabled' : 'User disabled',
            'user' => $user
        ]);
    }

    /**
     * Delete a user.
     */
    public function destroy($id)
    {
        $companyId = Auth::user()->company_id;

        $user = User::where('company_id', $companyId)->findOrFail($id);

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function search(Request $request)
    {
        $request->validate(['q' => 'required|string|min:1']);

        $companyId = Auth::user()->company_id;
        $query     = $request->input('q');

        $users = User::where('company_id', $companyId)
            ->where('name', 'like', "%{$query}%")
            ->select('id', 'name', 'email', 'role', 'profile_pic')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($users);
    }

    //////////////////// INTERNAL CRM API METHODS //////////////////////////

    public function apiIndex(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $query = User::where('company_id', $company->id);

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        $users = $query
            ->select('id', 'name', 'email', 'phone', 'role', 'is_active', 'created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json($users);
    }

    public function apiShow(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $user = User::where('company_id', $company->id)
            ->select('id', 'name', 'email', 'phone', 'role', 'is_active', 'last_login_at', 'created_at')
            ->find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        return response()->json(['data' => $user]);
    }

    public function apiStore(Request $request)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users,email',
            'phone'     => 'nullable|string|max:20',
            'role'      => 'required|in:admin,manager,agent',
            'is_active' => 'boolean',
        ]);

        $user = User::create([
            ...$validated,
            'company_id' => $company->id,
            'password'   => Str::random(32),
        ]);

        $this->firstAccessService->send($user);

        return response()->json([
            'message' => 'User created successfully',
            'data'    => $user->only(['id', 'name', 'email', 'phone', 'role', 'is_active', 'created_at']),
        ], 201);
    }

    public function apiUpdate(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $user = User::where('company_id', $company->id)->find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'name'      => 'nullable|string|max:255',
            'email'     => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'     => 'nullable|string|max:20',
            'role'      => 'nullable|in:admin,manager,agent',
            'is_active' => 'boolean',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully',
            'data'    => $user->fresh()->only(['id', 'name', 'email', 'phone', 'role', 'is_active']),
        ]);
    }

    public function apiDestroy(Request $request, int $id)
    {
        $company = $request->get('company');
        if (!$company) {
            return response()->json(['message' => 'Unauthorized: no company found for token'], 401);
        }

        $user = User::where('company_id', $company->id)->find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}
