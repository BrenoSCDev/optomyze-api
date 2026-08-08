<?php

namespace App\Models;

use App\Services\Signatures\Support\SignerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person asked to sign, and how far they have got.
 *
 * `lead_id` / `user_id` link back into the CRM when the signer is someone we
 * already know, but neither is required — a contract can name a counterparty
 * who is not a record in the system.
 */
class SignatureSigner extends Model
{
    protected $fillable = [
        'company_id',
        'signature_request_id',
        'lead_id',
        'user_id',
        'provider_signer_id',
        'name',
        'email',
        'phone',
        'document_number',
        'birthdate',
        'signing_group',
        'refusable',
        'auth_methods',
        'communication',
        'status',
        'notified_at',
        'viewed_at',
        'signed_at',
        'refused_at',
        'refusal_reason',
        'sign_url',
        'ip_address',
    ];

    protected $casts = [
        'auth_methods'  => 'array',
        'communication' => 'array',
        'refusable'     => 'boolean',
        'signing_group' => 'integer',
        'birthdate'     => 'date',
        'notified_at'   => 'datetime',
        'viewed_at'     => 'datetime',
        'signed_at'     => 'datetime',
        'refused_at'    => 'datetime',
    ];

    protected $attributes = [
        'status'        => SignerStatus::PENDING,
        'signing_group' => 1,
        'refusable'     => true,
    ];

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Move the signer forward, never backward.
     *
     * Webhook deliveries are not ordered — a "viewed" can land after the
     * "signed" it preceded, and applying it naively would un-sign a signed
     * contract in the UI.
     */
    public function advanceTo(string $status, array $attributes = []): bool
    {
        if (!SignerStatus::advances($this->status, $status)) {
            return false;
        }

        $this->forceFill(array_merge(['status' => $status], $attributes))->save();

        return true;
    }

    public function toPublicArray(): array
    {
        return [
            'id'                 => $this->id,
            'lead_id'            => $this->lead_id,
            'user_id'            => $this->user_id,
            'name'               => $this->name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'document_number'    => $this->document_number,
            'signing_group'      => $this->signing_group,
            'refusable'          => $this->refusable,
            'auth_methods'       => $this->auth_methods ?? [],
            'status'             => $this->status,
            'notified_at'        => $this->notified_at,
            'viewed_at'          => $this->viewed_at,
            'signed_at'          => $this->signed_at,
            'refused_at'         => $this->refused_at,
            'refusal_reason'     => $this->refusal_reason,
            // Only ever what the provider reported; never inferred from our own
            // request, which would describe us rather than the signer.
            'ip_address'         => $this->ip_address,
        ];
    }
}
