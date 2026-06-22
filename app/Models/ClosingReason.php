<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClosingReason extends Model
{
    const OUTCOME_WON = 'won';
    const OUTCOME_LOST = 'lost';
    const OUTCOME_NEUTRAL = 'neutral';

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'is_active',
        'sort_order',
        'outcome',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    public function closingMessages(): BelongsToMany
    {
        return $this->belongsToMany(ClosingMessage::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
