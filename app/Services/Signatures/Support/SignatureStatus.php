<?php

namespace App\Services\Signatures\Support;

/**
 * The CRM's own vocabulary for where a signature request stands.
 *
 * Vendors each have their own words for this — Clicksign says
 * draft/running/closed/canceled, DocuSign says created/sent/completed/voided.
 * Drivers translate into these constants so nothing above the provider layer
 * has to know which vendor produced a row.
 */
final class SignatureStatus
{
    /** Being assembled in the CRM; nothing has been sent to the vendor yet. */
    public const DRAFT = 'draft';

    /** Handed to the queue; the vendor call is in flight. */
    public const QUEUED = 'queued';

    /** Live at the vendor, waiting on signers. */
    public const SENT = 'sent';

    /** Every signer signed and the vendor finalised the document. */
    public const COMPLETED = 'completed';

    /** Withdrawn, by us or at the vendor. */
    public const CANCELED = 'canceled';

    /** A signer declined. Terminal only when the vendor stops the process. */
    public const REFUSED = 'refused';

    /** The signing deadline passed without completion. */
    public const EXPIRED = 'expired';

    /** The vendor call failed and retries are exhausted; see `last_error`. */
    public const FAILED = 'failed';

    public const ALL = [
        self::DRAFT,
        self::QUEUED,
        self::SENT,
        self::COMPLETED,
        self::CANCELED,
        self::REFUSED,
        self::EXPIRED,
        self::FAILED,
    ];

    /** States that will never change again on their own. */
    public const TERMINAL = [
        self::COMPLETED,
        self::CANCELED,
        self::EXPIRED,
    ];

    /** States where the vendor still owns the request and can emit events. */
    public const OPEN = [
        self::QUEUED,
        self::SENT,
        self::REFUSED,
    ];

    public static function isTerminal(?string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
