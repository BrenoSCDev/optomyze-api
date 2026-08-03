<?php

namespace App\Support;

use App\Models\Identity;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The short-lived credential handed out between "password accepted" and
 * "company chosen".
 *
 * Deliberately NOT a Sanctum token. Sanctum tokens in this application belong
 * to a `User`, and a user row is one company's account — that is the property
 * that makes a session incapable of seeing two tenants. Minting an
 * identity-scoped Sanctum token to cover the gap would create exactly the
 * cross-company credential the design exists to prevent.
 *
 * Instead this is a stateless encrypted payload. Laravel's encrypter is
 * authenticated (AES-256-CBC with an HMAC), so it cannot be forged or
 * tampered with, and it needs no table or cache entry of its own. It carries
 * no abilities: the only thing it can be exchanged for is a session on an
 * account that the identity inside it actually owns, re-checked at redemption.
 */
class CompanySelectionToken
{
    /** Long enough to pick from a list, short enough to be useless if leaked. */
    private const TTL_SECONDS = 300;

    public static function issue(Identity $identity): string
    {
        return Crypt::encryptString(json_encode([
            'identity_id' => $identity->id,
            'expires_at'  => now()->addSeconds(self::TTL_SECONDS)->timestamp,
            'nonce'       => Str::random(16),
        ]));
    }

    /**
     * The identity a token refers to, or null if it is forged, malformed,
     * expired, or points at an identity that has since been disabled.
     */
    public static function resolve(?string $token): ?Identity
    {
        if (!$token) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (!is_array($payload) || !isset($payload['identity_id'], $payload['expires_at'])) {
            return null;
        }

        if (now()->timestamp > (int) $payload['expires_at']) {
            return null;
        }

        return Identity::where('id', $payload['identity_id'])
            ->where('is_active', true)
            ->first();
    }

    public static function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }
}
