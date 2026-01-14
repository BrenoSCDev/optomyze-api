<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Get all users from the authenticated user's company.
     */
    public function index()
    {
        $companyId = Auth::user()->company_id;

        $users = User::where('company_id', $companyId)->get();

        return response()->json($users);
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

        $validated['password'] = 'changeme';

        $user = User::create($validated);

        // dd($user->password);


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
}
