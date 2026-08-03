<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * CALENDAR
 *
 * One company owns many calendars. Everything scheduled — appointments today,
 * tasks with a due date — hangs off one of them.
 *
 * The invariant this model exists to protect: a company always has exactly one
 * default calendar. `makeDefault()` is the only supported way to move it, and
 * it demotes the previous holder in the same transaction. Nothing else should
 * write `is_default` directly.
 */
class Calendar extends Model
{
    use SoftDeletes;

    /** What the calendar stands for. Drives future availability logic. */
    public const TYPES = ['company', 'team', 'user', 'resource', 'custom'];

    /** Who can see it. `team` is accepted now and enforced once teams own calendars. */
    public const VISIBILITIES = ['company', 'team', 'private'];

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'color',
        'type',
        'visibility',
        'owner_id',
        'timezone',
        'is_default',
        'is_active',
        'settings',
        'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'settings'   => 'array',
    ];

    protected $attributes = [
        'color'      => '#3B82F6',
        'type'       => 'custom',
        'visibility' => 'company',
        'is_default' => false,
        'is_active'  => true,
    ];

    /* ---------------- relations ---------------- */

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Who the calendar is *for*; null on shared calendars. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Explicit access grants, for calendars `visibility` alone cannot describe. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'calendar_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** External providers this calendar syncs with. Empty until sync ships. */
    public function integrations(): HasMany
    {
        return $this->hasMany(CalendarIntegration::class);
    }

    /** Public booking configuration. Absent until a booking page is published. */
    public function bookingSettings(): HasOne
    {
        return $this->hasOne(CalendarBookingSetting::class);
    }

    /* ---------------- scopes ---------------- */

    public function scopeFromCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('calendars.company_id', $companyId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('calendars.is_active', true);
    }

    /**
     * Calendars this user may see.
     *
     * Company-visible calendars are open to everyone in the company; private
     * ones need ownership or an explicit membership row. Admins see all of
     * their company's calendars, which is what makes them able to fix a
     * private calendar somebody left behind.
     */
    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->whereIn('calendars.visibility', ['company', 'team'])
                ->orWhere('calendars.owner_id', $user->id)
                ->orWhereExists(function ($sub) use ($user) {
                    $sub->selectRaw(1)
                        ->from('calendar_members')
                        ->whereColumn('calendar_members.calendar_id', 'calendars.id')
                        ->where('calendar_members.user_id', $user->id);
                });
        });
    }

    /* ---------------- default calendar ---------------- */

    /**
     * This company's default calendar, created if it has somehow gone missing.
     *
     * The safety net matters: appointments are created from webhooks and the
     * public API as well as the UI, and none of those should fail because a
     * company predates this feature or had its calendar deleted out of band.
     */
    public static function defaultFor(int $companyId): self
    {
        $existing = static::where('company_id', $companyId)->where('is_default', true)->first();

        if ($existing) {
            return $existing;
        }

        // Promote an existing calendar rather than adding another one, so a
        // company never ends up with a stray empty "Company Calendar".
        $fallback = static::where('company_id', $companyId)->orderBy('id')->first();

        if ($fallback) {
            $fallback->update(['is_default' => true, 'is_active' => true]);
            return $fallback;
        }

        return static::create([
            'company_id'  => $companyId,
            'name'        => 'Company Calendar',
            'description' => 'The default calendar for this company.',
            'type'        => 'company',
            'visibility'  => 'company',
            'is_default'  => true,
            'is_active'   => true,
        ]);
    }

    /**
     * Make this the company's default, demoting whichever calendar held it.
     *
     * A default calendar is also forced active: the fallback every unfiled
     * appointment lands on cannot be one that is hidden from the picker.
     */
    public function makeDefault(): void
    {
        static::where('company_id', $this->company_id)
            ->where('id', '!=', $this->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true, 'is_active' => true]);
    }
}
