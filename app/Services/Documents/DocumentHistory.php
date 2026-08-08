<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\SignatureAuditLog;
use App\Models\SignatureEvent;
use App\Models\SignatureSigner;
use Illuminate\Support\Collection;

/**
 * The single ordered account of everything that happened to a document.
 *
 * A contract's history is currently spread over four tables that were each
 * written for a different reason:
 *
 *   document_versions      — every render, with its checksum
 *   signature_events       — what the provider told us the outside world did
 *   signature_audit_logs   — every API call we made, with status and duration
 *   the document row       — the milestone timestamps
 *
 * None of them is the history on its own. Somebody asking "what happened to
 * this contract" wants one list, and — because a contract is evidence — wants
 * it to include the machine detail, not a prettified summary. So this merges
 * them rather than picking one.
 *
 * Two audiences, hence `channel`:
 *   'business'  — what a salesperson or a lawyer needs
 *   'technical' — the API traffic, shown behind a toggle
 *
 * Read-only and derived. Nothing here is a source of truth; it is a view over
 * the four that are, which is why it builds no table of its own.
 */
class DocumentHistory
{
    public const CHANNEL_BUSINESS  = 'business';
    public const CHANNEL_TECHNICAL = 'technical';

    /**
     * @return array<int, array<string, mixed>> newest first
     */
    public function for(Document $document, bool $includeTechnical = false): array
    {
        $entries = collect()
            ->merge($this->fromDocument($document))
            ->merge($this->fromVersions($document))
            ->merge($this->fromSignatureEvents($document));

        if ($includeTechnical) {
            $entries = $entries->merge($this->fromAuditLogs($document));
        }

        return $entries
            ->filter(fn ($e) => $e['at'] !== null)
            // Newest first, and for the same instant the higher-sequence entry
            // wins — a completion and its final signature share a timestamp
            // often enough that ordering by time alone reads backwards.
            ->sortByDesc(fn ($e) => [$e['at']->getTimestamp(), $e['sequence']])
            ->values()
            ->map(function ($entry) {
                $entry['at'] = $entry['at']->toIso8601String();

                return $entry;
            })
            ->all();
    }

    /** Milestones held as timestamps on the document row itself. */
    private function fromDocument(Document $document): Collection
    {
        $milestones = [
            ['created',   $document->created_at,   'Document created',        1],
            ['sent',      $document->sent_at,      'Sent for signature',      4],
            ['signed',    $document->signed_at,    'Fully signed',            8],
            ['rejected',  $document->rejected_at,  'Refused by a signer',     8],
            ['expired',   $document->expires_at && $document->status === 'expired'
                            ? $document->expires_at : null, 'Signing deadline passed', 8],
            ['cancelled', $document->cancelled_at, 'Cancelled',               8],
            ['approved',  $document->approved_at,  'Approved',                3],
            ['archived',  $document->archived_at,  'Archived',                9],
        ];

        return collect($milestones)
            ->filter(fn ($m) => $m[1] !== null)
            ->map(fn ($m) => [
                'id'       => "doc-{$document->id}-{$m[0]}",
                'channel'  => self::CHANNEL_BUSINESS,
                'type'     => "document_{$m[0]}",
                'title'    => $m[2],
                'detail'   => null,
                'actor'    => $this->actorFor($document, $m[0]),
                'at'       => $m[1],
                'sequence' => $m[3],
            ]);
    }

    /** Each render, with the checksum that makes a version citable. */
    private function fromVersions(Document $document): Collection
    {
        return $document->versions()
            ->with('generatedBy:id,name')
            ->get()
            ->map(fn ($version) => [
                'id'       => "version-{$version->id}",
                'channel'  => self::CHANNEL_BUSINESS,
                'type'     => 'document_generated',
                'title'    => "Version {$version->version_number} generated",
                'detail'   => $version->checksum
                    ? 'SHA-256 ' . substr($version->checksum, 0, 16) . '…'
                    : null,
                'actor'    => $version->generatedBy?->name,
                'at'       => $version->created_at,
                'sequence' => 2,
            ]);
    }

    /**
     * What the provider reported. This is the part a dispute turns on, so the
     * signer's identity is carried on the entry rather than being looked up
     * from the request — signers can be replaced between sendings.
     */
    private function fromSignatureEvents(Document $document): Collection
    {
        $requestIds = $document->signatureRequests()->pluck('id');

        if ($requestIds->isEmpty()) {
            return collect();
        }

        $signers = SignatureSigner::whereIn('signature_request_id', $requestIds)
            ->get()
            ->keyBy('id');

        return SignatureEvent::whereIn('signature_request_id', $requestIds)
            ->orderBy('id')
            ->get()
            ->map(function (SignatureEvent $event) use ($signers) {
                $signer = $event->signature_signer_id
                    ? $signers->get($event->signature_signer_id)
                    : null;

                return [
                    'id'       => "sigevent-{$event->id}",
                    'channel'  => self::CHANNEL_BUSINESS,
                    'type'     => "signature_{$event->type}",
                    'title'    => $this->signatureEventTitle($event->type, $signer?->name),
                    'detail'   => $signer?->email,
                    // The provider acted, not a CRM user.
                    'actor'    => null,
                    'provider' => $event->provider,
                    'at'       => $event->occurred_at ?? $event->created_at,
                    'sequence' => 6,
                ];
            });
    }

    /**
     * Every call we made to the provider. Off by default: it is the answer to
     * "why did this fail", not to "what happened".
     */
    private function fromAuditLogs(Document $document): Collection
    {
        $requestIds = $document->signatureRequests()->pluck('id');

        if ($requestIds->isEmpty()) {
            return collect();
        }

        return SignatureAuditLog::whereIn('signature_request_id', $requestIds)
            ->orderBy('id')
            ->get()
            ->map(fn (SignatureAuditLog $log) => [
                'id'          => "audit-{$log->id}",
                'channel'     => self::CHANNEL_TECHNICAL,
                'type'        => $log->action,
                'title'       => str_replace('_', ' ', ucfirst($log->action)),
                'detail'      => trim(sprintf(
                    '%s %s → %s',
                    $log->method ?: '',
                    $this->shortEndpoint($log->endpoint),
                    $log->status_code ?: '—'
                )),
                'actor'       => null,
                'provider'    => $log->provider,
                'success'     => (bool) $log->success,
                'duration_ms' => $log->duration_ms,
                'error'       => $log->error,
                'at'          => $log->created_at,
                'sequence'    => 5,
            ]);
    }

    private function signatureEventTitle(?string $type, ?string $signerName): string
    {
        $who = $signerName ? " by {$signerName}" : '';

        return match ($type) {
            'signature_started' => 'Signing started' . $who,
            'signed'            => 'Signed' . $who,
            'refused'           => 'Refused' . $who,
            'completed'         => 'Envelope completed at the provider',
            'canceled'          => 'Cancelled at the provider',
            'expired'           => 'Expired at the provider',
            'auth_failed'       => 'Authentication failed' . $who,
            'document_uploaded' => 'Document uploaded to the provider',
            'signer_added'      => 'Signer added' . $who,
            'signer_removed'    => 'Signer removed' . $who,
            default             => 'Provider event: ' . ($type ?: 'unknown'),
        };
    }

    /** Full URLs make the timeline unreadable; the path is the informative part. */
    private function shortEndpoint(?string $endpoint): string
    {
        if (empty($endpoint)) {
            return '';
        }

        $path = parse_url($endpoint, PHP_URL_PATH) ?: $endpoint;

        return strtok($path, '?') ?: $path;
    }

    private function actorFor(Document $document, string $milestone): ?string
    {
        return match ($milestone) {
            'created'  => $document->creator?->name,
            'approved' => $document->approver?->name,
            // Sent/signed/rejected/expired are driven by the counterparty or a
            // deadline; attributing them to a CRM user would be a lie.
            default    => null,
        };
    }
}
