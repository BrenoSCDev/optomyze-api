<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A list owns its own status set — the board columns users see. Creating a
 * list without statuses would leave an unusable board, so the default set is
 * seeded automatically on create.
 */
class TaskList extends Model
{
    use HasFactory;

    protected $table = 'task_lists';

    protected $fillable = [
        'company_id',
        'task_space_id',
        'name',
        'description',
        'color',
        'order',
        'default_view',
        'archived_at',
        'created_by',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
        'order'       => 'integer',
    ];

    /** name, color, type, is_default — mirrors the seeding migration. */
    public const DEFAULT_STATUSES = [
        ['To Do',       '#94a3b8', 'not_started', true],
        ['In Progress', '#3b82f6', 'active',      false],
        ['Blocked',     '#f59e0b', 'active',      false],
        ['Done',        '#10b981', 'done',        false],
        ['Cancelled',   '#64748b', 'closed',      false],
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(TaskSpace::class, 'task_space_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(TaskStatus::class)->orderBy('order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Top-level tasks only — subtasks are rendered nested under their parent. */
    public function rootTasks(): HasMany
    {
        return $this->tasks()->whereNull('parent_task_id');
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeArchiveFilter($query, ?string $mode)
    {
        return match ($mode) {
            'archived' => $query->archived(),
            'all'      => $query,
            default    => $query->notArchived(),
        };
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('name');
    }

    /** The status new tasks land in. */
    public function defaultStatus(): ?TaskStatus
    {
        return $this->statuses()->where('is_default', true)->first()
            ?? $this->statuses()->orderBy('order')->first();
    }

    /** Give a brand-new list a working board. */
    public function seedDefaultStatuses(): void
    {
        foreach (self::DEFAULT_STATUSES as $i => [$name, $color, $type, $isDefault]) {
            $this->statuses()->create([
                'company_id' => $this->company_id,
                'name'       => $name,
                'color'      => $color,
                'type'       => $type,
                'order'      => $i,
                'is_default' => $isDefault,
            ]);
        }
    }

    public static function validationRules(): array
    {
        return [
            'task_space_id' => 'required|integer',
            'name'          => 'required|string|max:255',
            'description'   => 'nullable|string|max:500',
            'color'         => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'order'         => 'nullable|integer|min:0',
            'default_view'  => 'nullable|in:list,board,calendar',
        ];
    }
}
