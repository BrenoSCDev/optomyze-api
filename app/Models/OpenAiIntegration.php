<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OpenAiIntegration extends Model
{
    use HasCompanyTimezone;

    public const CONNECTION_NOT_CONFIGURED = 'not_configured';
    public const CONNECTION_CONNECTED = 'connected';
    public const CONNECTION_ERROR = 'error';

    protected $table = 'openai_integrations';

    protected $fillable = [
        'name',
        'company_id',
        'api_key',
        'organization_id',
        'connection_status',
        'last_tested_at',
        'is_active',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        // Encrypted at rest: LLM keys grant paid API access, so unlike the
        // plaintext channel tokens elsewhere this uses APP_KEY encryption.
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $integration) {
            if (empty($integration->connection_status)) {
                $integration->connection_status = self::CONNECTION_NOT_CONFIGURED;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public static function validateConfig(array $data)
    {
        $validator = Validator::make($data, [
            'api_key' => ['required', 'string', 'max:512'],
            'organization_id' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    public function isUsable(): bool
    {
        return $this->is_active
            && $this->connection_status === self::CONNECTION_CONNECTED
            && !empty($this->api_key);
    }

    /**
     * Masked hint of the stored key for display (e.g. "sk-...abcd").
     */
    public function apiKeyHint(): ?string
    {
        if (empty($this->api_key)) {
            return null;
        }

        $key = $this->api_key;

        return substr($key, 0, 3) . '...' . substr($key, -4);
    }
}
