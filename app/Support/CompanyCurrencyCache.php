<?php

namespace App\Support;

use App\Models\Company;

/**
 * Per-request in-memory cache for company default currencies.
 *
 * Shared across all model classes that need a company's default currency,
 * so it's resolved from the database at most once per request regardless
 * of how many model instances reference it.
 */
class CompanyCurrencyCache
{
    private static array $cache = [];

    public static function get(int $companyId): string
    {
        if (!array_key_exists($companyId, self::$cache)) {
            self::$cache[$companyId] = Company::find($companyId)?->currency ?? 'USD';
        }

        return self::$cache[$companyId];
    }

    /** Reset between requests (useful in tests / long-running processes). */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
