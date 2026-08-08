<?php

namespace App\Services\Documents;

use App\Models\CompanyFile;
use App\Models\DocumentTemplate;
use App\Services\Storage\CompanyStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Images in documents: stored once, inlined at generation.
 *
 * The naive approach is to let the editor base64 the file straight into the
 * document body. It is also wrong three times over:
 *
 *   - A 2 MB logo becomes ~2.7 MB of base64 in `body_doc`, in every autosave
 *     revision, and in every `document_versions.source_html` of every contract
 *     ever generated from that template. The same bytes, dozens of times.
 *   - It never appears in the storage ledger, so plan usage under-reports.
 *   - Editing the template means shipping the whole payload back and forth.
 *
 * So the editor uploads once and references the asset by URL. The document
 * model holds a reference, not bytes.
 *
 * At *generation* time the reference is resolved back to a data URI and
 * embedded in the HTML the renderer sees. That is not an optimisation, it is
 * required twice over: dompdf has remote asset fetching disabled (a renderer
 * that fetches arbitrary URLs turns a user-authored template into an SSRF
 * primitive), and a contract must still render years later even if the asset
 * has been moved, re-bucketed or deleted — by which time the PDF is already
 * flat bytes in `document_versions`, and the signed copy is at the provider.
 */
class DocumentAssetService
{
    /** Formats a document can actually carry. SVG is excluded deliberately. */
    public const ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    public const MAX_BYTES = 5 * 1024 * 1024;

    public function store(UploadedFile $file, int $companyId, ?DocumentTemplate $template = null): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path      = "documents/{$companyId}/assets/" . Str::uuid() . '.' . $extension;

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        CompanyStorage::record(
            $companyId,
            $path,
            CompanyFile::CATEGORY_DOCUMENT_ASSET,
            'local',
            owner: $template,
            mime: $file->getMimeType(),
        );

        return [
            'path' => $path,
            'url'  => $this->publicUrl($path),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    /**
     * The URL the editor puts in an <img src>.
     *
     * Signed rather than public: document assets are a company's letterhead
     * and signature images, and the storage disk is private for the same
     * reason lead documents are.
     *
     * Signed *relatively* (the `false`) and never expiring. Relative because
     * prod terminates TLS at a proxy and forwards plain HTTP, so the scheme
     * Laravel sees would not match what was signed. Non-expiring because this
     * URL is written into a stored template body — a URL that dies in an hour
     * would leave every template with broken images tomorrow. Confinement to
     * the company's own asset prefix, enforced on read, is what makes that
     * safe.
     */
    public function publicUrl(string $path): string
    {
        return URL::to(URL::signedRoute(
            'documents.asset',
            ['path' => base64_encode($path)],
            null,
            false
        ));
    }

    /**
     * Replace every asset reference in the HTML with an embedded data URI.
     *
     * Runs on the rendered HTML immediately before the PDF renderer sees it.
     * Anything that cannot be resolved is left exactly as it was: a missing
     * logo is a blemish, while aborting the render would cost the contract.
     */
    public function inlineAssets(string $html): string
    {
        return preg_replace_callback(
            '#<img\b[^>]*\bsrc=(["\'])(.*?)\1[^>]*>#i',
            function (array $match) {
                $tag = $match[0];
                $src = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);

                // Already embedded — the editor may legitimately produce these
                // for a pasted image that was never uploaded.
                if (str_starts_with($src, 'data:')) {
                    return $tag;
                }

                $dataUri = $this->toDataUri($src);

                return $dataUri === null
                    ? $tag
                    : str_replace($match[2], $dataUri, $tag);
            },
            $html
        ) ?? $html;
    }

    /**
     * Resolve a src to a data URI, or null if it is not ours to resolve.
     *
     * Only files on our own disk are read. A src pointing anywhere else is
     * left alone rather than fetched — fetching it is precisely the
     * server-side request forgery that keeping `isRemoteEnabled` off avoids,
     * and doing it here would reintroduce the hole one layer up.
     */
    private function toDataUri(string $src): ?string
    {
        $path = $this->resolveStoragePath($src);

        if ($path === null) {
            return null;
        }

        try {
            $disk = Storage::disk('local');

            if (!$disk->exists($path)) {
                Log::warning('Document asset missing at render time', ['path' => $path]);

                return null;
            }

            $bytes = $disk->get($path);
            $mime  = $disk->mimeType($path) ?: 'image/png';

            if (!in_array($mime, self::ALLOWED_MIMES, true)) {
                return null;
            }

            return "data:{$mime};base64," . base64_encode($bytes);
        } catch (\Throwable $e) {
            Log::warning('Could not inline a document asset', [
                'src'   => $src,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract our storage path from an asset URL.
     *
     * The path is confined to the company assets prefix after decoding. Taking
     * a path out of a URL and handing it to the filesystem is a directory
     * traversal waiting to happen; `..` and absolute paths are refused rather
     * than normalised, because a template body is user-authored input.
     */
    private function resolveStoragePath(string $src): ?string
    {
        $query = parse_url($src, PHP_URL_QUERY);
        $path  = parse_url($src, PHP_URL_PATH) ?: '';

        $encoded = null;

        // `~` delimiter, not `#` — the character class needs a literal `#`.
        if (preg_match('~/documents/assets/([^/?\#]+)~', $path, $m)) {
            $encoded = $m[1];
        } elseif ($query) {
            parse_str($query, $params);
            $encoded = $params['path'] ?? null;
        }

        if (!$encoded) {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        if ($decoded === false || $decoded === '') {
            return null;
        }

        if (str_contains($decoded, '..') || str_starts_with($decoded, '/')) {
            return null;
        }

        if (!preg_match('#^documents/\d+/assets/#', $decoded)) {
            return null;
        }

        return $decoded;
    }
}
