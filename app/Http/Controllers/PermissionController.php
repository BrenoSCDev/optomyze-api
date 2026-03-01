<?php

namespace App\Http\Controllers;

use App\Models\Permission;

class PermissionController extends Controller
{
    /**
     * Return all permissions grouped by their group key.
     */
    public function index()
    {
        $permissions = Permission::orderBy('group')->orderBy('key')->get();

        $grouped = $permissions->groupBy('group')->map(fn ($items) => $items->values());

        return response()->json($grouped);
    }
}
