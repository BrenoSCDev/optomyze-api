<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A company's link to an Evolution API server.
 *
 * We host that server ourselves now, so in practice every row carries the same
 * platform credentials and the customer never sees them. The per-company
 * columns stay because they are what makes moving one tenant onto a dedicated
 * or self-hosted Evolution server a data change rather than a code change —
 * every call site already reads base_url and api_key off this model.
 */
class WhatsAppEvoIntegration extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'name',
        'company_id',
        'base_url',
        'api_key',
        'is_active',
    ];

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
     * Scope a query to find n8n integration from the same company.
     */
    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * The managed server's credentials, as configured for the platform.
     */
    public static function platformCredentials(): array
    {
        return [
            'base_url' => config('services.evolution.url'),
            'api_key'  => config('services.evolution.key'),
        ];
    }

    /**
     * Whether the platform has an Evolution server to hand out at all.
     *
     * Without it nothing can be provisioned, and an unset base URL would only
     * surface as a confusing HTTP failure somewhere inside Evolution's client.
     */
    public static function platformServerConfigured(): bool
    {
        $credentials = static::platformCredentials();

        return !empty($credentials['base_url']) && !empty($credentials['api_key']);
    }

    /**
     * The company's integration, created on demand.
     *
     * Installing WhatsApp from the marketplace creates this row, but connecting
     * an account must not depend on that having happened first: the old flow
     * put a credentials form in between, and there is nothing left to fill in.
     */
    public static function forCompany(int $companyId): self
    {
        $integration = static::fromCompany($companyId)->first();

        if ($integration) {
            return $integration;
        }

        return static::create(array_merge(static::platformCredentials(), [
            'name'       => 'wpp_evo_' . Str::random(8),
            'company_id' => $companyId,
            'is_active'  => true,
        ]));
    }

    /**
     * Fall back to the managed server when the row names no server of its own.
     *
     * Integrations created before we hosted Evolution have null columns, and
     * rotating the platform key would otherwise leave every stored copy stale.
     * Resolving through config covers both, while a row that *does* carry its
     * own server still wins — which is the custom-server path, intact.
     */
    public function getBaseUrlAttribute(?string $value): ?string
    {
        return $value ?: config('services.evolution.url');
    }

    public function getApiKeyAttribute(?string $value): ?string
    {
        return $value ?: config('services.evolution.key');
    }

    /**
     * Validate configuration input
     */
    public static function validateConfig(array $data)
    {
        $validator = Validator::make($data, [
            'base_url' => ['nullable', 'string', 'max:255'],
            'api_key'     => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
