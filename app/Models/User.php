<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Sale;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasCompanyTimezone, HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'identity_id',
        'company_id',
        'name',
        'email',
        'password',
        'phone',
        'role',
        'is_active',
        'last_login_at',
        'settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'settings' => 'array',
    ];

    /**
     * Give every new user a preferences row, and an identity to sign in as.
     *
     * Placed on the model rather than in the controllers because users are
     * created from four different call sites (UserController has three,
     * ManagerUserController one) plus factories and seeders. A hook here covers
     * all of them and cannot be forgotten when a fifth is added.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->identity_id ??= static::resolveIdentityFor($user)->id;
        });

        static::created(function (User $user) {
            $user->preferences()->create(UserPreference::DEFAULTS);
        });
    }

    /**
     * The person this new account belongs to.
     *
     * An address that already has an identity is the same person gaining an
     * account in another company, so the existing identity is reused and its
     * password left alone — they sign in with the credentials they already
     * have. Callers that need to know this happened should compare the
     * identity's `wasRecentlyCreated` flag.
     *
     * Anything created without an explicit identity_id (factories, seeders,
     * the existing user-creation endpoints) therefore keeps working unchanged.
     */
    protected static function resolveIdentityFor(User $user): Identity
    {
        $existing = Identity::withTrashed()->where('email', $user->email)->first();

        if ($existing) {
            // A person who was soft-deleted everywhere and is being re-added.
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $identity = new Identity([
            'name'              => $user->name,
            'email'             => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'is_active'         => true,
        ]);

        // Copied from the already-cast attribute rather than assigned through
        // the fillable path: both models cast `password` as `hashed`, and the
        // value on $user has been hashed once already.
        $identity->password = $user->getAttributes()['password'] ?? Str::random(40);
        $identity->save();

        return $identity;
    }

    /**
     * The user's own preferences (theme, language, search target, ...).
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * Get the company that owns the user.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The person who owns this account.
     *
     * A user row is one company's account for that person; the same person may
     * hold other accounts elsewhere. Nothing in the app should traverse this
     * to reach another company's data — it exists for authentication and for
     * the company picker only.
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }

    /** The person's other accounts. Never used to widen a query's scope. */
    public function siblingAccounts()
    {
        return static::where('identity_id', $this->identity_id)
            ->where('id', '!=', $this->id);
    }

    /**
     * Get the teams that the user belongs to (as a member).
     */
    public function teams()
    {
        return $this->belongsToMany(Team::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the teams that the user manages (via manager_user_id).
     */
    public function managedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'manager_user_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_user')->withTimestamps();
    }

    public function getProfilePicUrlAttribute(): ?string
    {
        return $this->profile_pic ?? null;
    }

    // /**
    //  * Get the funnels created by the user.
    //  */
    // public function createdFunnels(): HasMany
    // {
    //     return $this->hasMany(Funnel::class, 'created_by');
    // }

    /**
     * Scope a query to only include active users.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include users from the same company.
     */
    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is manager.
     */
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * Check if user is agent.
     */
    public function isAgent(): bool
    {
        return $this->role === 'agent';
    }

    /**
     * Check if user can manage other users.
     */
    public function canManageUsers(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    /**
     * Check if user can manage company settings.
     */
    public function canManageCompany(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Update last login timestamp.
     */
    public function updateLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    /**
     * Return the IDs of users whose data this user is allowed to see.
     * Returns null for admin (no filter — sees all company data).
     * Manager: self + all users in teams they manage (via manager_user_id).
     * Agent: only themselves.
     */
    public function visibleUserIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        if ($this->isManager()) {
            return Team::where('manager_user_id', $this->id)
                ->with('users:id')
                ->get()
                ->flatMap(fn($team) => $team->users->pluck('id'))
                ->push($this->id)
                ->unique()
                ->values()
                ->toArray();
        }

        return [$this->id];
    }

    /**
     * Uniqueness rule for an account's email address.
     *
     * Scoped to the company, not global: the same person may hold an account
     * in several companies, and a global unique is precisely what used to
     * forbid that. Within one company an address still identifies one account.
     *
     * Passing a null $companyId keeps the old global behaviour, which is what
     * a caller that genuinely cannot know the company should get.
     */
    public static function emailUniqueRule(?int $companyId, ?int $ignoreUserId = null): Unique
    {
        $rule = Rule::unique('users', 'email');

        if ($companyId !== null) {
            $rule->where(fn ($query) => $query->where('company_id', $companyId));
        }

        return $ignoreUserId ? $rule->ignore($ignoreUserId) : $rule;
    }

    /**
     * Validation rules for user creation.
     */
    public static function validationRules(?int $companyId = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', static::emailUniqueRule($companyId)],
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:admin,manager,agent',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
            'company_id' => 'required|exists:companies,id',
        ];
    }

    /**
     * End every session this account has open.
     *
     * Called when an account is deleted or disabled: a Sanctum token is bound
     * to this account and therefore to its company, so leaving one alive would
     * leave a live session inside a company the person no longer belongs to.
     * Sessions on the person's *other* accounts are untouched.
     */
    public function revokeSessions(): void
    {
        $this->tokens()->delete();
    }
}