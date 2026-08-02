<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'title',
        'is_done',
        'order',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'is_done'      => 'boolean',
        'order'        => 'integer',
        'completed_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** Keeps is_done and its audit columns from drifting apart. */
    public function setDone(bool $done, ?int $userId = null): void
    {
        $this->update([
            'is_done'      => $done,
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $userId : null,
        ]);
    }
}
