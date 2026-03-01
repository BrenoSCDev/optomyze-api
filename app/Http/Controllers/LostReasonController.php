<?php

namespace App\Http\Controllers;

use App\Models\LostReason;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LostReasonController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $reasons = LostReason::fromCompany($user->company_id)
            ->latest()
            ->get();

        return response()->json($reasons);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'description' => 'required|string|max:255',
        ]);

        $reason = LostReason::create([
            'company_id'  => $user->company_id,
            'description' => $data['description'],
        ]);

        return response()->json($reason, 201);
    }

    public function update(Request $request, LostReason $lostReason)
    {
        $user = Auth::user();

        if ($lostReason->company_id !== $user->company_id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'description' => 'required|string|max:255',
        ]);

        $lostReason->update($data);

        return response()->json($lostReason);
    }

    public function destroy(LostReason $lostReason)
    {
        $user = Auth::user();

        if ($lostReason->company_id !== $user->company_id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $lostReason->delete();

        return response()->json(['message' => 'Lost reason deleted successfully']);
    }
}
