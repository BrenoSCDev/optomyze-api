<?php

namespace App\Support;

use App\Models\Company;

/**
 * Per-request in-memory cache for company languages, mirroring
 * CompanyCurrencyCache — avoids repeated company lookups when rendering
 * many Records for the same company within a single request.
 */
class CompanyLanguageCache
{
    private static array $cache = [];

    public static function get(int $companyId): string
    {
        if (!array_key_exists($companyId, self::$cache)) {
            self::$cache[$companyId] = Company::find($companyId)?->language ?? 'EN';
        }

        return self::$cache[$companyId];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
