<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeadDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $path = $file->store('lead-documents', 'public');

        $document = LeadDocument::create([
            'lead_id' => $validated['lead_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'path' => $path,
        ]);

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
            Storage::disk('public')->delete($leadDocument->path);
        }

        $leadDocument->delete();

        return response()->json([
            'message' => 'Documento removido com sucesso'
        ]);
    }
}
