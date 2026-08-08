<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A point-in-time copy of a template body.
 *
 * Generated documents already snapshot the body they used, so a contract was
 * never at risk from someone editing the template afterwards. What was missing
 * is the author's own undo: no way back from "I have just rewritten the
 * standard services agreement and made it worse".
 *
 * Whole bodies, not diffs. Restoring a revision must be a copy, not a replay
 * of a chain that one corrupt link can break.
 */
class DocumentTemplateRevision extends Model
{
    public const TRIGGER_AUTOSAVE = 'autosave';
    public const TRIGGER_MANUAL   = 'manual';
    public const TRIGGER_PUBLISH  = 'publish';
    public const TRIGGER_RESTORE  = 'restore';

    protected $fillable = [
        'company_id',
        'document_template_id',
        'revision_number',
        'body_doc',
        'body_html',
        'header_doc',
        'header_html',
        'footer_doc',
        'footer_html',
        'page_settings',
        'trigger',
        'label',
        'created_by',
    ];

    protected $casts = [
        'body_doc'        => 'array',
        'header_doc'      => 'array',
        'footer_doc'      => 'array',
        'page_settings'   => 'array',
        'revision_number' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Capture the template as it stands.
     *
     * The revision number is allocated under a row lock on the template, for
     * the same reason document numbers are: two autosaves landing in the same
     * second must not collide on the unique index and lose one of them.
     */
    public static function capture(
        DocumentTemplate $template,
        string $trigger = self::TRIGGER_AUTOSAVE,
        ?int $userId = null,
        ?string $label = null,
    ): self {
        return DB::transaction(function () use ($template, $trigger, $userId, $label) {
            $locked = DocumentTemplate::whereKey($template->id)->lockForUpdate()->first();

            $next = (int) self::where('document_template_id', $locked->id)->max('revision_number') + 1;

            return self::create([
                'company_id'           => $locked->company_id,
                'document_template_id' => $locked->id,
                'revision_number'      => $next,
                'body_doc'             => $locked->body_doc,
                'body_html'            => $locked->body_html,
                'header_doc'           => $locked->header_doc,
                'header_html'          => $locked->header_html,
                'footer_doc'           => $locked->footer_doc,
                'footer_html'          => $locked->footer_html,
                'page_settings'        => $locked->page_settings,
                'trigger'              => $trigger,
                'label'                => $label,
                'created_by'           => $userId,
            ]);
        });
    }

    /**
     * Bodies are large and a revision list is a list of *labels*. The body
     * only travels when one revision is opened or restored.
     */
    public function toPublicArray(bool $withBody = false): array
    {
        $payload = [
            'id'              => $this->id,
            'revision_number' => $this->revision_number,
            'trigger'         => $this->trigger,
            'label'           => $this->label,
            'author'          => $this->author?->name,
            'created_at'      => $this->created_at,
        ];

        if ($withBody) {
            $payload['body_doc']      = $this->body_doc;
            $payload['body_html']     = $this->body_html;
            $payload['header_doc']    = $this->header_doc;
            $payload['header_html']   = $this->header_html;
            $payload['footer_doc']    = $this->footer_doc;
            $payload['footer_html']   = $this->footer_html;
            $payload['page_settings'] = $this->page_settings;
        }

        return $payload;
    }
}
