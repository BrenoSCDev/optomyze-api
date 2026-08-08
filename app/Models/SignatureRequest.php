<?php

namespace App\Models;

use App\Services\Signatures\Support\SignatureStatus;
use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One signing process: documents, signers, and where it stands.
 *
 * Vendor-neutral by construction — `provider` says which vendor is handling
 * it and `provider_request_id` is that vendor's identifier, but nothing else
 * on this model knows or cares which one it is.
 */
class SignatureRequest extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'document_id',
        'document_version_id',
        'signature_provider_id',
        'provider',
        'user_id',
        'provider_request_id',
        'title',
        'subject',
        'message',
        'status',
        'locale',
        'auto_close',
        'reminder_interval',
        'block_after_refusal',
        'deadline_at',
        'queued_at',
        'sent_at',
        'completed_at',
        'canceled_at',
        'refused_at',
        'last_error',
        'attempt_count',
        'metadata',
    ];

    protected $casts = [
        'auto_close'          => 'boolean',
        'block_after_refusal' => 'boolean',
        'reminder_interval'   => 'integer',
        'attempt_count'       => 'integer',
        'metadata'            => 'array',
        'deadline_at'         => 'datetime',
        'queued_at'           => 'datetime',
        'sent_at'             => 'datetime',
        'completed_at'        => 'datetime',
        'canceled_at'         => 'datetime',
        'refused_at'          => 'datetime',
    ];

    protected $attributes = [
        'status'     => SignatureStatus::DRAFT,
        'auto_close' => true,
    ];

    /* ============================
     | Relationships
     ============================ */

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(SignatureProviderAccount::class, 'signature_provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The agreement being signed. The document owns the CRM relationships —
     * lead, deal, customer — so this class no longer carries its own.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Exactly which rendered version went out. Without this, a document
     * regenerated after sending could not be told apart from the one the
     * signer actually saw.
     */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SignatureDocument::class)->orderBy('position');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(SignatureSigner::class)->orderBy('signing_group')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignatureEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SignatureAuditLog::class);
    }

    /* ============================
     | Scopes
     ============================ */

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', SignatureStatus::OPEN);
    }

    public function scopeForDocument($query, int $documentId)
    {
        return $query->where('document_id', $documentId);
    }

    /* ============================
     | State
     ============================ */

    public function isDraft(): bool
    {
        return $this->status === SignatureStatus::DRAFT;
    }

    public function isTerminal(): bool
    {
        return SignatureStatus::isTerminal($this->status);
    }

    /** Editing signers or documents is only safe before anything was sent. */
    public function isEditable(): bool
    {
        return in_array($this->status, [SignatureStatus::DRAFT, SignatureStatus::FAILED], true);
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status'     => SignatureStatus::FAILED,
            'last_error' => Str::limit($error, 2000),
        ])->save();
    }

    /** True once every signer has signed. */
    public function allSignersSigned(): bool
    {
        $signers = $this->relationLoaded('signers') ? $this->signers : $this->signers()->get();

        return $signers->isNotEmpty()
            && $signers->every(fn (SignatureSigner $signer) => $signer->status === \App\Services\Signatures\Support\SignerStatus::SIGNED);
    }
}
