<?php

namespace App\Services\Signatures\Providers\Clicksign;

use App\Services\Signatures\Support\SignatureEventType;
use App\Services\Signatures\Support\SignatureStatus;

/**
 * Clicksign's vocabulary → the CRM's.
 *
 * Every event name below is taken from Clicksign's published event list
 * (https://developers.clicksign.com/docs/eventos). Names not listed here are
 * deliberately mapped to UNKNOWN rather than guessed at: an unrecognised
 * event is still recorded, it just does not move any state.
 */
final class ClicksignEventMap
{
    /** @var array<string, string> */
    private const EVENTS = [
        // Document lifecycle
        'upload'            => SignatureEventType::DOCUMENT_UPLOADED,
        'close'             => SignatureEventType::COMPLETED,
        'auto_close'        => SignatureEventType::COMPLETED,
        'document_closed'   => SignatureEventType::COMPLETED,
        'cancel'            => SignatureEventType::CANCELED,
        'deadline'          => SignatureEventType::EXPIRED,

        // Signers
        'add_signer'        => SignatureEventType::SIGNER_ADDED,
        'remove_signer'     => SignatureEventType::SIGNER_REMOVED,
        'signature_started' => SignatureEventType::SIGNATURE_STARTED,
        'sign'              => SignatureEventType::SIGNED,
        'refusal'           => SignatureEventType::REFUSED,

        // Identity verification gave up on this signer
        'attempts_by_whatsapp_exceeded'              => SignatureEventType::AUTH_FAILED,
        'attempts_by_liveness_or_facematch_exceeded' => SignatureEventType::AUTH_FAILED,
        'liveness_refused'                           => SignatureEventType::AUTH_FAILED,
        'facematch_refused'                          => SignatureEventType::AUTH_FAILED,
        'biometric_refused'                          => SignatureEventType::AUTH_FAILED,
        'identity_biometrics_refused'                => SignatureEventType::AUTH_FAILED,
        'documentscopy_refused'                      => SignatureEventType::AUTH_FAILED,
        'ocr_refused'                                => SignatureEventType::AUTH_FAILED,

        // Configuration changes — recorded, no state change
        'update_deadline'   => SignatureEventType::SETTINGS_UPDATED,
        'update_auto_close' => SignatureEventType::SETTINGS_UPDATED,
        'update_locale'     => SignatureEventType::SETTINGS_UPDATED,
    ];

    /**
     * Events this integration subscribes to when registering a webhook.
     *
     * A subset of the above: the ones that move a request or a signer
     * forward. Everything else would be noise on the timeline.
     */
    public const SUBSCRIBED_EVENTS = [
        'sign',
        'refusal',
        'signature_started',
        'close',
        'auto_close',
        'document_closed',
        'cancel',
        'deadline',
    ];

    /** Clicksign envelope status → CRM request status. */
    private const ENVELOPE_STATUSES = [
        'draft'    => SignatureStatus::QUEUED,
        'running'  => SignatureStatus::SENT,
        'closed'   => SignatureStatus::COMPLETED,
        'canceled' => SignatureStatus::CANCELED,
    ];

    public static function eventType(string $clicksignEvent): string
    {
        return self::EVENTS[$clicksignEvent] ?? SignatureEventType::UNKNOWN;
    }

    public static function envelopeStatus(?string $clicksignStatus): ?string
    {
        return self::ENVELOPE_STATUSES[$clicksignStatus] ?? null;
    }
}
