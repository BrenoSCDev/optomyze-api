<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person, independent of any company.
 *
 * Credentials live here; everything else lives on the per-company `User`
 * accounts this identity owns. Nothing in the app is scoped to an identity —
 * it exists only to answer "who is signing in, and which accounts may they
 * choose from". The moment a company is chosen, the request is acting as a
 * `User`, which carries exactly one `company_id`, and every existing
 * company-scoped query stays correct as written.
 *
 * Deliberately NOT the Sanctum token holder: tokens belong to a `User`, so a
 * token can only ever see one company.
 */
class Identity extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $table = 'identities';

    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'last_login_at',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'last_login_at'     => 'datetime',
        'is_active'         => 'boolean',
    ];

    /** Every company account this person holds. */
    public function accounts(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The accounts this person may actually sign in to: active account, at an
     * active company. Used by both the company picker and the token exchange,
     * so the list the user sees and the list the server will honour cannot
     * drift apart.
     */
    public function selectableAccounts()
    {
        return $this->accounts()
            ->where('users.is_active', true)
            ->whereHas('company', fn (Builder $q) => $q->where('is_active', true))
            ->with('company:id,name,logo_path,is_active')
            ->orderBy('company_id');
    }

    /** Resolves one of this person's accounts by company, or null. */
    public function accountForCompany(int|string $companyId): ?User
    {
        return $this->selectableAccounts()
            ->where('users.company_id', $companyId)
            ->first();
    }

    public function updateLastLogin(): void
    {
        $this->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
