<?php

namespace App\Services\Signatures\Support;

/**
 * Vendor events, normalized.
 *
 * A driver maps its vendor's event names onto these; the applier switches on
 * these and nothing else. The raw vendor name is still stored alongside on
 * `signature_events.provider_event_name` so the audit trail stays faithful.
 */
final class SignatureEventType
{
    public const DOCUMENT_UPLOADED = 'document_uploaded';
    public const SIGNER_ADDED      = 'signer_added';
    public const SIGNER_REMOVED    = 'signer_removed';
    public const SIGNATURE_STARTED = 'signature_started';
    public const SIGNED            = 'signed';
    public const REFUSED           = 'refused';
    public const COMPLETED         = 'completed';
    public const CANCELED          = 'canceled';
    public const EXPIRED           = 'expired';
    public const AUTH_FAILED       = 'auth_failed';
    public const SETTINGS_UPDATED  = 'settings_updated';

    /** Recognised delivery, no state change — kept for the audit trail. */
    public const UNKNOWN = 'unknown';

    public const ALL = [
        self::DOCUMENT_UPLOADED,
        self::SIGNER_ADDED,
        self::SIGNER_REMOVED,
        self::SIGNATURE_STARTED,
        self::SIGNED,
        self::REFUSED,
        self::COMPLETED,
        self::CANCELED,
        self::EXPIRED,
        self::AUTH_FAILED,
        self::SETTINGS_UPDATED,
        self::UNKNOWN,
    ];
}
