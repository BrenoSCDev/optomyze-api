<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Conversation extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'type',
        'name',
        'cover_image',
        'created_by',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function getCoverImageUrlAttribute(): ?string
    {
        return $this->cover_image ? Storage::url($this->cover_image) : null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot('role', 'last_read_at')
            ->withTimestamps();
    }

    /**
     * Messages this user has not seen yet.
     *
     * Their own messages never count — you have read what you just sent — and
     * a participant with no last_read_at (added to an existing group) starts
     * from the moment they joined rather than inheriting the whole history.
     */
    public function unreadCountFor(User $user): int
    {
        $pivot = $this->participants->firstWhere('id', $user->id)?->pivot
            ?? $this->participants()->where('users.id', $user->id)->first()?->pivot;

        if (!$pivot) {
            return 0;
        }

        $since = $pivot->last_read_at ?? $pivot->created_at;

        return $this->messages()
            ->where('user_id', '!=', $user->id)
            ->when($since, fn ($q) => $q->where('created_at', '>', $since))
            ->count();
    }

    /**
     * Move this user's high-water mark to now.
     *
     * Returns false when nothing changed — the caller can then skip the
     * broadcast rather than telling everyone about a no-op.
     */
    public function markReadFor(User $user): bool
    {
        $membership = $this->participants()->where('users.id', $user->id)->first();

        if (!$membership) {
            return false;
        }

        if ($this->unreadCountFor($user) === 0) {
            return false;
        }

        $this->participants()->updateExistingPivot($user->id, ['last_read_at' => now()]);

        return true;
    }

    /**
     * The other participants who have caught up past a given message — what
     * drives the "seen" indicator on your own messages.
     */
    public function readersOf(InternalMessage $message, User $author): array
    {
        return $this->participants
            ->filter(function (User $p) use ($message, $author) {
                if ($p->id === $author->id || !$p->pivot->last_read_at) {
                    return false;
                }

                // Pivot columns carry no casts, so last_read_at arrives as a
                // string. Comparing it to a Carbon with >= coerces rather than
                // compares, and quietly reports everyone as having read.
                return Carbon::parse($p->pivot->last_read_at)->gte($message->created_at);
            })
            ->pluck('id')
            ->values()
            ->all();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InternalMessage::class);
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(InternalMessage::class)->latestOfMany();
    }
}
