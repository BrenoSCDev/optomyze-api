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

    /**
     * Serve a private file authenticated via API token (external v1 API).
     * Company scope comes from the token, not the session user.
     */
    public function serveExternal(Request $request, string $path)
    {
        $company = $request->company;

        if (!$company) {
            abort(403, 'Forbidden');
        }

        $companyId = 0;
        foreach (explode('/', $path) as $segment) {
            $n = (int) $segment;
            if ($n > 0) {
                $companyId = $n;
                break;
            }
        }

        if (!$companyId || $company->id !== $companyId) {
            abort(403, 'Forbidden');
        }

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

    /**
     * Serve a file via a short-lived signed URL, with no Sanctum/company
     * checks. Used so external platforms (e.g. Instagram's Graph API) can
     * fetch outbound media we send them - they can't present our auth
     * header, so the URL's signature (tied to this exact path and an
     * expiry) is the auth instead. The route's `signed` middleware already
     * rejects any tampered or expired URL before this method ever runs.
     */
    public function serveSigned(Request $request, string $path)
    {
        if (Storage::disk('local')->exists($path)) {
            $absolutePath = Storage::disk('local')->path($path);
        } elseif (Storage::disk('public')->exists($path)) {
            $absolutePath = Storage::disk('public')->path($path);
        } else {
            abort(404, 'File not found');
        }

        return response()->file($absolutePath, [
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }
}
