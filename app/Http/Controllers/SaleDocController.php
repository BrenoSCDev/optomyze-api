<?php

namespace App\Http\Controllers;

use App\Models\Record;
use App\Models\Sale;
use App\Models\SaleDoc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\CompanyFile;
use App\Services\Storage\CompanyStorage;

class SaleDocController extends Controller
{
    public function store(Request $request, Sale $sale)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $file = $request->file('file');
        $path = $file->store('sales/' . $request->user()->company_id . '/' . $sale->id, 'local');

        $doc = $sale->docs()->create([
            'filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        CompanyStorage::record(
            $request->user()->company_id,
            $path,
            CompanyFile::CATEGORY_DEAL_DOCUMENT,
            owner: $doc,
            mime: $file->getClientMimeType(),
        );

        Record::createDealDocumentAdded($doc, $sale, $request->user());

        return response()->json($doc, 201);
    }

    public function destroy(SaleDoc $doc)
    {
        Storage::disk('local')->delete($doc->storage_path);
        CompanyStorage::forget($doc->storage_path);
        $doc->delete();

        return response()->json(['message' => 'Document deleted']);
    }
}