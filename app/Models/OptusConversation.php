<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Optus thread, private to the user who owns it.
 *
 * There is deliberately no company-wide read path: a conversation is only ever
 * reached through its owner, so "private" is a property of every query rather
 * than a flag someone has to remember to check.
 */
class OptusConversation extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'title',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        // Chronological by id: two messages in the same turn share a
        // created_at to the second, and a timestamp sort would let the
        // assistant's reply come back before the question.
        return $this->hasMany(OptusMessage::class)->orderBy('id');
    }

    public function scopeOwnedBy($query, User $user)
    {
        return $query->where('user_id', $user->id)
            ->where('company_id', $user->company_id);
    }

    /**
     * The user's active thread, created on first use.
     */
    public static function currentFor(User $user): self
    {
        return static::ownedBy($user)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first()
            ?? static::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
            ]);
    }
}
