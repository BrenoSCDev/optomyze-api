<?php

namespace App\Support;

/**
 * Per-request in-memory cache for company timezones.
 *
 * Shared across all model classes that use HasCompanyTimezone, so a
 * company's timezone is resolved from the database at most once per request
 * regardless of how many model instances reference it.
 */
class CompanyTimezoneCache
{
    private static array $cache = [];

    public static function get(int $companyId): ?string
    {
        return self::$cache[$companyId] ?? null;
    }

    public static function set(int $companyId, string $timezone): void
    {
        self::$cache[$companyId] = $timezone;
    }

    /** Reset between requests (useful in tests / long-running processes). */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
