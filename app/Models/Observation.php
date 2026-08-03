<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A note, on anything.
 *
 * Notes hang off a lead or a deal through `notable`, and off each other through
 * `parent_id` — a reply is an ordinary note that names its parent, so a future
 * note on a task or a product needs no new table and no new model.
 *
 * Threads are deliberately one level deep. Replies to replies read as a tree
 * nobody can follow in a sidebar; every reply belongs to the note that started
 * the thread.
 */
class Observation extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'lead_id',
        'notable_type',
        'notable_id',
        'parent_id',
        'user_id',
        'note',
        'mentions',
        'edited_at',
    ];

    protected $casts = [
        'mentions'  => 'array',
        'edited_at' => 'datetime',
    ];

    /** The lead or deal this note is about. */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Kept while `lead_id` is still populated, so existing callers and any
     * rollback keep working. Prefer `notable`.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    /** Top-level notes only — the ones that start a thread. */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeFromCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /** Notes attached to one record. */
    public function scopeFor(Builder $query, Model $notable): Builder
    {
        return $query
            ->where('notable_type', $notable->getMorphClass())
            ->where('notable_id', $notable->getKey());
    }

    /**
     * Pull @mentions out of a note, keeping only users who belong to the given
     * company — a mention is not a way to discover other tenants.
     *
     * Same `@[id]` markup and same rule as TaskComment, so one mention picker
     * serves both surfaces.
     */
    public static function parseMentions(string $body, int $companyId): array
    {
        if (!preg_match_all('/@\[(\d+)\]/', $body, $matches)) {
            return [];
        }

        return User::whereIn('id', array_unique($matches[1]))
            ->where('company_id', $companyId)
            ->pluck('id')
            ->all();
    }
}
