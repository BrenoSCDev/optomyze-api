<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /**
     * Return all known roles with their assigned permission keys (scoped to company).
     */
    public function index()
    {
        $companyId = Auth::user()->company_id;

        $byRole = RolePermission::select('role', 'permission_key')
            ->where('company_id', $companyId)
            ->orderBy('role')
            ->orderBy('permission_key')
            ->get()
            ->groupBy('role')
            ->map(fn ($rows) => $rows->pluck('permission_key')->values());

        return response()->json($byRole);
    }

    /**
     * Return the permission keys assigned to a specific role (scoped to company).
     */
    public function show(string $role)
    {
        $companyId = Auth::user()->company_id;

        $keys = RolePermission::where('company_id', $companyId)
            ->where('role', $role)
            ->orderBy('permission_key')
            ->pluck('permission_key');

        return response()->json([
            'role'        => $role,
            'permissions' => $keys,
        ]);
    }

    /**
     * Sync (replace) all permissions for a role within the authenticated user's company.
     * Body: { "permissions": ["leads.view", "leads.create", ...] }
     */
    public function update(Request $request, string $role)
    {
        $companyId = Auth::user()->company_id;

        $data = $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string|exists:permissions,key',
        ]);

        DB::transaction(function () use ($role, $companyId, $data) {
            DB::table('role_permissions')
                ->where('company_id', $companyId)
                ->where('role', $role)
                ->delete();

            $rows = array_map(
                fn ($key) => ['company_id' => $companyId, 'role' => $role, 'permission_key' => $key],
                array_unique($data['permissions'])
            );

            if (!empty($rows)) {
                DB::table('role_permissions')->insert($rows);
            }
        });

        $updated = RolePermission::where('company_id', $companyId)
            ->where('role', $role)
            ->pluck('permission_key');

        return response()->json([
            'message'     => "Permissions for role '{$role}' updated successfully.",
            'role'        => $role,
            'permissions' => $updated,
        ]);
    }
}
