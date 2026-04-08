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

        // Company ID is the first numeric segment in the path.
        // Handles both shallow (profile_pics/{company_id}/file) and
        // nested paths (chat/images/{company_id}/file, whatsapp/audio/{company_id}/file).
        $companyId = 0;
        foreach (explode('/', $path) as $segment) {
            $n = (int) $segment;
            if ($n > 0) {
                $companyId = $n;
                break;
            }
        }

        if (!$companyId || $user->company_id !== $companyId) {
            abort(403, 'Forbidden');
        }

        // Try local disk first (preferred), then public disk (legacy files).
        if (Storage::disk('local')->exists($path)) {
            $absolutePath = Storage::disk('local')->path($path);
        } elseif (Storage::disk('public')->exists($path)) {
            $absolutePath = Storage::disk('public')->path($path);
        } else {
            abort(404, 'File not found');
        }

        $download = $request->boolean('download', false);
        $filename = basename($path);

        return response()->file($absolutePath, [
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"',
        ]);
    }
}
