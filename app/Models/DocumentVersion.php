<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable rendering of a document.
 *
 * Nothing here is ever updated after creation. A generated contract is
 * evidence: the version somebody signed has to stay byte-identical to what
 * they were shown, so regenerating writes a new row and the document repoints
 * `current_version_id` at it.
 *
 * `source_html` is the template body copied in at generation time. That copy is
 * what makes editing a template safe — last year's contract keeps last year's
 * wording. `variables` freezes every resolved value for the same reason: the
 * lead may have been renamed since, and the document still has to be
 * explainable.
 */
class DocumentVersion extends Model
{
    protected $fillable = [
        'company_id',
        'document_id',
        'version_number',
        'source_html',
        'rendered_html',
        'variables',
        'pdf_disk',
        'pdf_path',
        'pdf_size_bytes',
        'checksum',
        'generated_by',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'variables'      => 'array',
        'pdf_size_bytes' => 'integer',
    ];

    protected $attributes = [
        'pdf_disk' => 'local',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function hasPdf(): bool
    {
        return !empty($this->pdf_path);
    }

    /**
     * The rendered HTML is deliberately not included: it can be megabytes, and
     * the detail view fetches it from its own endpoint when the user asks to
     * preview a specific version.
     */
    public function toPublicArray(): array
    {
        return [
            'id'             => $this->id,
            'version_number' => $this->version_number,
            'pdf_size_bytes' => $this->pdf_size_bytes,
            'checksum'       => $this->checksum,
            'has_pdf'        => $this->hasPdf(),
            'generated_by'   => $this->generatedBy?->name,
            'created_at'     => $this->created_at,
        ];
    }
}
