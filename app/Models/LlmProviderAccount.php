<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A company's account with one LLM vendor (OpenAI, Gemini, Claude...).
 *
 * One table keyed by `provider`, the same shape signature vendors use — the
 * driver's capabilities live in config/llm.php, only the credentials live here.
 */
class LlmProviderAccount extends Model
{
    use HasCompanyTimezone;

    public const CONNECTION_NOT_CONFIGURED = 'not_configured';
    public const CONNECTION_CONNECTED = 'connected';
    public const CONNECTION_ERROR = 'error';

    protected $table = 'llm_provider_accounts';

    protected $fillable = [
        'company_id',
        'provider',
        'name',
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
        static::creating(function (self $account) {
            if (empty($account->connection_status)) {
                $account->connection_status = self::CONNECTION_NOT_CONFIGURED;
            }

            if (empty($account->name)) {
                $account->name = config("llm.providers.{$account->provider}.label", $account->provider);
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

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Validate credentials against the field list the driver declares, so a
     * vendor that needs a different set doesn't need a rule change here.
     */
    public static function validateConfig(string $provider, array $data): array
    {
        $rules = [];

        foreach ((array) config("llm.providers.{$provider}.credential_fields", []) as $field) {
            $rules[$field['key']] = [
                ($field['required'] ?? false) ? 'required' : 'nullable',
                'string',
                'max:512',
            ];
        }

        $validator = Validator::make($data, $rules);

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
