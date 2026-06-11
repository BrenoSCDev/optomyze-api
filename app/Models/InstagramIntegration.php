<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InstagramIntegration extends Model
{
    use HasCompanyTimezone;

    public const CONNECTION_NOT_CONFIGURED = 'not_configured';
    public const CONNECTION_CONNECTED = 'connected';
    public const CONNECTION_ERROR = 'error';

    public const WEBHOOK_PENDING = 'pending';
    public const WEBHOOK_VERIFIED = 'verified';

    protected $fillable = [
        'name',
        'company_id',
        'access_token',
        'instagram_user_id',
        'username',
        'display_name',
        'profile_picture_url',
        'connection_status',
        'webhook_verify_token',
        'webhook_callback_id',
        'webhook_status',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $integration) {
            if (empty($integration->webhook_callback_id)) {
                $integration->webhook_callback_id = (string) Str::uuid();
            }

            if (empty($integration->webhook_verify_token)) {
                $integration->webhook_verify_token = Str::random(32);
            }

            if (empty($integration->connection_status)) {
                $integration->connection_status = self::CONNECTION_NOT_CONFIGURED;
            }

            if (empty($integration->webhook_status)) {
                $integration->webhook_status = self::WEBHOOK_PENDING;
            }
        });
    }

    /**
     * Get the company that owns this integration
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope to get only active integrations
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to find the Instagram integration from the same company.
     */
    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Validate configuration input
     */
    public static function validateConfig(array $data)
    {
        $validator = Validator::make($data, [
            'access_token' => ['required', 'string', 'max:1024'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
