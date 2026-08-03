<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\CompanyFile;
use App\Services\Storage\CompanyStorage;

class LeadDocumentController extends Controller
{
    /**
     * Store document
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file' => 'required|file|max:20480', // 20MB
        ]);

        $file = $request->file('file');
        $path = $file->store('lead-documents/' . $request->user()->company_id, 'local');

        $document = LeadDocument::create([
            'lead_id' => $validated['lead_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'path' => $path,
        ]);

        CompanyStorage::record(
            $request->user()->company_id,
            $path,
            CompanyFile::CATEGORY_LEAD_DOCUMENT,
            owner: $document,
            mime: $file->getClientMimeType(),
        );

        $lead = Lead::find($validated['lead_id']);
        if ($lead) {
            Record::createLeadDocumentAdded($document, $lead, $request->user());
        }

        return response()->json([
            'message' => 'Documento criado com sucesso',
            'data' => $document
        ], 201);
    }

    /**
     * Delete document
     */
    public function destroy(LeadDocument $leadDocument)
    {
        if ($leadDocument->path) {
            Storage::disk('local')->delete($leadDocument->path);
            CompanyStorage::forget($leadDocument->path);
        }

        $leadDocument->delete();

        return response()->json([
            'message' => 'Documento removido com sucesso'
        ]);
    }
}
