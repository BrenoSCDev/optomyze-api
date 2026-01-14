<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleDoc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SaleDocController extends Controller
{
    public function store(Request $request, Sale $sale)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $file = $request->file('file');
        $path = $file->store("sales/{$sale->id}", 'public');

        $doc = $sale->docs()->create([
            'filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        return response()->json($doc, 201);
    }

    public function destroy(SaleDoc $doc)
    {
        Storage::disk('public')->delete($doc->storage_path);
        $doc->delete();

        return response()->json(['message' => 'Document deleted']);
    }
}