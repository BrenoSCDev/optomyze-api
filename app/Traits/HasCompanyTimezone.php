<?php

namespace App\Traits;

use Carbon\Carbon;
use App\Models\Company;
use App\Support\CompanyTimezoneCache;

/**
 * Applies the company's timezone to all datetime attribute reads.
 *
 * - Dates are still stored using the app timezone (UTC or whatever is configured).
 * - When reading, Carbon instances are presented in the company's timezone.
 * - Writes are normalised back to the app timezone before storage.
 *
 * Usage: add `use HasCompanyTimezone;` to any Eloquent model that has a
 * direct `company_id` column or a loaded `company` relation.
 *
 * For correct timezone resolution without extra queries, eager-load the
 * `company` relation on queries that need timezone-aware timestamps:
 *
 *   Lead::with('company')->get();
 *
 * If the company relation is not loaded, the trait falls back to the
 * app-level timezone (config('app.timezone')).
 */
trait HasCompanyTimezone
{
    /**
     * Resolve the company timezone for this model instance.
     *
     * Resolution order:
     *  1. This model IS a Company – use its own timezone attribute.
     *  2. The `company` relation is already loaded – use it.
     *  3. Fall back to the application timezone.
     */
    public function getCompanyTimezone(): string
    {
        // 1. This model IS a Company — use its own attribute.
        if ($this instanceof Company) {
            $tz = $this->getAttribute('timezone') ?: config('app.timezone');
            CompanyTimezoneCache::set($this->getKey(), $tz);
            return $tz;
        }

        // 2. The company relation is already loaded — use it and warm the cache.
        if ($this->relationLoaded('company') && $this->company instanceof Company) {
            $tz = $this->company->getAttribute('timezone') ?: config('app.timezone');
            CompanyTimezoneCache::set($this->company->getKey(), $tz);
            return $tz;
        }

        // 3. Check the per-request cache — avoids any DB query when the same
        //    company_id has already been resolved elsewhere in this request.
        $companyId = $this->getAttribute('company_id');
        if ($companyId) {
            $cached = CompanyTimezoneCache::get((int) $companyId);
            if ($cached !== null) {
                return $cached;
            }

            // 4. Single DB lookup as last resort — result is cached immediately
            //    so subsequent calls for the same company_id are free.
            $tz = Company::where('id', $companyId)->value('timezone')
                ?: config('app.timezone');
            CompanyTimezoneCache::set((int) $companyId, $tz);
            return $tz;
        }

        return config('app.timezone');
    }

    /**
     * Convert a raw database value to a Carbon instance in the company timezone.
     *
     * This affects every datetime-cast attribute (created_at, updated_at,
     * closed_at, lost_at, start_at, last_contact_at, etc.) when they are read.
     */
    protected function asDateTime($value): Carbon
    {
        return parent::asDateTime($value)->setTimezone($this->getCompanyTimezone());
    }

    /**
     * Convert a datetime value to the string that will be persisted.
     *
     * We always normalise to the app timezone before formatting so that
     * storage is consistent regardless of how the caller expressed the date.
     */
    public function fromDateTime($value): string
    {
        return parent::asDateTime($value)
            ->setTimezone(config('app.timezone'))
            ->format($this->getDateFormat());
    }

    /**
     * Serialize a date for JSON output.
     *
     * Overrides the default behaviour (which always outputs UTC with a Z
     * suffix) so that the company timezone offset is preserved in every API
     * response, e.g. "2026-03-09T14:29:13-04:00" instead of
     * "2026-03-09T18:29:13.000000Z".
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return \Carbon\Carbon::instance($date)
            ->setTimezone($this->getCompanyTimezone())
            ->toIso8601String();
    }
}
