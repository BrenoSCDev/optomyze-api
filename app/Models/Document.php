<?php

namespace App\Models;

use App\Events\DocumentLiveUpdate;
use App\Services\Documents\Support\DocumentStatus;
use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A business document — first-class, alongside Leads, Deals and Appointments.
 *
 * A Deal represents an opportunity; a Contract represents the legal agreement
 * that results from it. That agreement is this row: it has a type, a number, a
 * lifecycle, an owner, a value and a term. The PDF is an output of it, held in
 * `document_versions`, and the signature is a stage of its life, held in
 * `signature_requests`.
 *
 * Contracts are simply documents whose type sits in the `contract` category.
 * Proposals, quotes and NDAs are the same table.
 */
class Document extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'document_type_id',
        'number',
        'title',
        'status',
        'lead_id',
        'sale_id',
        'lead_company_id',
        'owner_id',
        'created_by',
        'document_template_id',
        'current_version_id',
        'value',
        'currency',
        'start_date',
        'end_date',
        'renewal_date',
        'auto_renew',
        'renewal_frequency',
        'notice_period_days',
        'approval_status',
        'approved_by',
        'approved_at',
        'sent_at',
        'signed_at',
        'rejected_at',
        'cancelled_at',
        'expires_at',
        'metadata',
        'archived_at',
    ];

    protected $casts = [
        'value'              => 'decimal:2',
        'auto_renew'         => 'boolean',
        'notice_period_days' => 'integer',
        'metadata'           => 'array',
        'start_date'         => 'date',
        'end_date'           => 'date',
        'renewal_date'       => 'date',
        'approved_at'        => 'datetime',
        'sent_at'            => 'datetime',
        'signed_at'          => 'datetime',
        'rejected_at'        => 'datetime',
        'cancelled_at'       => 'datetime',
        'expires_at'         => 'datetime',
        'archived_at'        => 'datetime',
    ];

    protected $attributes = [
        'status'     => DocumentStatus::DRAFT,
        'auto_renew' => false,
    ];

    /**
     * Broadcast every change to the company's boards.
     *
     * A model hook rather than calls sprinkled through the controller, because
     * most of what moves a contract is not a controller at all: a webhook on a
     * queue worker when the counterparty signs, `signatures:reconcile` on the
     * scheduler, a renewal command later. Anything that misses a broadcast
     * leaves a stale card on somebody's screen, and the only way to be sure
     * nothing misses it is to hang it off the write itself.
     *
     * Mirrors Record's booted() hook, which is how deal activity already
     * reaches the feed.
     */
    protected static function booted(): void
    {
        static::created(function (Document $document) {
            broadcast(new DocumentLiveUpdate('created', $document));
        });

        static::updated(function (Document $document) {
            // `status` is checked against the original so the client can
            // animate a move between columns rather than a redraw in place.
            $oldStatus = $document->getOriginal('status');
            $changed   = $document->wasChanged('status');

            broadcast(new DocumentLiveUpdate(
                $changed ? 'status_changed' : 'updated',
                $document,
                $changed ? $oldStatus : null,
            ));
        });

        static::deleted(function (Document $document) {
            broadcast(new DocumentLiveUpdate('deleted', $document));
        });
    }

    /* ============================
     | Relationships
     ============================ */

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    /** The opportunity this agreement came out of, when there was one. */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function leadCompany(): BelongsTo
    {
        return $this->belongsTo(LeadCompany::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    /** The version that is live — what gets sent, signed and downloaded. */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * The signature process, when one has been started. One live request per
     * document: re-sending a cancelled document creates a new one.
     */
    public function signatureRequest(): HasOne
    {
        return $this->hasOne(SignatureRequest::class)->latestOfMany();
    }

    public function signatureRequests(): HasMany
    {
        return $this->hasMany(SignatureRequest::class);
    }

    /* ============================
     | Scopes
     ============================ */

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    /** Documents of a given family — 'contract', 'proposal', 'nda'… */
    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $query->whereHas('type', fn ($q) => $q->where('category', $category));
    }

    /* ============================
     | State
     ============================ */

    public function isEditable(): bool
    {
        return DocumentStatus::isEditable($this->status) && !$this->archived_at;
    }

    public function isTerminal(): bool
    {
        return DocumentStatus::isTerminal($this->status);
    }

    public function isWithProvider(): bool
    {
        return DocumentStatus::isWithProvider($this->status);
    }

    /** Has a rendered version to send, download or sign. */
    public function hasGeneratedVersion(): bool
    {
        return $this->current_version_id !== null;
    }

    /**
     * The record this document is "about", preferring the most specific.
     * Used to attribute timeline entries and to resolve variables.
     */
    public function primaryRecord(): Lead|Sale|LeadCompany|null
    {
        return $this->sale ?? $this->lead ?? $this->leadCompany;
    }

    /**
     * The board-card shape.
     *
     * Lives on the model rather than in the controller because two callers
     * need it to be identical: the REST list that paints the board, and the
     * websocket broadcast that patches it afterwards. If a card gained a field
     * in one and not the other, a live update would blank it.
     *
     * Relations are loaded defensively — a broadcast is often built from a
     * model that arrived through an event, not a controller query.
     */
    public function toCardArray(): array
    {
        $this->loadMissing(['type:id,label,category', 'owner:id,name', 'currentVersion', 'lead', 'sale']);

        return [
            'id'               => $this->id,
            'number'           => $this->number,
            'title'            => $this->title,
            'status'           => $this->status,
            'document_type_id' => $this->document_type_id,
            'type_label'       => $this->type?->label,
            'category'         => $this->type?->category,
            'lead_id'          => $this->lead_id,
            'lead_name'        => $this->lead
                ? trim("{$this->lead->first_name} {$this->lead->last_name}")
                : null,
            'sale_id'          => $this->sale_id,
            'deal_name'        => $this->sale?->name,
            'lead_company_id'  => $this->lead_company_id,
            'owner_id'         => $this->owner_id,
            'owner_name'       => $this->owner?->name,
            'value'            => $this->value,
            'currency'         => $this->currency,
            'start_date'       => $this->start_date,
            'end_date'         => $this->end_date,
            'renewal_date'     => $this->renewal_date,
            'auto_renew'       => $this->auto_renew,
            'sent_at'          => $this->sent_at,
            'signed_at'        => $this->signed_at,
            'rejected_at'      => $this->rejected_at,
            'cancelled_at'     => $this->cancelled_at,
            'archived_at'      => $this->archived_at,
            'version_number'   => $this->currentVersion?->version_number,
            'has_pdf'          => (bool) $this->currentVersion?->hasPdf(),
            'is_editable'      => $this->isEditable(),
            'created_at'       => $this->created_at,
        ];
    }
}
