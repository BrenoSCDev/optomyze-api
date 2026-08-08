<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable document body, written in the platform's template language.
 *
 * The publish flag is the point of the model: legal writes and publishes,
 * everyone else may only generate from something published. An unpublished
 * draft template cannot be used to produce a contract.
 *
 * Templates are not versioned. They do not need to be — every generated
 * document snapshots the body it used into its own version, so editing a
 * template can never rewrite history.
 */
class DocumentTemplate extends Model
{
    /**
     * The editor schema version this codebase writes.
     *
     * Bump when a node type is added or renamed in a way an older editor
     * cannot read. Stored on every save so a document written by a newer
     * editor is refused with an explanation rather than failing inside
     * ProseMirror.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Defaults for a template that has never been configured.
     *
     * Millimetres, because that is what paper is specified in everywhere
     * outside the US and what both the editor canvas and the PDF need; points
     * and pixels are conversions of this, not the other way round.
     */
    public const DEFAULT_PAGE_SETTINGS = [
        'paper'             => 'a4',
        'orientation'       => 'portrait',
        'margins'           => ['top' => 25, 'right' => 20, 'bottom' => 30, 'left' => 20],
        'header_offset'     => 12,
        'footer_offset'     => 15,
        'font_family'       => 'DejaVu Sans',
        'font_size'         => 11,
        'line_height'       => 1.6,
        'paragraph_spacing' => 10,
    ];

    protected $fillable = [
        'company_id',
        'document_type_id',
        'name',
        'description',
        'body_html',
        'body_doc',
        'doc_schema_version',
        'header_html',
        'header_doc',
        'footer_html',
        'footer_doc',
        'page_settings',
        'variables_schema',
        'is_published',
        'published_at',
        'created_by',
        'is_active',
    ];

    protected $casts = [
        'variables_schema'   => 'array',
        'body_doc'           => 'array',
        'header_doc'         => 'array',
        'footer_doc'         => 'array',
        'page_settings'      => 'array',
        'doc_schema_version' => 'integer',
        'is_published'       => 'boolean',
        'is_active'          => 'boolean',
        'published_at'       => 'datetime',
    ];

    protected $attributes = [
        'is_published' => false,
        'is_active'    => true,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true)->where('is_active', true);
    }

    public function isUsable(): bool
    {
        return $this->is_published && $this->is_active;
    }

    /**
     * `body_html` is omitted from list responses — template bodies are large
     * and the list only needs to identify them. The detail endpoint returns it.
     */
    public function toPublicArray(bool $withBody = false): array
    {
        $payload = [
            'id'               => $this->id,
            'document_type_id' => $this->document_type_id,
            'type_label'       => $this->type?->label,
            'name'             => $this->name,
            'description'      => $this->description,
            'variables_schema' => $this->variables_schema ?? [],
            'is_published'     => $this->is_published,
            'published_at'     => $this->published_at,
            'is_active'        => $this->is_active,
            'created_by'       => $this->creator?->name,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];

        if ($withBody) {
            $payload['body_html']   = $this->body_html;
            $payload['header_html'] = $this->header_html;
            $payload['footer_html'] = $this->footer_html;

            // The structured model. Null on templates written before the
            // visual editor, which is exactly the signal the client uses to
            // offer conversion instead of opening an empty canvas.
            $payload['body_doc']           = $this->body_doc;
            $payload['header_doc']         = $this->header_doc;
            $payload['footer_doc']         = $this->footer_doc;
            $payload['doc_schema_version'] = $this->doc_schema_version;
            $payload['has_structured_body'] = $this->hasStructuredBody();
            $payload['page_settings']      = $this->pageSettings();
        }

        return $payload;
    }

    /** Authored in the visual editor rather than as raw HTML. */
    public function hasStructuredBody(): bool
    {
        return !empty($this->body_doc);
    }

    /**
     * Written by an editor newer than this codebase understands.
     *
     * Opening one anyway would silently drop the nodes this build has no
     * schema for — and silently dropping a clause from a contract template is
     * the worst failure this module has.
     */
    public function isFromNewerEditor(): bool
    {
        return (int) $this->doc_schema_version > self::SCHEMA_VERSION;
    }

    /**
     * Page setup, with defaults filled in.
     *
     * Merged rather than replaced so a template configured before a setting
     * existed picks up that setting's default instead of a null the renderer
     * and the editor would each guess at differently.
     */
    public function pageSettings(): array
    {
        $stored = $this->page_settings ?? [];

        return array_replace_recursive(self::DEFAULT_PAGE_SETTINGS, $stored);
    }

    /**
     * Page setup in the shape PdfRenderer::render() expects.
     *
     * One translation point, so "the editor's idea of the page" and "the PDF's
     * idea of the page" cannot drift into two different documents.
     */
    public function pdfOptions(): array
    {
        $settings = $this->pageSettings();

        return [
            'paper'       => $settings['paper'],
            'orientation' => $settings['orientation'],
            'margins'     => $settings['margins'],
            'typography'  => [
                'font_family'       => $settings['font_family'],
                'font_size'         => $settings['font_size'],
                'line_height'       => $settings['line_height'],
                'paragraph_spacing' => $settings['paragraph_spacing'],
            ],
            'header_offset' => $settings['header_offset'],
            'footer_offset' => $settings['footer_offset'],
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentTemplateRevision::class)->orderByDesc('revision_number');
    }
}
