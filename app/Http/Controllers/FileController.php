<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    /**
     * Serve a private file with authorization.
     *
     * All private files are stored under paths with company_id as the second segment:
     *   profile_pics/{company_id}/{filename}
     *   bg_pics/{company_id}/{filename}
     *   lead-documents/{company_id}/{filename}
     *   sales/{company_id}/{sale_id}/{filename}
     *   products/{company_id}/{product_id}/{filename}
     */
    public function serve(Request $request, string $path)
    {
        $user = $request->user();

        $segments = explode('/', $path);
        $companyId = (int) ($segments[1] ?? 0);

        if (!$companyId || $user->company_id !== $companyId) {
            abort(403, 'Forbidden');
        }

        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'File not found');
        }

        $absolutePath = Storage::disk('local')->path($path);
        $download = $request->boolean('download', false);
        $filename = basename($path);

        return response()->file($absolutePath, [
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"',
        ]);
    }
}
