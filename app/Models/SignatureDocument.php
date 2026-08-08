<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file inside a signature request, in both its states: the source we
 * uploaded, and the finished copy we pulled back.
 *
 * We store our own copy of the signed file rather than keeping the vendor's
 * link, because vendor download URLs are generally undocumented as to expiry
 * and a signed contract has to remain retrievable years later.
 */
class SignatureDocument extends Model
{
    public const DOWNLOAD_PENDING     = 'pending';
    public const DOWNLOAD_STORED      = 'stored';
    public const DOWNLOAD_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'company_id',
        'signature_request_id',
        'provider_document_id',
        'filename',
        'mime',
        'size_bytes',
        'disk',
        'source_path',
        'source_checksum',
        'signed_path',
        'signed_size_bytes',
        'signed_file_url',
        'download_status',
        'download_error',
        'downloaded_at',
        'position',
    ];

    protected $casts = [
        'size_bytes'        => 'integer',
        'signed_size_bytes' => 'integer',
        'position'          => 'integer',
        'downloaded_at'     => 'datetime',
    ];

    protected $attributes = [
        'disk'            => 'local',
        'download_status' => self::DOWNLOAD_PENDING,
        'position'        => 1,
    ];

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function hasSignedCopy(): bool
    {
        return $this->download_status === self::DOWNLOAD_STORED && !empty($this->signed_path);
    }

    public function toPublicArray(): array
    {
        return [
            'id'              => $this->id,
            'filename'        => $this->filename,
            'mime'            => $this->mime,
            'size_bytes'      => $this->size_bytes,
            'position'        => $this->position,
            'download_status' => $this->download_status,
            'download_error'  => $this->download_error,
            'downloaded_at'   => $this->downloaded_at,
            'has_signed_copy' => $this->hasSignedCopy(),
        ];
    }
}
