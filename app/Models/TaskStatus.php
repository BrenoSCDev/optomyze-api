<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A board column. Companies rename and recolour these freely; `type` is the
 * stable semantic the rest of the CRM reads, so reports and the legacy
 * `tasks.status` enum keep working whatever the column is called.
 */
class TaskStatus extends Model
{
    use HasFactory;

    protected $table = 'task_statuses';

    protected $fillable = [
        'company_id',
        'task_list_id',
        'name',
        'color',
        'type',
        'order',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'order'      => 'integer',
    ];

    /** Semantic type -> the legacy `tasks.status` enum value. */
    public const TYPE_TO_LEGACY = [
        'not_started' => 'pending',
        'active'      => 'in_progress',
        'done'        => 'completed',
        'closed'      => 'cancelled',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /** True once work in this column counts as finished (done or cancelled). */
    public function isClosed(): bool
    {
        return in_array($this->type, ['done', 'closed'], true);
    }

    public function legacyStatus(): string
    {
        return self::TYPE_TO_LEGACY[$this->type] ?? 'pending';
    }

    public static function validationRules(): array
    {
        return [
            'name'       => 'required|string|max:60',
            'color'      => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'type'       => 'required|in:not_started,active,done,closed',
            'order'      => 'nullable|integer|min:0',
            'is_default' => 'nullable|boolean',
        ];
    }
}
