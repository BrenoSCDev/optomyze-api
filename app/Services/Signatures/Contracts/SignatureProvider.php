<?php

namespace App\Services\Signatures\Contracts;

use App\Models\SignatureDocument;
use App\Models\SignatureProviderAccount;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Services\Signatures\Data\ConnectionResult;
use App\Services\Signatures\Data\DownloadedFile;
use App\Services\Signatures\Data\NormalizedEvent;
use App\Services\Signatures\Data\ProviderCapabilities;
use App\Services\Signatures\Data\RemoteRequestState;
use App\Services\Signatures\Data\WebhookRegistration;
use Illuminate\Http\Request as HttpRequest;

/**
 * THE SIGNATURE PROVIDER CONTRACT
 *
 * Everything the CRM needs from a digital-signature vendor. Clicksign is the
 * first implementation, not a special case: DocuSign, Adobe Acrobat Sign,
 * Dropbox Sign and SignNow all fit the same shape.
 *
 * Rules for implementers:
 *
 *   - **Speak the CRM's vocabulary outward.** Take domain models in, return
 *     the DTOs in Services\Signatures\Data out, using SignatureStatus /
 *     SignerStatus / SignatureEventType constants. Vendor words stop here.
 *
 *   - **Persist the vendor ids you learn.** createRequest() is resumable: it
 *     is called from a retrying job, so save each provider id as soon as the
 *     vendor issues it. A retry that re-creates an already-created request
 *     bills the customer twice.
 *
 *   - **Classify your failures.** Throw SignatureProviderException with
 *     `retryable: true` only when trying again could plausibly work (429,
 *     5xx, connection resets). A rejected payload must be permanent, or the
 *     queue will grind on it until it gives up.
 *
 *   - **Never emulate what the vendor does not document.** If an operation
 *     has no documented endpoint, throw UnsupportedCapabilityException and
 *     declare it false in capabilities(). A visible gap the user can plan
 *     around beats an invented one that breaks silently.
 */
interface SignatureProvider
{
    /** Stable key stored in signature_providers.provider and config. */
    public function key(): string;

    /** Human label for the integrations UI. */
    public function label(): string;

    /** What this vendor supports; the UI renders its options from this. */
    public function capabilities(): ProviderCapabilities;

    /**
     * Check the company's stored credentials against the vendor.
     *
     * Must not mutate anything; the caller records the result.
     */
    public function verifyCredentials(SignatureProviderAccount $account): ConnectionResult;

    /**
     * Register `$callbackUrl` with the vendor so it delivers events to us.
     *
     * @throws \App\Services\Signatures\Exceptions\UnsupportedCapabilityException
     *         when the vendor has no API for this and it must be done by hand
     *         in their dashboard.
     */
    public function registerWebhook(SignatureProviderAccount $account, string $callbackUrl): WebhookRegistration;

    /** Remove a previously registered callback. Tolerates one already gone. */
    public function removeWebhook(SignatureProviderAccount $account): void;

    /**
     * Check a draft against this vendor's own rules before anything is sent.
     *
     * Vendors reject payloads for reasons the CRM cannot guess — a signer
     * name that is one word, a phone number of the wrong length, a CPF
     * required by the chosen identity check. Catching those here turns a
     * confusing vendor 422 into a message the user can act on, and keeps
     * vendor-specific rules inside the driver where they belong.
     *
     * @return array<int, string> Human-readable problems; empty means valid.
     */
    public function validateDraft(SignatureRequest $request): array;

    /**
     * Create the request at the vendor and start collecting signatures.
     *
     * Receives a request that already has its documents and signers persisted
     * locally, and fills in the provider ids as it goes.
     */
    public function createRequest(SignatureRequest $request): void;

    /** Withdraw a live request at the vendor. */
    public function cancelRequest(SignatureRequest $request): void;

    /**
     * (Re)send the signature invitation. A null signer means everyone still
     * outstanding.
     */
    public function notify(SignatureRequest $request, ?SignatureSigner $signer = null): void;

    /** Read the request's current state at the vendor, for reconciliation. */
    public function fetchStatus(SignatureRequest $request): RemoteRequestState;

    /**
     * Fetch the finished file.
     *
     * `$url` is whatever the vendor gave us — from a webhook, or a status
     * fetch. Implementations resolve it against their own documented rules.
     */
    public function downloadSignedDocument(SignatureDocument $document, string $url): DownloadedFile;

    /**
     * Is this delivery genuinely from the vendor?
     *
     * Implementations must verify against the RAW request body: re-encoding a
     * parsed payload will not reproduce the bytes the signature was computed
     * over.
     */
    public function verifyWebhook(SignatureProviderAccount $account, HttpRequest $httpRequest): bool;

    /**
     * Translate a delivery into zero or more normalized events.
     *
     * Returning an empty array is correct for deliveries that carry nothing
     * the CRM models — the caller still records the raw delivery.
     *
     * @return array<int, NormalizedEvent>
     */
    public function parseWebhook(SignatureProviderAccount $account, HttpRequest $httpRequest): array;
}
