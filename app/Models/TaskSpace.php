<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Top level of the task hierarchy: Space > List > Task > Subtask.
 */
class TaskSpace extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'color',
        'icon',
        'order',
        'archived_at',
        'created_by',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
        'order'       => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lists(): HasMany
    {
        return $this->hasMany(TaskList::class)->orderBy('order');
    }

    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(
            Task::class,
            TaskList::class,
            'task_space_id', // FK on task_lists
            'task_list_id',  // FK on tasks
            'id',
            'id'
        );
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

    /** active | archived | all — mirrors Lead::scopeArchiveFilter. */
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

    public static function validationRules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon'        => 'nullable|string|max:40',
            'order'       => 'nullable|integer|min:0',
        ];
    }
}
