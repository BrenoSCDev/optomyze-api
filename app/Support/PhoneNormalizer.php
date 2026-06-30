<?php

namespace App\Support;

/**
 * Utility for stripping and canonicalising phone numbers before storage or
 * lookup. All methods are pure (no I/O, no DB) so they can be called from
 * any layer — controllers, jobs, imports, API integrations.
 *
 * ## Brazilian 9-digit transition
 *
 * Several years ago Brazil mandated an extra leading "9" on all mobile
 * numbers, turning 8-digit subscribers into 9-digit ones. Many WhatsApp
 * accounts were registered before the change and still use the old format,
 * while web forms, ERPs, and CRMs capture the new format. Both numbers
 * identify the same person.
 *
 * Old format (12 digits with country code): 55 + DDD(2) + 8-digit subscriber
 * New format (13 digits with country code): 55 + DDD(2) + 9 + 8-digit subscriber
 *
 * variants() returns both so callers can do a whereIn lookup and match
 * either format without knowing which one is stored.
 */
class PhoneNormalizer
{
    /**
     * Strip every character that is not a decimal digit.
     * "+55 (11) 9 8765-4321" → "5511987654321"
     */
    public static function normalize(string $raw): string
    {
        return preg_replace('/\D/', '', $raw);
    }

    /**
     * Return all digit strings that should be treated as equivalent to $digits.
     * Always includes $digits itself as the first element.
     *
     * For Brazilian mobile numbers the list contains both the 12-digit (old)
     * and 13-digit (new) forms. For every other number the list has one entry.
     *
     * @param  string  $digits  Already-normalized (digits only) phone number.
     * @return non-empty-list<string>
     */
    public static function variants(string $digits): array
    {
        if (!str_starts_with($digits, '55')) {
            return [$digits];
        }

        $len = strlen($digits);

        if ($len === 13 && $digits[4] === '9') {
            // New Brazilian mobile format → also try the old 8-digit variant
            // by stripping the extra 9 that sits right after the 2-digit DDD.
            // e.g. 5511 9 87654321 → 5511 87654321
            $old = substr($digits, 0, 4) . substr($digits, 5);
            return [$digits, $old];
        }

        if ($len === 12) {
            // Old Brazilian mobile format → also try the new 9-digit variant
            // by inserting a 9 right after the 2-digit DDD.
            // e.g. 5511 87654321 → 5511 9 87654321
            $new = substr($digits, 0, 4) . '9' . substr($digits, 4);
            return [$digits, $new];
        }

        return [$digits];
    }
}
