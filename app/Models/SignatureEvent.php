<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a signature request, in the CRM's vocabulary.
 *
 * `dedupe_key` carries the idempotency guarantee: it is unique per provider,
 * so a redelivered webhook cannot produce a second row, and the applier reads
 * that collision as "already handled".
 */
class SignatureEvent extends Model
{
    protected $fillable = [
        'company_id',
        'signature_request_id',
        'signature_signer_id',
        'provider',
        'provider_event_name',
        'type',
        'external_id',
        'dedupe_key',
        'occurred_at',
        'payload',
    ];

    protected $casts = [
        'payload'     => 'array',
        'occurred_at' => 'datetime',
    ];

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(SignatureSigner::class, 'signature_signer_id');
    }

    /**
     * The timeline projection. The raw payload is deliberately not exposed —
     * it can contain signer documents and vendor internals, and the audit log
     * is the place to go for it.
     */
    public function toPublicArray(): array
    {
        return [
            'id'                  => $this->id,
            'type'                => $this->type,
            'provider'            => $this->provider,
            'provider_event_name' => $this->provider_event_name,
            'signature_signer_id' => $this->signature_signer_id,
            'occurred_at'         => $this->occurred_at,
            'created_at'          => $this->created_at,
        ];
    }
}
