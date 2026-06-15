<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use HasCompanyTimezone, HasFactory;

    protected $fillable = ['company_id', 'name', 'token', 'expires_at', 'rate_limit_per_minute'];

    protected $casts = [
        'expires_at' => 'datetime',
        'rate_limit_per_minute' => 'integer',
    ];

    // protected $hidden = [
    //     'token', // Hide sensitive data from JSON responses
    // ];

    /**
     * Relationship: ApiToken belongs to a Company
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Origins (domains) this token is allowed to be used from.
     * An empty set means no origin restriction is enforced.
     */
    public function allowedOrigins(): BelongsToMany
    {
        return $this->belongsToMany(AllowedOrigin::class, 'api_token_allowed_origins');
    }

    /**
     * Generate a unique API token and save it
     */
    public static function generateToken($companyId, $name, $expiresAt = null, $rateLimitPerMinute = null)
    {
        $token = hash_hmac('sha256', Str::random(40), config('app.key'));

        if ($rateLimitPerMinute === null) {
            $company = Company::find($companyId);
            $rateLimitPerMinute = Company::planLimits($company->subscription_plan)['api_token_rate_limit_per_minute'] ?? null;
        }

        return self::create([
            'company_id' => $companyId,
            'name' => $name,
            'token' => $token,
            'expires_at' => $expiresAt,
            'rate_limit_per_minute' => $rateLimitPerMinute,
        ]);
    }

    /**
     * Revoke token by its value
     */
    public static function revokeToken($token)
    {
        return self::where('token', $token)->delete();
    }

    /**
     * Whether this token has an expiration date that has passed.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
