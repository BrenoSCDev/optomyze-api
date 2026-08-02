<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'body',
        'mentions',
        'edited_at',
    ];

    protected $casts = [
        'mentions'  => 'array',
        'edited_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pull @mentions out of a comment body, keeping only users who belong to
     * the given company — a mention is not a way to discover other tenants.
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
