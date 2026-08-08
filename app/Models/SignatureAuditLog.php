<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of every call we made to a vendor and every delivery a
 * vendor made to us — successes, failures, rejected signatures and duplicates
 * alike.
 *
 * Written through SignatureAuditLogger, which redacts credentials and elides
 * file bodies before anything lands here.
 */
class SignatureAuditLog extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND  = 'inbound';

    protected $fillable = [
        'company_id',
        'signature_provider_id',
        'signature_request_id',
        'provider',
        'direction',
        'action',
        'method',
        'endpoint',
        'status_code',
        'duration_ms',
        'success',
        'request_payload',
        'response_payload',
        'error',
    ];

    protected $casts = [
        'success'          => 'boolean',
        'status_code'      => 'integer',
        'duration_ms'      => 'integer',
        'request_payload'  => 'array',
        'response_payload' => 'array',
    ];

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(SignatureProviderAccount::class, 'signature_provider_id');
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function toPublicArray(): array
    {
        return [
            'id'          => $this->id,
            'provider'    => $this->provider,
            'direction'   => $this->direction,
            'action'      => $this->action,
            'method'      => $this->method,
            'endpoint'    => $this->endpoint,
            'status_code' => $this->status_code,
            'duration_ms' => $this->duration_ms,
            'success'     => $this->success,
            'error'       => $this->error,
            'created_at'  => $this->created_at,
        ];
    }
}
