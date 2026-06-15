<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AllowedOrigin extends Model
{
    use HasCompanyTimezone, HasFactory;

    protected $fillable = [
        'company_id',
        'url',
    ];

    /**
     * Validation rules for creating an allowed origin.
     */
    public static function validationRules(): array
    {
        return [
            'url' => 'required|string|max:255|url',
        ];
    }

    /**
     * Validation rules for updating an allowed origin.
     */
    public static function updateValidationRules(): array
    {
        return [
            'url' => 'required|string|max:255|url',
        ];
    }

    /**
     * Scope a query to only include origins from the same company.
     */
    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Checks if an origin with the same URL already exists for the company.
     */
    public static function existsForCompany(int $companyId, string $url): bool
    {
        return self::fromCompany($companyId)
            ->whereRaw('LOWER(url) = ?', [strtolower($url)])
            ->exists();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function apiTokens(): BelongsToMany
    {
        return $this->belongsToMany(ApiToken::class, 'api_token_allowed_origins');
    }
}
