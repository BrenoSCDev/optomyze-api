<?php
namespace App\Http\Controllers;

use App\Models\AppointmentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AppointmentTypeController extends Controller
{
    public function index()
    {
        $types = AppointmentType::where('company_id', Auth::user()->company_id)
            ->orderBy('name')
            ->get();
        return response()->json($types);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'duration_minutes' => 'sometimes|integer|min:5|max:480',
            'color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);
        $data['company_id'] = Auth::user()->company_id;
        $type = AppointmentType::create($data);
        return response()->json($type, 201);
    }

    public function update(Request $request, AppointmentType $appointmentType)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'duration_minutes' => 'sometimes|integer|min:5|max:480',
            'color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);
        $appointmentType->update($data);
        return response()->json($appointmentType);
    }

    public function destroy(AppointmentType $appointmentType)
    {
        $appointmentType->delete();
        return response()->noContent();
    }
}
