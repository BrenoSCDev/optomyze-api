<?php

namespace App\Services\Storage;

use App\Models\Company;
use App\Models\CompanyFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * COMPANY STORAGE ACCOUNTING
 *
 * Every file the CRM writes is attributed to a company here, and every file it
 * deletes is forgotten here. Two calls, at each of the write and delete sites
 * found in the audit.
 *
 * Design notes worth keeping:
 *
 *   - **Never throws into the caller.** Accounting is bookkeeping around a
 *     user's actual work; if the ledger write fails, the upload should still
 *     succeed and the discrepancy should be recoverable with `recalculate()`.
 *     A failed `record()` must not turn a working file upload into a 500.
 *
 *   - **Size is read from the disk, not from the request.** `UploadedFile`
 *     sizes are client-adjacent, and several call sites write derived files
 *     (a transcoded m4a, a downloaded avatar) that have no request at all.
 *     Asking the disk is the only measure that matches what is on it.
 *
 *   - **(disk, path) is unique.** Recording the same path twice updates the
 *     existing row instead of double-counting, which makes `record()` safe to
 *     call from a retried webhook.
 */
class CompanyStorage
{
    /**
     * Attribute a stored file to a company.
     *
     * Returns the ledger row, or null when nothing could be recorded — the
     * caller is not expected to check.
     */
    public static function record(
        ?int $companyId,
        string $path,
        string $category,
        string $disk = 'local',
        ?Model $owner = null,
        ?string $mime = null,
        ?int $sizeBytes = null,
    ): ?CompanyFile {
        if (!$companyId || $path === '') {
            return null;
        }

        try {
            $size = $sizeBytes ?? self::sizeOnDisk($disk, $path);

            // The app enforces a morph map, so an owner that is not in it
            // throws. The link is a convenience — losing it must not cost us
            // the accounting row, which is the whole point of the call.
            $ownerType = self::morphAlias($owner);
            $ownerId   = $ownerType ? $owner?->getKey() : null;

            return DB::transaction(function () use ($companyId, $path, $category, $disk, $ownerType, $ownerId, $mime, $size) {
                $existing = CompanyFile::where('disk', $disk)->where('path', $path)->first();

                if ($existing) {
                    // Re-recording the same path: adjust the counter by the
                    // difference so a rewritten file does not double-count.
                    $delta = $size - $existing->size_bytes;

                    $existing->update([
                        'company_id' => $companyId,
                        'size_bytes' => $size,
                        'mime'       => $mime ?? $existing->mime,
                        'category'   => $category,
                        'owner_type' => $ownerType ?? $existing->owner_type,
                        'owner_id'   => $ownerId ?? $existing->owner_id,
                    ]);

                    self::bumpCounter($companyId, $delta);

                    return $existing;
                }

                $file = CompanyFile::create([
                    'company_id' => $companyId,
                    'disk'       => $disk,
                    'path'       => $path,
                    'size_bytes' => $size,
                    'mime'       => $mime,
                    'category'   => $category,
                    'owner_type' => $ownerType,
                    'owner_id'   => $ownerId,
                ]);

                self::bumpCounter($companyId, $size);

                return $file;
            });
        } catch (\Throwable $e) {
            // Bookkeeping must never break the upload it is describing.
            Log::warning('CompanyStorage::record failed', [
                'company_id' => $companyId,
                'path'       => $path,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Forget one or more files, freeing their bytes.
     *
     * Paths that were never recorded are ignored, so this is safe to call
     * defensively next to every `Storage::delete()`.
     */
    public static function forget(string|array $paths, string $disk = 'local'): void
    {
        $paths = array_values(array_filter((array) $paths));

        if (!$paths) {
            return;
        }

        try {
            DB::transaction(function () use ($paths, $disk) {
                $files = CompanyFile::where('disk', $disk)->whereIn('path', $paths)->get();

                foreach ($files->groupBy('company_id') as $companyId => $group) {
                    self::bumpCounter((int) $companyId, -$group->sum('size_bytes'));
                }

                CompanyFile::whereIn('id', $files->pluck('id'))->delete();
            });
        } catch (\Throwable $e) {
            Log::warning('CompanyStorage::forget failed', [
                'paths' => $paths,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Forget every file recorded against a record (a deleted lead, deal, product). */
    public static function forgetOwner(Model $owner): void
    {
        try {
            $files = CompanyFile::where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey())
                ->get();

            if ($files->isEmpty()) {
                return;
            }

            DB::transaction(function () use ($files) {
                foreach ($files->groupBy('company_id') as $companyId => $group) {
                    self::bumpCounter((int) $companyId, -$group->sum('size_bytes'));
                }

                CompanyFile::whereIn('id', $files->pluck('id'))->delete();
            });
        } catch (\Throwable $e) {
            Log::warning('CompanyStorage::forgetOwner failed', [
                'owner' => $owner->getMorphClass() . ':' . $owner->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rebuild a company's counter from the ledger.
     *
     * The counter is denormalised, so it can drift if a write site is added
     * without a `record()` call or a process dies mid-transaction. This is the
     * way back; `storage:recalculate` runs it for every company.
     */
    public static function recalculate(Company $company): int
    {
        $total = (int) CompanyFile::where('company_id', $company->id)->sum('size_bytes');

        $company->forceFill(['storage_used_bytes' => $total])->saveQuietly();

        return $total;
    }

    /** Bytes used, and how they break down by category. */
    public static function usage(Company $company): array
    {
        $byCategory = CompanyFile::where('company_id', $company->id)
            ->selectRaw('category, SUM(size_bytes) AS bytes, COUNT(*) AS files')
            ->groupBy('category')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'bytes'    => (int) $row->bytes,
                'files'    => (int) $row->files,
            ])
            ->sortByDesc('bytes')
            ->values()
            ->all();

        return [
            'bytes'       => (int) ($company->storage_used_bytes ?? 0),
            'files'       => (int) CompanyFile::where('company_id', $company->id)->count(),
            'by_category' => $byCategory,
        ];
    }

    /** Bytes as gigabytes, which is the unit the plan limits are written in. */
    public static function bytesToGb(int $bytes): float
    {
        return round($bytes / (1024 ** 3), 4);
    }

    /**
     * The morph alias for an owner, or null when the model is not in the
     * enforced morph map. Never throws — see record().
     */
    private static function morphAlias(?Model $owner): ?string
    {
        if (!$owner) {
            return null;
        }

        try {
            return $owner->getMorphClass();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function sizeOnDisk(string $disk, string $path): int
    {
        try {
            return Storage::disk($disk)->exists($path)
                ? (int) Storage::disk($disk)->size($path)
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Move the counter by a delta, clamped at zero.
     *
     * An in-database arithmetic update rather than a read-modify-write: two
     * uploads finishing at once would otherwise each read the same total and
     * one would be lost.
     *
     * Subtraction goes through a CASE rather than GREATEST() so it works on
     * both MySQL and the SQLite the test suite runs on, and because the column
     * is unsigned — letting it go negative would be a database error, not just
     * a wrong number.
     */
    private static function bumpCounter(int $companyId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $query = DB::table('companies')->where('id', $companyId);

        if ($delta > 0) {
            $query->increment('storage_used_bytes', $delta);
            return;
        }

        $magnitude = abs($delta);

        $query->update([
            'storage_used_bytes' => DB::raw(
                "CASE WHEN storage_used_bytes < {$magnitude} THEN 0 ELSE storage_used_bytes - {$magnitude} END"
            ),
        ]);
    }
}
