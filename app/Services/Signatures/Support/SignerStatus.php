<?php

namespace App\Services\Signatures\Support;

/**
 * Per-signer progress, in the CRM's vocabulary. See SignatureStatus for why
 * these are ours rather than any vendor's.
 */
final class SignerStatus
{
    /** Registered on the request, not yet asked to sign. */
    public const PENDING = 'pending';

    /** The vendor sent the signature request (email/SMS/WhatsApp). */
    public const NOTIFIED = 'notified';

    /** The signer opened the signing page and began the flow. */
    public const VIEWED = 'viewed';

    public const SIGNED = 'signed';

    public const REFUSED = 'refused';

    /** Identity verification failed enough times that the vendor gave up. */
    public const AUTH_FAILED = 'auth_failed';

    public const ALL = [
        self::PENDING,
        self::NOTIFIED,
        self::VIEWED,
        self::SIGNED,
        self::REFUSED,
        self::AUTH_FAILED,
    ];

    /**
     * How far along each state is. Webhooks can arrive out of order, so
     * signer state only ever moves forward — a late "viewed" must not
     * overwrite "signed".
     */
    private const RANK = [
        self::PENDING     => 0,
        self::NOTIFIED    => 1,
        self::VIEWED      => 2,
        self::AUTH_FAILED => 3,
        self::REFUSED     => 4,
        self::SIGNED      => 5,
    ];

    public static function advances(?string $from, string $to): bool
    {
        return (self::RANK[$to] ?? 0) > (self::RANK[$from] ?? -1);
    }
}
