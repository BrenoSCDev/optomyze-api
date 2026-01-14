<?php

namespace App\Http\Controllers;

use App\Models\Stage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EntryRequirementController extends Controller
{
    public function update(Request $request, Stage $stage)
    {
        // Ensure EntryRequirement exists
        $entryRequirement = $stage->entryRequirement()->firstOrCreate([]);

        // Allowed fields (one at a time)
        $allowedFields = [
            'require_assigned_owner',
            'require_company_linked',
            'require_contact_email',
            'require_phone_number',
            'require_at_least_one_product',
            'require_deal_value',
            'require_recent_activity',
        ];

        // Validate: exactly ONE field present
        $validator = Validator::make($request->all(), [
            'field' => ['required', 'string', 'in:' . implode(',', $allowedFields)],
            'value' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid request.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $field = $request->input('field');
        $value = $request->boolean('value');

        // Update single field
        $entryRequirement->update([
            $field => $value,
        ]);

        return response()->json([
            'message' => 'Entry requirement updated successfully.',
            'data' => [
                'stage_id' => $stage->id,
                'field' => $field,
                'value' => $entryRequirement->$field,
            ],
        ]);
    }

}
