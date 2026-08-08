<?php

namespace App\Services\Documents;

use App\Models\DocumentNumberSequence;
use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;

/**
 * Allocates human-facing document numbers — CTR-2026-0001.
 *
 * Under a row lock, not `MAX(number) + 1`. Two salespeople creating a contract
 * in the same second must not be handed the same number: a contract number is
 * quoted in correspondence, referenced by amendments and cited in court, so a
 * collision is not a cosmetic bug.
 *
 * Numbers are allocated per company, per prefix, per year, and never reused —
 * a cancelled contract keeps its number rather than freeing it, because the
 * number appears in whatever was already sent out.
 */
class DocumentNumberGenerator
{
    public function generate(int $companyId, ?DocumentType $type = null): string
    {
        $prefix = $this->prefixFor($type);
        $year   = (int) now()->format('Y');
        $pad    = (int) config('documents.numbering.pad', 4);

        $next = DB::transaction(function () use ($companyId, $prefix, $year) {
            $sequence = DocumentNumberSequence::query()
                ->where('company_id', $companyId)
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                // A concurrent creator may win the insert; treat the unique
                // violation as "someone else made it" and re-read under lock.
                try {
                    $sequence = DocumentNumberSequence::create([
                        'company_id'  => $companyId,
                        'prefix'      => $prefix,
                        'year'        => $year,
                        'last_number' => 0,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    $sequence = DocumentNumberSequence::query()
                        ->where('company_id', $companyId)
                        ->where('prefix', $prefix)
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            $sequence->increment('last_number');

            return $sequence->fresh()->last_number;
        });

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $next, $pad, '0', STR_PAD_LEFT));
    }

    private function prefixFor(?DocumentType $type): string
    {
        $prefixes = (array) config('documents.numbering.prefixes', []);
        $default  = (string) config('documents.numbering.default_prefix', 'DOC');

        return $prefixes[$type?->category ?? ''] ?? $default;
    }
}
