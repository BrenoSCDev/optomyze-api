<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A task in the workspace: Space > List > Task > Subtask.
 *
 * Two things about this model are worth knowing before changing it:
 *
 * 1. `status` (the legacy four-value enum) is derived, not authoritative.
 *    `task_status_id` is the source of truth; the booted() hook below mirrors
 *    it down to `status` and keeps `completed_at` in step. Records, the
 *    reports dashboard, the lead-details panel and the public v1 API all still
 *    read `status`, so it must never be allowed to drift.
 *
 * 2. `assigned_to` is the *primary* assignee and stays populated for the same
 *    back-compat reason; `assignees` is the full set.
 *
 * The task now carries its own company_id, so HasCompanyTimezone resolves the
 * timezone directly instead of the old chain through the lead.
 */
class Task extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'calendar_id',
        'task_list_id',
        'task_status_id',
        'parent_task_id',
        'lead_id',
        'sale_id',
        'title',
        'description',
        'status',
        'priority',
        'reminder',
        'due_date',
        'start_date',
        'position',
        'completed_at',
        'archived_at',
        'assigned_to',
        'created_by',
    ];

    protected $casts = [
        'due_date'     => 'datetime',
        'start_date'   => 'datetime',
        'completed_at' => 'datetime',
        'archived_at'  => 'datetime',
        'reminder'     => 'boolean',
        'position'     => 'float',
    ];

    /*
    |--------------------------------------------------------------------------
    | Derived legacy status
    |--------------------------------------------------------------------------
    */

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            if (!$task->isDirty('task_status_id') || !$task->task_status_id) {
                return;
            }

            $status = TaskStatus::find($task->task_status_id);
            if (!$status) {
                return;
            }

            $task->status = $status->legacyStatus();

            // Moving into a done column completes the task; moving back out
            // reopens it. Without this, a task dragged out of Done would keep
            // its completed_at and stay "finished" in every report.
            if ($status->type === 'done') {
                $task->completed_at ??= now();
            } elseif ($status->type !== 'closed') {
                $task->completed_at = null;
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The calendar this task appears on when it has a due date. Tasks are
     * scheduling records on the calendar grid, so they are filterable by
     * calendar like appointments are.
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'task_status_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id')->orderBy('position');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** Primary assignee — kept in step with the first of `assignees`. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees')->withTimestamps();
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers')->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeFromCompany(Builder $query, $companyId): Builder
    {
        return $query->where('tasks.company_id', $companyId);
    }

    /**
     * Restrict to tasks this user may see. A task counts as visible if the
     * user is the primary assignee, one of the additional assignees, or its
     * creator — the last clause matters because a personal task with nobody
     * assigned would otherwise be invisible to the person who made it.
     */
    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        $ids = $user->visibleUserIds();
        if ($ids === null) {
            return $query;
        }

        return $query->where(function ($q) use ($ids, $user) {
            $q->whereIn('tasks.assigned_to', $ids)
              ->orWhereHas('assignees', fn ($sub) => $sub->whereIn('users.id', $ids))
              ->orWhere('tasks.created_by', $user->id);

            if ($user->isManager()) {
                $q->orWhereNull('tasks.assigned_to');
            }
        });
    }

    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_task_id');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('tasks.archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('tasks.archived_at');
    }

    public function scopeArchiveFilter(Builder $query, ?string $mode): Builder
    {
        return match ($mode) {
            'archived' => $query->archived(),
            'all'      => $query,
            default    => $query->notArchived(),
        };
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now());
    }

    /** Not finished — the "still needs doing" set. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    /*
    |--------------------------------------------------------------------------
    | Behaviour
    |--------------------------------------------------------------------------
    */

    public function isOverdue(): bool
    {
        return $this->due_date
            && !$this->completed_at
            && $this->due_date->isPast();
    }

    /**
     * Complete via the list's own done column so the board and the legacy enum
     * agree. Falls back to writing the enum directly for tasks that have not
     * been filed into a list.
     */
    public function markAsCompleted(): void
    {
        $doneStatus = $this->task_list_id
            ? TaskStatus::where('task_list_id', $this->task_list_id)
                ->where('type', 'done')
                ->orderBy('order')
                ->first()
            : null;

        if ($doneStatus) {
            $this->update(['task_status_id' => $doneStatus->id]);
            return;
        }

        $this->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /**
     * Replace the assignee set, keeping `assigned_to` pointing at the first of
     * them so every legacy consumer still sees an owner.
     */
    public function syncAssignees(array $userIds): void
    {
        $this->assignees()->sync($userIds);
        $this->update(['assigned_to' => $userIds[0] ?? null]);
    }

    /** Fractional midpoint, so a reorder touches one row rather than the column. */
    public static function positionBetween(?float $before, ?float $after): float
    {
        if ($before === null && $after === null) return 1000;
        if ($before === null) return $after - 1000;
        if ($after === null)  return $before + 1000;

        return ($before + $after) / 2;
    }

    public static function validationRules(): array
    {
        return [
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string',
            'task_list_id'   => 'nullable|integer',
            'task_status_id' => 'nullable|integer',
            'parent_task_id' => 'nullable|integer',
            'lead_id'        => 'nullable|integer',
            'sale_id'        => 'nullable|integer',
            'calendar_id'    => 'nullable|integer',
            'priority'       => 'nullable|in:low,medium,high,urgent',
            'due_date'       => 'nullable|date',
            'start_date'     => 'nullable|date',
            'reminder'       => 'nullable|boolean',
            'assignee_ids'   => 'nullable|array',
            'assignee_ids.*' => 'integer',
            'watcher_ids'    => 'nullable|array',
            'watcher_ids.*'  => 'integer',
            'tag_ids'        => 'nullable|array',
            'tag_ids.*'      => 'integer',
        ];
    }
}
