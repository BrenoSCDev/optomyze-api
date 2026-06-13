<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use HasCompanyTimezone, HasFactory;

    protected $fillable = ['company_id', 'name', 'token', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
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
     * Generate a unique API token and save it
     */
    public static function generateToken($companyId, $name, $expiresAt = null)
    {
        $token = hash_hmac('sha256', Str::random(40), config('app.key'));

        return self::create([
            'company_id' => $companyId,
            'name' => $name,
            'token' => $token,
            'expires_at' => $expiresAt,
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
