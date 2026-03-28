<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadCustomField;
use Illuminate\Http\Request;

class LeadCustomFieldController extends Controller
{
    /**
     * List all model-level (default) lead custom fields for the company.
     */
    public function index(Request $request)
    {
        $fields = LeadCustomField::where('company_id', $request->user()->company_id)
            ->where('type', 'model')
            ->get();

        return response()->json($fields);
    }

    /**
     * Create a model-level or lead-level custom field.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'field_key'   => 'required|string|max:255',
            'field_type'  => 'required|string|in:title,text,number,boolean,json,date',
            'field_value' => 'nullable',
            'lead_id'     => 'nullable|exists:leads,id',
            'type'        => 'nullable|in:model,lead',
        ]);

        $field = LeadCustomField::create([
            'company_id'  => $request->user()->company_id,
            'type'        => $validated['type'] ?? 'model',
            'lead_id'     => $validated['lead_id'] ?? null,
            'field_key'   => $validated['field_key'],
            'field_type'  => $validated['field_type'],
            'field_value' => $validated['field_value'] ?? null,
        ]);

        return response()->json($field, 201);
    }

    /**
     * Update a lead custom field.
     */
    public function update(Request $request, LeadCustomField $leadCustomField)
    {
        $validated = $request->validate([
            'field_key'   => 'required|string|max:255',
            'field_type'  => 'required|string|in:title,text,number,boolean,json,date',
            'field_value' => 'nullable',
            'lead_id'     => 'nullable|exists:leads,id',
            'type'        => 'nullable|in:model,lead',
        ]);

        $leadCustomField->update([
            'type'        => $validated['type'] ?? $leadCustomField->type,
            'lead_id'     => $validated['lead_id'] ?? $leadCustomField->lead_id,
            'field_key'   => $validated['field_key'],
            'field_type'  => $validated['field_type'],
            'field_value' => $validated['field_value'] ?? null,
        ]);

        return response()->json($leadCustomField);
    }

    /**
     * Delete a lead custom field.
     */
    public function destroy(LeadCustomField $leadCustomField)
    {
        $leadCustomField->delete();

        return response()->json(['message' => 'Lead custom field deleted successfully.']);
    }

    /**
     * List custom fields for a specific lead.
     */
    public function forLead(Request $request, Lead $lead)
    {
        $fields = LeadCustomField::where('lead_id', $lead->id)
            ->where('type', 'lead')
            ->get();

        return response()->json($fields);
    }

    /**
     * Store or update a custom field value on a specific lead.
     */
    public function storeForLead(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'field_key'   => 'required|string|max:255',
            'field_type'  => 'required|string|in:title,text,number,boolean,json,date',
            'field_value' => 'nullable',
        ]);

        $field = LeadCustomField::updateOrCreate(
            [
                'company_id' => $lead->company_id,
                'lead_id'    => $lead->id,
                'field_key'  => $validated['field_key'],
                'type'       => 'lead',
            ],
            [
                'field_type'  => $validated['field_type'],
                'field_value' => $validated['field_value'] ?? null,
            ]
        );

        return response()->json($field, 201);
    }

    /**
     * Delete a specific custom field from a lead.
     */
    public function destroyForLead(Request $request, Lead $lead, string $fieldKey)
    {
        LeadCustomField::where('lead_id', $lead->id)
            ->where('field_key', $fieldKey)
            ->where('type', 'lead')
            ->delete();

        return response()->json(['message' => 'Field removed from lead successfully.']);
    }
}
