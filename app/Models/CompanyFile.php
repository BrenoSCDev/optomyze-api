<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One stored file, attributed to the company that owns it.
 *
 * Written through CompanyStorage rather than directly — the ledger and the
 * denormalised counter on `companies` have to move together, and doing that in
 * one place is what keeps them from drifting.
 */
class CompanyFile extends Model
{
    /**
     * Every kind of file the CRM stores, from the audit of write sites.
     * Kept as constants so a typo in a category is a fatal, not a silently
     * mis-bucketed row.
     */
    public const CATEGORY_WHATSAPP_MEDIA   = 'whatsapp_media';
    public const CATEGORY_INSTAGRAM_MEDIA  = 'instagram_media';
    public const CATEGORY_CHAT_ATTACHMENT  = 'chat_attachment';
    public const CATEGORY_LEAD_DOCUMENT    = 'lead_document';
    public const CATEGORY_DEAL_DOCUMENT    = 'deal_document';
    public const CATEGORY_PRODUCT_IMAGE    = 'product_image';
    public const CATEGORY_PROFILE_PICTURE  = 'profile_picture';
    public const CATEGORY_COMPANY_BRANDING = 'company_branding';

    /** Contracts sent for signature, and the signed copies pulled back. */
    public const CATEGORY_SIGNATURE_DOCUMENT = 'signature_document';

    /**
     * Logos, signatures, stamps and letterheads placed in a document template.
     *
     * Its own category rather than company_branding: these are referenced by
     * template bodies, so "what would I break by deleting this" has a
     * different answer, and they are inlined into every generated PDF, so
     * their weight is worth seeing separately in the usage breakdown.
     */
    public const CATEGORY_DOCUMENT_ASSET = 'document_asset';

    public const CATEGORIES = [
        self::CATEGORY_WHATSAPP_MEDIA,
        self::CATEGORY_INSTAGRAM_MEDIA,
        self::CATEGORY_CHAT_ATTACHMENT,
        self::CATEGORY_LEAD_DOCUMENT,
        self::CATEGORY_DEAL_DOCUMENT,
        self::CATEGORY_PRODUCT_IMAGE,
        self::CATEGORY_PROFILE_PICTURE,
        self::CATEGORY_COMPANY_BRANDING,
        self::CATEGORY_SIGNATURE_DOCUMENT,
        self::CATEGORY_DOCUMENT_ASSET,
    ];

    protected $fillable = [
        'company_id',
        'disk',
        'path',
        'size_bytes',
        'mime',
        'category',
        'owner_type',
        'owner_id',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
