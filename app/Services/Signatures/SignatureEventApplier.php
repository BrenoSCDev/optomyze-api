<?php

namespace App\Services\Signatures;

use App\Events\DocumentExpired;
use App\Events\DocumentRejected;
use App\Events\DocumentSigned;
use App\Jobs\DownloadSignedSignatureDocument;
use App\Models\Document;
use App\Models\SignatureEvent;
use App\Models\SignatureProviderAccount;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Services\Signatures\Data\NormalizedEvent;
use App\Services\Signatures\Support\SignatureEventType;
use App\Services\Documents\Support\DocumentStatus;
use App\Services\Signatures\Support\SignatureStatus;
use App\Services\Signatures\Support\SignerStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies normalized vendor events to CRM state.
 *
 * Vendor-agnostic by construction: it takes NormalizedEvent and switches on
 * SignatureEventType, so it behaves identically whichever driver produced the
 * event.
 *
 * IDEMPOTENCY. Vendors redeliver — on our 5xx, on their own retry policy, and
 * sometimes for no reason at all. Applying "signed" twice would double a
 * timeline and re-trigger a file download, so the guard is a unique index on
 * (provider, dedupe_key) and the insert happens *first*:
 *
 *     insert the event row  →  if it collided, stop, we have seen this
 *                           →  if it inserted, apply the state change
 *
 * The key is derived from the delivery's content only, never from receipt
 * time — a time-based key would differ on every redelivery and guard nothing.
 * Doing the insert before the state change also makes two simultaneous
 * deliveries safe: one wins the index, the other returns early.
 */
class SignatureEventApplier
{
    /**
     * @return SignatureEvent|null  The stored event, or null when this
     *                              delivery was a duplicate or unmatchable.
     */
    public function apply(SignatureProviderAccount $account, NormalizedEvent $event): ?SignatureEvent
    {
        $request = $this->resolveRequest($account, $event);

        if (!$request) {
            Log::warning('Signature webhook could not be matched to a request', [
                'provider'            => $account->provider,
                'company_id'          => $account->company_id,
                'provider_event'      => $event->providerEventName,
                'provider_request_id' => $event->providerRequestId,
            ]);

            return null;
        }

        $signer = $this->resolveSigner($request, $event);

        $stored = $this->storeEvent($account, $request, $signer, $event);

        if (!$stored) {
            // Already handled. Not an error — this is the guard doing its job.
            Log::info('Signature webhook ignored as duplicate', [
                'provider'             => $account->provider,
                'signature_request_id' => $request->id,
                'provider_event'       => $event->providerEventName,
            ]);

            return null;
        }

        $this->applyStateChange($request, $signer, $event);

        return $stored;
    }

    /**
     * Find the request a delivery belongs to.
     *
     * The identifier a vendor puts in its payload is not always the one it
     * gave us for the request. Clicksign's webhook body carries
     * `document.key`, and its documentation does not state whether that is the
     * envelope id or the document id under API 3.0 — so both are tried, in the
     * order that is cheapest to confirm, always scoped to the company that
     * owns the callback. An unmatched delivery is reported rather than
     * guessed at.
     */
    private function resolveRequest(SignatureProviderAccount $account, NormalizedEvent $event): ?SignatureRequest
    {
        if (empty($event->providerRequestId)) {
            return null;
        }

        $byRequestId = SignatureRequest::with(['signers', 'documents', 'providerAccount', 'document.sale', 'document.lead'])
            ->fromCompany($account->company_id)
            ->where('provider', $account->provider)
            ->where('provider_request_id', $event->providerRequestId)
            ->first();

        if ($byRequestId) {
            return $byRequestId;
        }

        return SignatureRequest::with(['signers', 'documents', 'providerAccount', 'document.sale', 'document.lead'])
            ->fromCompany($account->company_id)
            ->where('provider', $account->provider)
            ->whereHas(
                'documents',
                fn ($query) => $query->where('provider_document_id', $event->providerRequestId)
            )
            ->first();
    }

    /**
     * Which signer the event is about — by the vendor's signer id when it
     * sends one, otherwise by email. Null is a valid answer for events that
     * concern the whole request.
     */
    private function resolveSigner(SignatureRequest $request, NormalizedEvent $event): ?SignatureSigner
    {
        $signers = $request->relationLoaded('signers') ? $request->signers : $request->signers()->get();

        if (!empty($event->providerSignerId)) {
            $match = $signers->firstWhere('provider_signer_id', $event->providerSignerId);

            if ($match) {
                return $match;
            }
        }

        if (!empty($event->signerEmail)) {
            $match = $signers->first(
                fn (SignatureSigner $signer) => $signer->email
                    && strcasecmp($signer->email, $event->signerEmail) === 0
            );

            if ($match) {
                return $match;
            }
        }

        // A per-signer event we cannot attribute is worth surfacing: the
        // request still progresses, but the signer's row will not.
        if (in_array($event->type, [
            SignatureEventType::SIGNED,
            SignatureEventType::REFUSED,
            SignatureEventType::SIGNATURE_STARTED,
            SignatureEventType::AUTH_FAILED,
        ], true)) {
            Log::info('Signature event could not be attributed to a signer', [
                'signature_request_id' => $request->id,
                'provider_event'       => $event->providerEventName,
                'provider_signer_id'   => $event->providerSignerId,
            ]);
        }

        return null;
    }

    /**
     * Persist the event, or report that we already had it.
     *
     * The unique index is the arbiter rather than a preceding SELECT: two
     * concurrent deliveries would both pass a check-then-insert.
     */
    private function storeEvent(
        SignatureProviderAccount $account,
        SignatureRequest $request,
        ?SignatureSigner $signer,
        NormalizedEvent $event
    ): ?SignatureEvent {
        try {
            return SignatureEvent::create([
                'company_id'           => $account->company_id,
                'signature_request_id' => $request->id,
                'signature_signer_id'  => $signer?->id,
                'provider'             => $account->provider,
                'provider_event_name'  => Str::limit($event->providerEventName, 80, ''),
                'type'                 => $event->type,
                'external_id'          => $event->externalId,
                'dedupe_key'           => $this->dedupeKey($event),
                'occurred_at'          => $event->occurredAt ?? now(),
                'payload'              => $event->payload,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Content-derived identity for a delivery.
     *
     * A vendor event id is used when there is one. Otherwise the hash covers
     * the event name plus the payload, which is stable across redeliveries of
     * the same event and different between genuinely different ones.
     */
    private function dedupeKey(NormalizedEvent $event): string
    {
        if (!empty($event->externalId)) {
            return hash('sha256', $event->providerEventName . '|' . $event->externalId);
        }

        return hash('sha256', $event->providerEventName . '|' . json_encode($event->payload));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000/23505 across MySQL, MariaDB, Postgres and SQLite.
        return in_array($e->getCode(), ['23000', '23505'], true);
    }

    private function applyStateChange(
        SignatureRequest $request,
        ?SignatureSigner $signer,
        NormalizedEvent $event
    ): void {
        DB::transaction(function () use ($request, $signer, $event) {
            match ($event->type) {
                SignatureEventType::SIGNATURE_STARTED => $this->onSignatureStarted($signer, $event),
                SignatureEventType::SIGNED            => $this->onSigned($request, $signer, $event),
                SignatureEventType::REFUSED           => $this->onRefused($request, $signer, $event),
                SignatureEventType::AUTH_FAILED       => $this->onAuthFailed($signer),
                SignatureEventType::COMPLETED         => $this->onCompleted($request, $event),
                SignatureEventType::CANCELED          => $this->onCanceled($request, $event),
                SignatureEventType::EXPIRED           => $this->onExpired($request, $event),
                default                               => null,
            };
        });
    }

    private function onSignatureStarted(?SignatureSigner $signer, NormalizedEvent $event): void
    {
        $signer?->advanceTo(SignerStatus::VIEWED, ['viewed_at' => $event->occurredAt ?? now()]);
    }

    private function onSigned(SignatureRequest $request, ?SignatureSigner $signer, NormalizedEvent $event): void
    {
        $signer?->advanceTo(SignerStatus::SIGNED, ['signed_at' => $event->occurredAt ?? now()]);

        // A refusal that a later signature supersedes should not leave the
        // request looking refused.
        if ($request->status === SignatureStatus::REFUSED) {
            $request->forceFill(['status' => SignatureStatus::SENT, 'refused_at' => null])->save();
        }

        // Someone has signed but the provider has not closed the document, so
        // others are still outstanding. Only advance — a completion that
        // arrived first must not be walked back to partially signed.
        $document = $request->document;

        if ($document && $document->status === DocumentStatus::WAITING_SIGNATURE) {
            $document->forceFill(['status' => DocumentStatus::PARTIALLY_SIGNED])->save();
        }
    }

    private function onRefused(SignatureRequest $request, ?SignatureSigner $signer, NormalizedEvent $event): void
    {
        $signer?->advanceTo(SignerStatus::REFUSED, ['refused_at' => $event->occurredAt ?? now()]);

        if (!$request->isTerminal()) {
            $request->forceFill([
                'status'     => SignatureStatus::REFUSED,
                'refused_at' => $event->occurredAt ?? now(),
            ])->save();

            $document = $request->document;

            if ($document && !$document->isTerminal()) {
                $document->forceFill([
                    'status'      => DocumentStatus::REJECTED,
                    'rejected_at' => $event->occurredAt ?? now(),
                ])->save();

                // Listeners write the timeline and notify the owner; this
                // class only decides what happened, never what follows.
                event(new DocumentRejected($document, $signer));
            }
        }
    }

    private function onAuthFailed(?SignatureSigner $signer): void
    {
        $signer?->advanceTo(SignerStatus::AUTH_FAILED);
    }

    /**
     * The vendor finalised the document.
     *
     * The signed file is fetched in a separate job: the webhook has to answer
     * fast, and a download failure must not cost us the completion itself.
     */
    private function onCompleted(SignatureRequest $request, NormalizedEvent $event): void
    {
        $alreadyCompleted = $request->status === SignatureStatus::COMPLETED;

        if (!$alreadyCompleted) {
            $request->forceFill([
                'status'       => SignatureStatus::COMPLETED,
                'completed_at' => $event->occurredAt ?? now(),
                'last_error'   => null,
            ])->save();

            // Every signer is done by definition once the vendor closes it.
            foreach ($request->signers as $signer) {
                $signer->advanceTo(SignerStatus::SIGNED, ['signed_at' => $event->occurredAt ?? now()]);
            }

            $document = $request->document;

            if ($document && $document->status !== DocumentStatus::SIGNED) {
                $document->forceFill([
                    'status'    => DocumentStatus::SIGNED,
                    'signed_at' => $event->occurredAt ?? now(),
                ])->save();

                // The seam everything downstream hangs off — the deal moving
                // to won today, invoices and projects whenever they exist.
                event(new DocumentSigned($document));
            }
        }

        if (empty($event->signedFileUrl)) {
            return;
        }

        foreach ($request->documents as $document) {
            if ($document->hasSignedCopy()) {
                continue;
            }

            $document->forceFill(['signed_file_url' => $event->signedFileUrl])->save();

            DownloadSignedSignatureDocument::dispatch($document->id);
        }
    }

    private function onCanceled(SignatureRequest $request, NormalizedEvent $event): void
    {
        if ($request->status === SignatureStatus::CANCELED) {
            return;
        }

        $request->forceFill([
            'status'      => SignatureStatus::CANCELED,
            'canceled_at' => $event->occurredAt ?? now(),
        ])->save();

        $document = $request->document;

        if ($document && !$document->isTerminal()) {
            $document->forceFill([
                'status'       => DocumentStatus::CANCELLED,
                'cancelled_at' => $event->occurredAt ?? now(),
            ])->save();
        }
    }

    private function onExpired(SignatureRequest $request, NormalizedEvent $event): void
    {
        if ($request->isTerminal()) {
            return;
        }

        $request->forceFill(['status' => SignatureStatus::EXPIRED])->save();

        $document = $request->document;

        if ($document && !$document->isTerminal()) {
            $document->forceFill(['status' => DocumentStatus::EXPIRED])->save();

            event(new DocumentExpired($document));
        }
    }
}
