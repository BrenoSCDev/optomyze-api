<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A company's account with one signature vendor.
 *
 * The table is `signature_providers`; the class carries "Account" because
 * that is what a row is — the tenant's credentials and webhook state for a
 * vendor, not the vendor driver itself. The driver is the class resolved from
 * config/signatures.php by SignatureProviderRegistry.
 */
class SignatureProviderAccount extends Model
{
    use HasCompanyTimezone;

    protected $table = 'signature_providers';

    public const CONNECTION_NOT_CONFIGURED = 'not_configured';
    public const CONNECTION_CONNECTED      = 'connected';
    public const CONNECTION_ERROR          = 'error';

    public const WEBHOOK_PENDING      = 'pending';
    public const WEBHOOK_REGISTERED   = 'registered';
    public const WEBHOOK_UNSUPPORTED  = 'unsupported';
    public const WEBHOOK_ERROR        = 'error';

    public const ENVIRONMENT_SANDBOX    = 'sandbox';
    public const ENVIRONMENT_PRODUCTION = 'production';

    protected $fillable = [
        'company_id',
        'provider',
        'name',
        'environment',
        'credentials',
        'webhook_external_id',
        'webhook_secret',
        'webhook_status',
        'connection_status',
        'last_tested_at',
        'last_error',
        'is_active',
    ];

    /**
     * Credentials and the webhook secret never leave the server. Every read
     * path goes through toPublicArray() instead.
     */
    protected $hidden = [
        'credentials',
        'webhook_secret',
        'webhook_callback_id',
    ];

    protected $casts = [
        // Encrypted at rest — these grant the ability to send legally binding
        // contracts on the customer's account, and are billed to them.
        'credentials'    => 'encrypted:array',
        'webhook_secret' => 'encrypted',
        'is_active'      => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $account) {
            if (empty($account->webhook_callback_id)) {
                $account->webhook_callback_id = (string) Str::uuid();
            }

            if (empty($account->connection_status)) {
                $account->connection_status = self::CONNECTION_NOT_CONFIGURED;
            }

            if (empty($account->webhook_status)) {
                $account->webhook_status = self::WEBHOOK_PENDING;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(SignatureRequest::class, 'signature_provider_id');
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

    /** One credential value, without exposing the whole encrypted blob. */
    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials ?? [], $key, $default);
    }

    public function hasCredentials(): bool
    {
        return !empty(array_filter($this->credentials ?? []));
    }

    /** Ready to send with: enabled, credentialed, and known to work. */
    public function isUsable(): bool
    {
        return $this->is_active
            && $this->hasCredentials()
            && $this->connection_status === self::CONNECTION_CONNECTED;
    }

    /** The vendor base URL for this account's environment. */
    public function baseUrl(): ?string
    {
        return config("signatures.providers.{$this->provider}.environments.{$this->environment}");
    }

    /** The URL this account's vendor should deliver webhooks to. */
    public function callbackUrl(): string
    {
        $base = rtrim((string) config('signatures.callback_base_url'), '/');

        return "{$base}/api/signatures/webhook/{$this->provider}/{$this->webhook_callback_id}";
    }

    public function markConnected(?array $credentials = null): void
    {
        $this->forceFill(array_filter([
            'credentials'       => $credentials,
            'connection_status' => self::CONNECTION_CONNECTED,
            'last_tested_at'    => now(),
            'last_error'        => null,
        ], fn ($value) => $value !== null))->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'connection_status' => self::CONNECTION_ERROR,
            'last_tested_at'    => now(),
            'last_error'        => Str::limit($error, 1000),
        ])->save();
    }

    /**
     * The safe projection for API responses: never the credentials, only
     * whether they exist.
     */
    public function toPublicArray(): array
    {
        return [
            'id'                => $this->id,
            'provider'          => $this->provider,
            'name'              => $this->name,
            'environment'       => $this->environment,
            'has_credentials'   => $this->hasCredentials(),
            'connection_status' => $this->connection_status,
            'webhook_status'    => $this->webhook_status,
            'webhook_url'       => $this->callbackUrl(),
            'last_tested_at'    => $this->last_tested_at,
            'last_error'        => $this->last_error,
            'is_active'         => $this->is_active,
            'is_usable'         => $this->isUsable(),
        ];
    }
}
